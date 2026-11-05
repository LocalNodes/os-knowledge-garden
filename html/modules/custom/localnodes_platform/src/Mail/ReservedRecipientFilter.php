<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Mail;

use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Exception\SkipMailException;
use Psr\Log\LoggerInterface;

/**
 * Keeps mail for reserved test domains away from the mail transport.
 *
 * Demo content creates users at reserved domains (for example
 * @localnodes.example). Open Social then sends them activity notifications,
 * which the SMTP provider either refuses (550) or accepts and bounces. That
 * burns the sending quota and a high bounce rate can get the account
 * suspended. Reserved names can never belong to a real member, so dropping
 * them is safe on every instance.
 *
 * Reserved names (RFC 2606, RFC 6761):
 * - top-level domains .example, .test, .invalid, .localhost;
 * - second-level domains example.com, example.net, example.org and their
 *   subdomains.
 */
final class ReservedRecipientFilter {

  /**
   * Reserved top-level domains (RFC 2606 section 2, RFC 6761 section 6).
   */
  private const RESERVED_TLDS = ['example', 'test', 'invalid', 'localhost'];

  /**
   * Reserved second-level domains (RFC 2606 section 3).
   */
  private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

  /**
   * Whether an address belongs to a reserved test domain.
   */
  public static function isReserved(string $address): bool {
    $address = trim($address);
    $at = strrpos($address, '@');
    if ($at === FALSE) {
      return FALSE;
    }
    $domain = rtrim(strtolower(substr($address, $at + 1)), '.');
    if ($domain === '') {
      return FALSE;
    }
    foreach (self::RESERVED_DOMAINS as $reserved) {
      if ($domain === $reserved || str_ends_with($domain, '.' . $reserved)) {
        return TRUE;
      }
    }
    $labels = explode('.', $domain);
    return in_array(end($labels), self::RESERVED_TLDS, TRUE);
  }

  /**
   * Drops reserved recipients from an email, or cancels it.
   *
   * Runs in symfony_mailer's post-render phase, when the recipient list is
   * final. If no recipient is left in To, Cc or Bcc the send is cancelled with
   * SkipMailException, which symfony_mailer treats as a deliberate skip (no
   * error, no transport call).
   *
   * @throws \Drupal\symfony_mailer\Exception\SkipMailException
   */
  public static function apply(EmailInterface $email, LoggerInterface $logger): void {
    $dropped = [];
    $kept = [];
    foreach (['to' => $email->getTo(), 'cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $header => $addresses) {
      $kept[$header] = [];
      foreach ($addresses as $address) {
        if (self::isReserved((string) $address->getEmail())) {
          $dropped[] = $address->getEmail();
        }
        else {
          $kept[$header][] = $address;
        }
      }
    }
    if (!$dropped) {
      return;
    }

    $context = [
      '@type' => $email->getType() . '.' . $email->getSubType(),
      '@addresses' => implode(', ', $dropped),
    ];

    if (!$kept['to'] && !$kept['cc'] && !$kept['bcc']) {
      $logger->notice('Dropped @type mail: every recipient is at a reserved test domain (@addresses).', $context);
      throw new SkipMailException('All recipients are at reserved test domains');
    }

    // Some real recipients remain (in To, Cc or Bcc): send to them only. A
    // message with only Cc or Bcc recipients is still valid. 'to' is normally
    // locked after the build phase; the third argument is symfony_mailer's late-set
    // path for legacy emails (BaseEmailTrait::setAddress()), which is what we
    // need here because the list is only final after rendering.
    $email->setAddress('to', $kept['to'], TRUE);
    $email->setAddress('cc', $kept['cc']);
    $email->setAddress('bcc', $kept['bcc']);
    $logger->notice('Removed reserved test-domain recipients from @type mail (@addresses).', $context);
  }

}
