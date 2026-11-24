<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Sponsor;

use Drupal\gas_sponsor\GasSponsorInterface;
use Drupal\gas_sponsor\StatusMemory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the network fee account's status on cron and stores a good read.
 *
 * A group payment page decides who pays the network fee from gas_sponsor's
 * last stored status read (safe_smart_accounts PaymentView::coverage()); it
 * never asks the sponsor itself, because that read can be slow. Only the
 * Network Fee Account page stored a read, so on a fresh site every payment
 * ready to send said "Couldn't check the network fee account right now. Try
 * again." and offered no Send until a site manager opened that page. Cron
 * now does the same read and stores it the same way, through StatusMemory.
 *
 * Every good read is stored, a zero balance included, as the Network Fee
 * Account page and the readiness check do (a practice fee account is funded
 * after hand-over). A read that fails stores nothing and logs nothing, so
 * the last good read stays, and a site that never had one keeps failing
 * closed: the page says it couldn't check and offers no Send.
 */
final class FeeStatusRefresh {

  /**
   * Constructs a FeeStatusRefresh.
   *
   * @param \Closure(): (\Drupal\gas_sponsor\GasSponsorInterface|null) $sponsor
   *   Returns the sponsor, or NULL when gas_sponsor is off. A closure, so the
   *   service is only built when it is there.
   * @param \Drupal\gas_sponsor\StatusMemory|null $memory
   *   Where the last good read is kept; NULL when gas_sponsor is off.
   */
  public function __construct(
    private readonly \Closure $sponsor,
    private readonly ?StatusMemory $memory,
  ) {}

  /**
   * Builds it from the container, without failing when gas_sponsor is off.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      static fn (): ?object => $container->has('gas_sponsor.sponsor') ? $container->get('gas_sponsor.sponsor') : NULL,
      $container->has('gas_sponsor.status_memory') ? $container->get('gas_sponsor.status_memory') : NULL,
    );
  }

  /**
   * Reads the status once and stores it when the read is good.
   *
   * @return bool
   *   TRUE when a read was stored.
   */
  public function run(): bool {
    if ($this->memory === NULL) {
      return FALSE;
    }
    try {
      $sponsor = ($this->sponsor)();
      if (!$sponsor instanceof GasSponsorInterface) {
        return FALSE;
      }
      $status = $sponsor->status();
    }
    catch (\Throwable) {
      // Not set up, unreachable, or a body that can't be read: nothing is
      // stored. The message isn't kept, so nothing it might hold is logged.
      return FALSE;
    }
    $this->memory->remember($status);
    return TRUE;
  }

}
