<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Readiness;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\gas_sponsor\Exception\SponsorNotConfiguredException;
use Drupal\gas_sponsor\GasSponsorInterface;

/**
 * Whether a practice site's group funds and decisions really work.
 *
 * Run by the container entrypoint (through localnodes-web3:check) before a
 * sign-up site with WEB3_ENABLED=true reports "ready", so a half-on site is
 * never handed over. A site whose modules failed to enable still answers
 * normally, so "the site responds" proves nothing.
 *
 * Checks, in order, each with a fixed code:
 * - modules_off: one of the eight web3 modules is not enabled;
 * - network_off: the practice network entry is missing, off, or has no
 *   usable chain id;
 * - sponsor_not_set_up: the gas sponsor is not configured (no relayer key,
 *   no token, or web3 off in the relayer), or reports no address;
 * - sponsor_unreachable: the relayer could not be read;
 * - balance_unreadable: the relayer cannot read its balance on that network
 *   (it answers "configured" while the chain RPC is down). Zero is fine: a
 *   practice relayer is funded after hand-over.
 *
 * On success the result carries the relayer's public address, which the
 * sign-up app records so whoever funds the relayer knows where to send.
 * Nothing else leaves: never the key, the token or an RPC URL, and never an
 * exception message.
 */
final class Web3ReadinessCheck {

  /**
   * The modules a practice site needs for group funds and decisions.
   */
  public const MODULES = [
    'localnodes_web3',
    'siwe_login_waap',
    'safe_smart_accounts',
    'safe_governance',
    'safe_governance_group',
    'gas_sponsor',
    'group_treasury',
    'social_group_treasury',
  ];

  /**
   * The network entry practice sites use.
   *
   * Its chain id is read from this entry, so the chain is named in one place:
   * the network registry.
   */
  public const NETWORK_CONFIG = 'safe_smart_accounts.network.ethereum_sepolia';

  public const OK = 'ok';
  public const MODULES_OFF = 'modules_off';
  public const NETWORK_OFF = 'network_off';
  public const SPONSOR_NOT_SET_UP = 'sponsor_not_set_up';
  public const SPONSOR_UNREACHABLE = 'sponsor_unreachable';
  public const BALANCE_UNREADABLE = 'balance_unreadable';

  /**
   * Constructs a Web3ReadinessCheck.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Closure(): \Drupal\gas_sponsor\GasSponsorInterface $sponsor
   *   Returns the sponsor seam. A closure, so a site whose gas_sponsor module
   *   is off reports modules_off instead of failing to build the service.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly \Closure $sponsor,
  ) {}

  /**
   * Runs every check once.
   *
   * @return array{code: string, address: string|null}
   *   code is OK or the first failing check's fixed code; address is the
   *   relayer's address on OK, NULL otherwise.
   */
  public function run(): array {
    foreach (self::MODULES as $module) {
      if (!$this->moduleHandler->moduleExists($module)) {
        return self::fail(self::MODULES_OFF);
      }
    }

    $network = $this->configFactory->get(self::NETWORK_CONFIG);
    if ($network->get('status') !== TRUE) {
      return self::fail(self::NETWORK_OFF);
    }
    $chain_id = $network->get('chain_id');
    if (!is_int($chain_id) || $chain_id <= 0) {
      return self::fail(self::NETWORK_OFF);
    }

    try {
      $sponsor = ($this->sponsor)();
      if (!$sponsor instanceof GasSponsorInterface) {
        return self::fail(self::SPONSOR_NOT_SET_UP);
      }
      $status = $sponsor->status();
    }
    catch (SponsorNotConfiguredException) {
      return self::fail(self::SPONSOR_NOT_SET_UP);
    }
    catch (\Throwable) {
      // SponsorUnavailableException, a body the status can't read, or
      // anything else: the relayer could not be read. The message is not
      // kept, so nothing it might hold reaches the log.
      return self::fail(self::SPONSOR_UNREACHABLE);
    }

    if (preg_match('/^0x[0-9a-fA-F]{40}$/', $status->address) !== 1) {
      return self::fail(self::SPONSOR_NOT_SET_UP);
    }
    if ($status->balanceWei($chain_id) === NULL) {
      return self::fail(self::BALANCE_UNREADABLE);
    }

    return ['code' => self::OK, 'address' => $status->address];
  }

  /**
   * A failed result.
   *
   * @return array{code: string, address: null}
   *   The result.
   */
  private static function fail(string $code): array {
    return ['code' => $code, 'address' => NULL];
  }

}
