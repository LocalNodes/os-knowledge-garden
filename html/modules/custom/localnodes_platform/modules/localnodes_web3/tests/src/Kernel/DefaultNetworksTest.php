<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\safe_smart_accounts\Entity\SafeNetwork;
use Drupal\safe_smart_accounts\Entity\SafeNetworkInterface;
use Drupal\safe_smart_accounts\Network\NetworkRegistry;
use Drupal\safe_smart_accounts\Value\Address;
use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * The default network registry entries LocalNodes ships (D-05, D-06).
 *
 * The localnodes_web3 module itself is not enabled: it depends on
 * group_treasury and social_group_treasury, which pull in Open Social's
 * social_group, so the Kernel harness can't boot it. Instead each network
 * YAML in its config/install is parsed and saved with
 * SafeNetwork::create()->save(), so the same preSave() validation a real
 * install runs applies.
 *
 * @group localnodes_web3
 */
final class DefaultNetworksTest extends SafeKernelTestBase {

  /**
   * Chain ids of real-money networks no default entry may use (P8).
   */
  private const MAINNET_CHAIN_IDS = [1, 10, 56, 100, 137, 8453, 42161, 43114];

  /**
   * The registry entries this module ships, by id, in load order.
   */
  private const EXPECTED = [
    'ethereum_sepolia' => [
      'chain_id' => 11155111,
      'chain_id_hex' => '0xaa36a7',
      'host' => 'eth-sepolia-testnet.api.pocket.network',
    ],
    'base_sepolia' => [
      'chain_id' => 84532,
      'chain_id_hex' => '0x14a34',
      'host' => 'base-sepolia-testnet.api.pocket.network',
    ],
  ];

