<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DestructableInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the sign-in links asked for in this request, after the response.
 *
 * The request page queues the typed address and answers at once. Looking up
 * the account and sending its mail wait until the response has gone out
 * (destruct() runs at kernel terminate), so a known address and an unknown
 * one take the same time to answer.
 *
 * Core's per-account flood limit for password requests applies, under an
 * event of its own (user.flood user_limit, user_window), checked and taken
 * in one step (FloodAllowance). Past it nothing more is sent.
 */
final class EmailLinkMailer implements DestructableInterface {

  private const FLOOD_USER = 'localnodes_web3.email_link_user';

  /**
   * Addresses asked for in this request.
   *
   * @var string[]
   */
  private array $queue = [];

  public function __construct(
    private readonly FloodAllowance $allowance,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Queues a link for whichever account uses this address, if any.
   */
  public function queue(string $email): void {
    $this->queue[] = $email;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $queue = $this->queue;
    $this->queue = [];
    foreach ($queue as $email) {
      $this->send($email);
    }
  }

  /**
   * Mails a link to the account that uses the address, within the limit.
   */
  private function send(string $email): void {
    $account = EmailLink::accountFor($email);
    if ($account === NULL) {
      return;
    }
    $config = $this->configFactory->get('user.flood');
    $id = (string) $account->id();
    $limit = [
      self::FLOOD_USER,
      (int) $config->get('user_limit'),
      (int) $config->get('user_window'),
      $id,
    ];
    if ($this->allowance->reserve([$limit]) !== NULL) {
      return;
    }
    $message = $this->mailManager->mail('localnodes_web3', 'email_link', (string) $account->getEmail(), $account->getPreferredLangcode(), [
      'account' => $account,
      'url' => EmailLink::url($account, $this->time->getRequestTime()),
    ]);
    if (empty($message['result'])) {
      $this->logger->error('Sign-in link mail failed for uid @uid.', ['@uid' => $id]);
      return;
    }
    $this->logger->info('Sign-in link mailed to uid @uid.', ['@uid' => $id]);
  }

}
