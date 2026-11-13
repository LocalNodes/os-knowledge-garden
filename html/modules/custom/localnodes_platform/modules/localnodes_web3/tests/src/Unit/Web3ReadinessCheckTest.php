<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\gas_sponsor\GasSponsorInterface;
use Drupal\gas_sponsor\Value\SponsorStatus;
use Drupal\gas_sponsor_fixture\FixtureSponsor;
use Drupal\localnodes_web3\Readiness\Web3ReadinessCheck;
use Drupal\Tests\UnitTestCase;

/**
 * The practice-site readiness check run before a site reports "ready".
 *
 * A unit test, not a kernel test: the Kernel harness can't boot
 * localnodes_web3 (see DefaultNetworksTest). The sponsor is the real
 * gas_sponsor_fixture backend, driven by its scenarios, so the statuses are
 * the ones SponsorStatus::fromRelayer() builds from a relayer body.
 *
 * @group localnodes_web3
 * @coversDefaultClass \Drupal\localnodes_web3\Readiness\Web3ReadinessCheck
 */
final class Web3ReadinessCheckTest extends UnitTestCase {

  /**
   * The chain id in the shipped practice network entry.
   */
  private const CHAIN_ID = 11155111;

  /**
   * A configured fixture relayer, every module on, practice network on: ok.
   */
  public function testConfiguredPasses(): void {
    $result = $this->check(sponsor: $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED))->run();
    $this->assertSame(['code' => Web3ReadinessCheck::OK, 'address' => FixtureSponsor::ADDRESS], $result);
  }

  /**
   * Each fixture failure gives its fixed code and no address.
   */
  public function testSponsorFailures(): void {
    $this->assertSame(
      ['code' => Web3ReadinessCheck::SPONSOR_NOT_SET_UP, 'address' => NULL],
      $this->check(sponsor: $this->fixture(FixtureSponsor::SCENARIO_NOT_SET_UP))->run(),
    );
    $this->assertSame(
      ['code' => Web3ReadinessCheck::SPONSOR_UNREACHABLE, 'address' => NULL],
      $this->check(sponsor: $this->fixture(FixtureSponsor::SCENARIO_UNREACHABLE))->run(),
    );
  }

  /**
   * The relayer says "configured" but can't read the practice chain: it fails.
   */
  public function testUnreadableChainFails(): void {
    $body = $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED)->status()->toArray();
    $body['chains'][(string) self::CHAIN_ID] = ['error' => 'rpc_unreachable'];
    $this->assertSame(
      ['code' => Web3ReadinessCheck::BALANCE_UNREADABLE, 'address' => NULL],
      $this->check(sponsor: $this->stub(SponsorStatus::fromRelayer($body)))->run(),
    );

    // A relayer that does not serve that chain at all fails the same way.
    unset($body['chains'][(string) self::CHAIN_ID]);
    $this->assertSame(
      Web3ReadinessCheck::BALANCE_UNREADABLE,
      $this->check(sponsor: $this->stub(SponsorStatus::fromRelayer($body)))->run()['code'],
    );
  }

  /**
   * A zero balance passes: a practice relayer is funded after hand-over.
   */
  public function testZeroBalancePasses(): void {
    $body = $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED)->status()->toArray();
    $body['chains'][(string) self::CHAIN_ID] = ['balanceWei' => '0', 'pending' => 0];
    $this->assertSame(Web3ReadinessCheck::OK, $this->check(sponsor: $this->stub(SponsorStatus::fromRelayer($body)))->run()['code']);
  }

  /**
   * Any one of the eight modules off fails before the sponsor is asked.
   */
  public function testEachModuleOffFails(): void {
    foreach (Web3ReadinessCheck::MODULES as $off) {
      $asked = FALSE;
      $sponsor = function () use (&$asked) {
        $asked = TRUE;
        return $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED);
      };
      $result = $this->check(off: [$off], sponsor: $sponsor)->run();
      $this->assertSame(['code' => Web3ReadinessCheck::MODULES_OFF, 'address' => NULL], $result, $off);
      $this->assertFalse($asked, "$off off: the sponsor service is not built");
    }
    $this->assertCount(8, Web3ReadinessCheck::MODULES);
  }

  /**
   * The practice network off, or its entry missing, fails.
   */
  public function testNetworkOffFails(): void {
    foreach ([FALSE, NULL] as $status) {
      $this->assertSame(
        Web3ReadinessCheck::NETWORK_OFF,
        $this->check(network: $status, sponsor: $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED))->run()['code'],
      );
    }
  }

  /**
   * A network entry with no usable chain id fails before the sponsor is asked.
   */
  public function testNetworkWithoutChainIdFails(): void {
    foreach ([NULL, 0, -1, '11155111'] as $chain_id) {
      $asked = FALSE;
      $sponsor = function () use (&$asked) {
        $asked = TRUE;
        return $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED);
      };
      $result = $this->check(chainId: $chain_id, sponsor: $sponsor)->run();
      $this->assertSame(['code' => Web3ReadinessCheck::NETWORK_OFF, 'address' => NULL], $result, var_export($chain_id, TRUE));
      $this->assertFalse($asked, 'the sponsor service is not built');
    }
  }

  /**
   * The balance is read on the chain the network entry names, not another.
   */
  public function testBalanceIsReadOnTheEntrysChain(): void {
    $other = 84532;
    $body = $this->fixture(FixtureSponsor::SCENARIO_CONFIGURED)->status()->toArray();
    $body['chains'][(string) self::CHAIN_ID] = ['balanceWei' => '0', 'pending' => 0];
    unset($body['chains'][(string) $other]);
    $sponsor = $this->stub(SponsorStatus::fromRelayer($body));
    $this->assertSame(Web3ReadinessCheck::BALANCE_UNREADABLE, $this->check(chainId: $other, sponsor: $sponsor)->run()['code']);
    $this->assertSame(Web3ReadinessCheck::OK, $this->check(chainId: self::CHAIN_ID, sponsor: $sponsor)->run()['code']);
  }

  /**
   * A failure never carries an exception message out.
   */
  public function testNoMessageLeaks(): void {
    $secret = '0x' . str_repeat('ab', 32) . ' https://rpc.example/KEY-SENTINEL';
    $sponsor = $this->createMock(GasSponsorInterface::class);
    $sponsor->method('status')->willThrowException(new \RuntimeException($secret));
    $result = $this->check(sponsor: $sponsor)->run();
    $this->assertSame(['code' => Web3ReadinessCheck::SPONSOR_UNREACHABLE, 'address' => NULL], $result);
    $this->assertStringNotContainsString('SENTINEL', var_export($result, TRUE));
  }

  /**
   * Builds the check.
   *
   * @param string[] $off
   *   Modules reported off.
   * @param bool|null $network
   *   The network entry's status.
   * @param mixed $chainId
   *   The network entry's chain id.
   * @param \Drupal\gas_sponsor\GasSponsorInterface|\Closure $sponsor
   *   The sponsor, or a closure returning it.
   */
  private function check(array $off = [], ?bool $network = TRUE, mixed $chainId = self::CHAIN_ID, GasSponsorInterface|\Closure $sponsor = NULL): Web3ReadinessCheck {
    $modules = $this->createMock(ModuleHandlerInterface::class);
    $modules->method('moduleExists')->willReturnCallback(fn (string $m) => !in_array($m, $off, TRUE));
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(fn (string $key) => match ($key) {
      'status' => $network,
      'chain_id' => $chainId,
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturnCallback(fn (string $name) => $name === Web3ReadinessCheck::NETWORK_CONFIG ? $config : $this->createMock(ImmutableConfig::class));
    $closure = $sponsor instanceof \Closure ? $sponsor : static fn () => $sponsor;
    return new Web3ReadinessCheck($modules, $factory, $closure);
  }

  /**
   * The fixture relayer in a scenario.
   */
  private function fixture(string $scenario): FixtureSponsor {
    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(fn (string $key, $default = NULL) => $key === FixtureSponsor::SCENARIO_KEY ? $scenario : $default);
    return new FixtureSponsor($state);
  }

  /**
   * A sponsor answering one status.
   */
  private function stub(SponsorStatus $status): GasSponsorInterface {
    $sponsor = $this->createMock(GasSponsorInterface::class);
    $sponsor->method('status')->willReturn($status);
    return $sponsor;
  }

}