  /**
   * The network YAML files found in localnodes_web3's config/install.
   *
   * @var string[]
   */
  private array $files = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->files = glob(dirname(__DIR__, 3) . '/config/install/safe_smart_accounts.network.*.yml') ?: [];
    foreach ($this->files as $file) {
      SafeNetwork::create(Yaml::parseFile($file))->save();
    }
  }

  /**
   * Exactly two entries ship, and they load in weight order.
   */
  public function testShipsExactlyTwoTestnetEntries(): void {
    $this->assertCount(2, $this->files, 'localnodes_web3 config/install holds exactly two network entries.');
    $this->assertSame(array_keys(self::EXPECTED), array_keys($this->registry()->loadAll()));
  }

  /**
   * Chain ids, test-network flag and native currency of each entry.
   */
  public function testChainIdsTestnetAndNativeCurrency(): void {
    foreach (self::EXPECTED as $id => $expected) {
      $network = $this->network($id);
      $this->assertSame($expected['chain_id'], $network->getChainId(), "$id chain id");
      $this->assertTrue($network->isTestnet(), "$id is a test network");
      $this->assertSame(['name' => 'Ether', 'symbol' => 'ETH', 'decimals' => 18], $network->getNativeCurrency(), "$id native currency");
    }
  }

  /**
   * Each entry has one USDC token and no entry is a real-money network.
   */
  public function testOneUsdcTokenAndNoMainnet(): void {
    $this->assertNotEmpty($this->registry()->loadAll());
    foreach ($this->registry()->loadAll() as $id => $network) {
      $tokens = $network->getTokens();
      $this->assertCount(1, $tokens, "$id has one token");
      $this->assertSame('USDC', $tokens[0]['label'], "$id token label");
      $this->assertNotContains($network->getChainId(), self::MAINNET_CHAIN_IDS, "$id is not a real-money network");
      $this->assertTrue($network->isTestnet(), "$id is a test network");
    }
  }

  /**
   * The proxy factory slot holds a checksummed address; the rest are empty.
   */
  public function testProxyFactorySlotFilledOthersEmpty(): void {
    foreach (array_keys(self::EXPECTED) as $id) {
      $contracts = $this->network($id)->getContracts();
      $factory = $contracts['safe_proxy_factory'] ?? '';
      $this->assertTrue(Address::isValidFormat($factory), "$id safe_proxy_factory is an address");
      $this->assertTrue(Address::hasValidChecksum($factory), "$id safe_proxy_factory has a valid checksum");
      unset($contracts['safe_proxy_factory']);
      $this->assertCount(8, $contracts, "$id has the other eight slots");
      $this->assertSame(array_fill_keys(array_keys($contracts), ''), $contracts, "$id other slots are empty");
    }
  }

  /**
   * Each entry names Safe 1.4.1, so its factory passed the canonical check.
   *
   * The setUp() method saved each YAML through preSave(), which refuses a
   * factory that isn't canonical for the entry's safe_version (D-33).
   */
  public function testEntriesNameSafeVersion141(): void {
    foreach (array_keys(self::EXPECTED) as $id) {
      $network = $this->network($id);
      $this->assertSame('1.4.1', $network->getSafeVersion(), "$id safe_version");
      $this->assertSame([], SafeNetwork::validationErrors([
        'id' => $id,
        'label' => $network->label(),
        'chain_id' => $network->getChainId(),
        'rpc_urls' => $network->getRpcUrls(),
        'explorer' => $network->getExplorer(),
        'native_currency' => $network->getNativeCurrency(),
        'tokens' => $network->getTokens(),
        'contracts' => $network->getContracts(),
        'safe_version' => $network->getSafeVersion(),
      ], $id), "$id validates");
    }
  }

  /**
   * A read on each entry goes to that entry's own RPC host.
   */
  public function testReadsRouteToOwnNetwork(): void {
    foreach (self::EXPECTED as $id => $expected) {
      $network = $this->network($id);
      $before = count($this->rpcHistory);
      $this->queueRpc(['jsonrpc' => '2.0', 'id' => 1, 'result' => $expected['chain_id_hex']]);

      $result = $this->container->get('safe_smart_accounts.eth_rpc_client')
        ->call($network, 'eth_chainId', []);

      $this->assertSame($expected['chain_id_hex'], $result, "$id chain id answer");
      $this->assertCount($before + 1, $this->rpcHistory, "$id sent one request");
      $this->assertSame($expected['host'], $this->rpcHistory[$before]['request']->getUri()->getHost(), "$id request host");
    }
  }

  /**
   * The install hook writes no Safe network data (D-05).
   *
   * The safe_smart_accounts.settings config still ships its legacy network
   * mapping; the hook must leave that config exactly as installed. Registry
   * entries come from config/install only.
   */
  public function testInstallHookWritesNoSafeNetworkData(): void {
    $before = $this->config('safe_smart_accounts.settings')->getRawData();

    $this->runInstallHook();

    $this->assertSame($before, $this->config('safe_smart_accounts.settings')->getRawData());
  }

  /**
   * The siwe_login RPC default is set when empty or the placeholder.
   *
   * @dataProvider unsetSiweProviderProvider
   */
  public function testInstallHookSetsSiweRpcDefaultWhenUnset(string $current): void {
    $this->config('siwe_login.settings')->set('ethereum_provider_url', $current)->save();

    $this->runInstallHook();

    $this->assertSame('https://eth.api.pocket.network', $this->config('siwe_login.settings')->get('ethereum_provider_url'));
  }

  /**
   * Unset siwe_login provider values the hook replaces.
   */
  public static function unsetSiweProviderProvider(): array {
    return [
      'empty' => [''],
      'placeholder' => ['https://eth-mainnet.g.alchemy.com/v2/YOUR_ALCHEMY_KEY'],
    ];
  }

  /**
   * An operator's siwe_login RPC value is left alone.
   */
  public function testInstallHookKeepsOperatorSiweRpc(): void {
    $this->config('siwe_login.settings')->set('ethereum_provider_url', 'https://rpc.operator.invalid/key')->save();

    $this->runInstallHook();

    $this->assertSame('https://rpc.operator.invalid/key', $this->config('siwe_login.settings')->get('ethereum_provider_url'));
  }

  /**
   * Loads localnodes_web3.install and runs its hook_install().
   *
   * The module is not enabled (see the class docblock), so the file is
   * loaded directly. siwe_login.settings exists with its schema already:
   * the base class runs installConfig(['siwe_login']).
   */
  private function runInstallHook(): void {
    require_once dirname(__DIR__, 3) . '/localnodes_web3.install';
    localnodes_web3_install();
  }

  /**
   * Returns the registry service.
   */
  private function registry(): NetworkRegistry {
    return $this->container->get('safe_smart_accounts.network_registry');
  }

  /**
   * Loads a shipped entry, failing the test (not erroring) when absent.
   */
  private function network(string $id): SafeNetworkInterface {
    $network = $this->registry()->load($id);
    $this->assertInstanceOf(SafeNetworkInterface::class, $network, "network $id is installed");
    return $network;
  }

}
