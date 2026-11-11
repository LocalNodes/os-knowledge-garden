<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\safe_governance\Network\GovernanceContracts;
use Drupal\safe_smart_accounts\Entity\SafeNetwork;
use Drupal\safe_smart_accounts\Entity\SafeNetworkInterface;
use Drupal\safe_smart_accounts\Network\NetworkRegistry;
use Drupal\safe_smart_accounts\Value\Address;
use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;
use Symfony\Component\Yaml\Yaml;

/**
 * The default network registry entries LocalNodes ships (D-05, D-06).
 *
 * Phase 25 (25-11, D-10) adds the governance addresses: the reality and
 * hats slots, and a third_party_settings.safe_governance block declared by
 * the safe_governance module's schema, which this test therefore enables.
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
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'siwe_login',
    'safe_smart_accounts',
    'safe_governance',
  ];

  /**
   * Governance contracts per entry.
   *
   * Compared without regard to letter case. The Reality template id is the
   * one the Wave 0 smoke test recorded on Ethereum Sepolia; Base Sepolia has no
   * template yet and no ENS.
   */
  private const GOVERNANCE = [
    'ethereum_sepolia' => [
      'reality' => '0xaf33DcB6E8c5c4D9dDF579f53031b514d19449CA',
      'hats' => '0x3bc1A0Ad72417f2d411118085256fC53CBdDd137',
      'multi_send' => '0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526',
      'multi_send_call_only' => '0x9641d764fc13c8B624c04430C7356C1C7C8102e2',
      'create_call' => '0x9b35Af71d77eaf8d7e40252370304687390A1A52',
      'zodiac_module_factory' => '0x000000000000aDdB49795b0f9bA5BC298cDda236',
      'zodiac_reality_mastercopy' => '0x4e35DA39Fa5893a70A40Ce964F993d891E607cC0',
      'ens_registrar' => '0xf633e7fc17e2bbe0d0965d18ec1821dcb754a3d3',
      'ens_payment_token' => '0x240b0316df57887dbbe58b586508b19e633a14aa',
      'ens_universal_helper' => '0xd453e5Bdb62CC3beA84341B1e306319C8Ffd7DFe',
      'snapshot_network_id' => '11155111',
      'reality_template_id' => '130',
      'name_network' => 'ethereum_sepolia',
    ],
    'base_sepolia' => [
      'reality' => '0x86b653Dc9aa6d7311109276925927087573f91CB',
      'hats' => '0x3bc1A0Ad72417f2d411118085256fC53CBdDd137',
      'multi_send' => '0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526',
      'multi_send_call_only' => '0x9641d764fc13c8B624c04430C7356C1C7C8102e2',
      'create_call' => '0x9b35Af71d77eaf8d7e40252370304687390A1A52',
      'zodiac_module_factory' => '0x000000000000aDdB49795b0f9bA5BC298cDda236',
      'zodiac_reality_mastercopy' => '0x4e35DA39Fa5893a70A40Ce964F993d891E607cC0',
      'snapshot_network_id' => '84532',
      'reality_template_id' => '',
      'name_network' => 'ethereum_sepolia',
    ],
  ];

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
   * The factory, reality and hats slots are filled; the other six are empty.
   */
  public function testContractSlots(): void {
    foreach (array_keys(self::EXPECTED) as $id) {
      $contracts = $this->network($id)->getContracts();
      foreach (['safe_proxy_factory', 'reality', 'hats'] as $slot) {
        $address = $contracts[$slot] ?? '';
        $this->assertTrue(Address::isValidFormat($address), "$id $slot is an address");
        $this->assertTrue(Address::hasValidChecksum($address), "$id $slot has a valid checksum");
        unset($contracts[$slot]);
      }
      $this->assertTrue(Address::equals(self::GOVERNANCE[$id]['reality'], $this->network($id)->getContracts()['reality']), "$id reality is Reality.eth 3.0");
      $this->assertTrue(Address::equals(self::GOVERNANCE[$id]['hats'], $this->network($id)->getContracts()['hats']), "$id hats is Hats Protocol v1");
      $this->assertCount(6, $contracts, "$id has the other six slots");
      $this->assertSame(array_fill_keys(array_keys($contracts), ''), $contracts, "$id other slots are empty");
    }
  }

  /**
   * Each entry carries the governance block the module reads (D-10).
   */
  public function testGovernanceThirdPartySettings(): void {
    foreach (self::GOVERNANCE as $id => $expected) {
      $network = $this->network($id);
      $settings = $network->getThirdPartySettings('safe_governance');
      $this->assertNotSame([], $settings, "$id has a safe_governance block");
      foreach ($settings as $key => $value) {
        $this->assertContains($key, GovernanceContracts::KEYS, "$id $key is a declared key");
        $this->assertIsString($value, "$id $key is a string");
      }
      foreach ($expected as $key => $value) {
        $actual = in_array($key, GovernanceContracts::SLOTS, TRUE) ? $network->getContracts()[$key] : ($settings[$key] ?? NULL);
        if (in_array($key, GovernanceContracts::ADDRESS_KEYS, TRUE)) {
          $this->assertIsString($actual, "$id $key is set");
          $this->assertTrue(Address::isValidFormat($actual), "$id $key is an address");
          $this->assertTrue(Address::hasValidChecksum($actual), "$id $key has a valid checksum");
          $this->assertTrue(Address::equals($value, $actual), "$id $key is the documented address");
        }
        else {
          $this->assertSame($value, $actual, "$id $key");
        }
      }
      foreach (['snapshot_hub', 'snapshot_sequencer', 'snapshot_app_url'] as $key) {
        $this->assertStringStartsWith('https://', $settings[$key] ?? '', "$id $key is an https URL");
      }
      $this->assertStringContainsString('{space}', $settings['snapshot_app_url'], "$id public record pattern has the space");
      $this->assertStringContainsString('{proposal}', $settings['snapshot_app_url'], "$id public record pattern has the proposal");
      $this->assertContains('safe_governance', $network->getDependencies()['module'] ?? [], "$id depends on safe_governance");
    }

    // Ethereum Sepolia: every key set, ENS included.
    $eth = $this->network('ethereum_sepolia')->getThirdPartySettings('safe_governance');
    $this->assertSame(GovernanceContracts::KEYS, array_keys($eth), 'ethereum_sepolia has every key, in schema order');
    foreach ($eth as $key => $value) {
      $this->assertNotSame('', $value, "ethereum_sepolia $key is not empty");
    }

    // Base Sepolia has no ENS: the keys are absent, not empty.
    $base = $this->network('base_sepolia')->getThirdPartySettings('safe_governance');
    $this->assertSame([], array_values(array_filter(array_keys($base), static fn (string $key): bool => str_starts_with($key, 'ens_'))), 'base_sepolia has no ens_* keys');
  }

  /**
   * GovernanceContracts hands the entries' values out unchanged.
   */
  public function testGovernanceContractsReadsTheEntries(): void {
    $contracts = $this->container->get('safe_governance.contracts');
    $this->assertInstanceOf(GovernanceContracts::class, $contracts);
    $eth = $contracts->for('ethereum_sepolia');
    $this->assertSame('130', $eth['reality_template_id']);
    $this->assertTrue(Address::equals(self::GOVERNANCE['ethereum_sepolia']['ens_registrar'], $eth['ens_registrar']));
    $this->assertSame('', $contracts->get('base_sepolia', 'ens_registrar'), 'No ENS on Base Sepolia, and no default.');
  }

  /**
   * Base Sepolia's voting page names are read on Ethereum Sepolia.
   *
   * The testnet voting service reads names on Ethereum Sepolia, and Base
   * Sepolia has no name registry, so a Base Sepolia group's name belongs to
   * its fund's address there (25-26 addendum).
   */
  public function testNamesAreOnEthereumSepolia(): void {
    $contracts = $this->container->get('safe_governance.contracts');
    foreach (['ethereum_sepolia', 'base_sepolia'] as $id) {
      $names = $contracts->nameNetwork($id);
      $this->assertNotNull($names, "$id names a network the registry holds");
      $this->assertSame('ethereum_sepolia', $names->id(), "$id voting page names");
    }
    $this->assertNotSame('', $contracts->get('ethereum_sepolia', 'ens_universal_helper'), 'The names network has a name registry.');
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
