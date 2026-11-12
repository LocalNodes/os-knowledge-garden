<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Component\Serialization\Yaml;
use Drupal\group_treasury\Service\SafeAppLink;
use Drupal\localnodes_web3\SiweHelpLink;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Entity\SafeNetworkInterface;
use Drupal\safe_smart_accounts\Entity\SafeTransactionInterface;
use Drupal\safe_smart_accounts\Service\ChainSnapshotStore;
use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;
use Drupal\user\UserInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * The "Hacked or phished? Get help" links on Approve and the wallet sign-in.
 *
 * Someone about to approve a payment, and someone signing in with a
 * wallet, reaches the "Hacked or Phished?" page (/help/hacked-or-phished,
 * localnodes_platform) in one click. The words are the approved ones.
 *
 * - The payment page: localnodes_web3 implements safe_smart_accounts'
 *   generic hook_safe_smart_accounts_approve_help(), so the link sits right
 *   after "Approve payment", on payments and settings changes alike.
 * - The sign-in: localnodes_web3 adds the link to the siwe_login block. It
 *   is checked through the real block pipeline (a placed block, the block
 *   view builder and the renderer) in three set-ups: siwe_login alone, and
 *   siwe_login_waap with show_siwe_wallet_button TRUE and FALSE. With FALSE,
 *   WaaP's own #pre_render replaces the whole block content, so the help
 *   link has to be added after it (review 15-M1).
 * - In the top bar ("header") the sign-in block gets no link.
 * - Without localnodes_platform the route is missing: no link, no error.
 * - Every network this module ships gets group_treasury's "Open in Safe
 *   app" link, which the help page's "If this site is down" section names.
 *
 * localnodes_web3 and localnodes_platform are added with enableModules(),
 * which loads their .module files without their .info.yml dependencies
 * (they pull in Open Social, which a Kernel test can't boot), as in
 * TreasuryStylesTest.
 *
 * @group localnodes_web3
 */
final class SafetyHelpLinksTest extends SafeKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'filter',
    'block',
    'siwe_login',
    'safe_smart_accounts',
  ];

  /**
   * The approved link text.
   */
  private const LINK_TEXT = 'Hacked or phished? Get help';

  /**
   * The help page's path.
   */
  private const PATH = '/help/hacked-or-phished';

  /**
   * Approver A, who set up the account.
   */
  private const OWNER = '0x9F082e184259D2F7089DF5CED36679BB88bfe123';

  /**
   * Approver B.
   */
  private const COSIGNER = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

  /**
   * A recipient no site account holds.
   */
  private const RECIPIENT = '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC';

  /**
   * The shared account's address.
   */
  private const SAFE = '0x3E852dEF104033D32EB20f45e0A82B76a37BF142';

  /**
   * The registry id of the test network.
   */
  private const NETWORK = 'test_sepolia';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'filter']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
  }

  /**
   * The payment page: the link after "Approve payment" and the signing code.
   */
  public function testPaymentPageLinksTheHelpPage(): void {
    $this->enable('localnodes_platform', 'localnodes_web3');
    [$safe, $owner] = $this->sharedAccount();
    $tx = $this->payment($safe, $owner, ['signed_by' => [self::OWNER]]);

    $html = $this->paymentPage($tx, $safe);
    $xpath = self::dom($html);
    $approve = $xpath->query('//*[contains(@class, "safe-transaction-sign")]')->item(0);
    $this->assertInstanceOf(\DOMElement::class, $approve);
    // The Safe's own code sits between the button and the help
    // links (safe_smart_accounts PaymentHelpLinkTest pins the same order).
    $code = $xpath->query('following-sibling::*[1]', $approve)->item(0);
    $this->assertInstanceOf(\DOMElement::class, $code);
    $this->assertStringContainsString('safe-signing-code-block', $code->getAttribute('class'));
    $next = $xpath->query('following-sibling::*[1]', $code)->item(0);
    $this->assertInstanceOf(\DOMElement::class, $next);
    $this->assertStringContainsString('safe-approve-help-links', $next->getAttribute('class'));
    $links = $xpath->query('.//a', $next);
    $this->assertSame(1, $links->length);
    $this->assertSame(self::LINK_TEXT, self::squash($links->item(0)->textContent));
    $this->assertStringEndsWith(self::PATH, $links->item(0)->getAttribute('href'));
    $this->assertSame(1, substr_count($html, self::LINK_TEXT));

    // A settings change (add an approver) gets it too.
    $change = $this->payment($safe, $owner, [
      'nonce' => 4,
      'value' => '0',
      'to' => self::SAFE,
      'signed_by' => [self::OWNER],
    ]);
    $change->set('data', '0x0d582f13' . str_pad(strtolower(substr(self::RECIPIENT, 2)), 64, '0', STR_PAD_LEFT) . str_pad('2', 64, '0', STR_PAD_LEFT))->save();
    $html = $this->paymentPage($change, $safe);
    $query = '//*[contains(@class, "safe-approve-help-links")]//a[normalize-space(.)="' . self::LINK_TEXT . '"]';
    $this->assertSame(1, self::dom($html)->query($query)->length);
  }

  /**
   * Without localnodes_platform the hook returns nothing and the page works.
   */
  public function testNoHelpRouteNoPaymentLink(): void {
    $this->enable('localnodes_web3');
    [$safe, $owner] = $this->sharedAccount();
    $tx = $this->payment($safe, $owner, ['signed_by' => [self::OWNER]]);

    $this->assertSame([], localnodes_web3_safe_smart_accounts_approve_help($tx, $safe));
    $html = $this->paymentPage($tx, $safe);
    $this->assertStringContainsString('Approve payment', $html);
    $this->assertStringNotContainsString(self::LINK_TEXT, $html);
    $this->assertStringNotContainsString('safe-approve-help-links', $html);
  }

  /**
   * The sign-in block, siwe_login alone.
   */
  public function testSignInBlockAlone(): void {
    $this->enable('localnodes_platform', 'localnodes_web3');

    $xpath = self::dom($this->signInBlock());
    $this->assertSame(1, $xpath->query('//*[@id="siwe-login-button"]')->length, 'The legacy wallet button.');
    $this->assertOneHelpLinkAfter($xpath, 'siwe-login-button');
  }

  /**
   * The sign-in block with WaaP and the wallet button beside Sign in.
   */
  public function testSignInBlockWithWaapAndWalletButton(): void {
    $this->enable('localnodes_platform', 'localnodes_web3', 'siwe_login_waap');
    $this->installConfig(['siwe_login_waap']);
    $this->config('siwe_login_waap.settings')->set('show_siwe_wallet_button', TRUE)->save();

    $xpath = self::dom($this->signInBlock());
    $this->assertSame(1, $xpath->query('//*[@id="siwe-login-waap"]')->length, "WaaP's mount element.");
    $this->assertSame('Use a browser wallet', self::squash((string) $xpath->query('//*[@id="siwe-login-button"]')->item(0)?->getAttribute('value')));
    $this->assertOneHelpLinkAfter($xpath, 'siwe-login-button');
  }

  /**
   * The sign-in block with WaaP alone: its pre_render replaces the content.
   */
  public function testSignInBlockWithWaapOnly(): void {
    $this->enable('localnodes_platform', 'localnodes_web3', 'siwe_login_waap');
    $this->installConfig(['siwe_login_waap']);
    $this->assertFalse($this->config('siwe_login_waap.settings')->get('show_siwe_wallet_button'));

    $xpath = self::dom($this->signInBlock());
    $this->assertSame(0, $xpath->query('//*[@id="siwe-login-button"]')->length, 'WaaP replaced the legacy button.');
    $this->assertSame(1, $xpath->query('//*[@id="siwe-login-waap"]')->length);
    $this->assertOneHelpLinkAfter($xpath, 'siwe-login-waap');
  }

  /**
   * In the top bar the sign-in block has no link, so the bar keeps its height.
   *
   * The link is dark text under the button there, which made the bar taller.
   * The footer links the help page on every page, and the log-in and sign-up
   * pages show it under their own button (SignInEntryTest).
   */
  public function testTopBarSignInHasNoLink(): void {
    $this->enable('localnodes_platform', 'localnodes_web3', 'siwe_login_waap');
    $this->installConfig(['siwe_login_waap']);

    $xpath = self::dom($this->signInBlock('header'));
    $this->assertSame(1, $xpath->query('//*[@id="siwe-login-waap"]')->length, 'The sign-in is still there.');
    $this->assertSame(0, $xpath->query('//a[normalize-space(.)="' . self::LINK_TEXT . '"]')->length, 'No help link in the top bar.');
    $this->assertSame(0, $xpath->query('//*[contains(@class, "ln-safety-help-link")]')->length);
  }

  /**
   * Without localnodes_platform the block has no link and still renders.
   */
  public function testNoHelpRouteNoSignInLink(): void {
    $this->enable('localnodes_web3');

    $html = $this->signInBlock();
    $this->assertStringContainsString('siwe-login-button', $html);
    $this->assertStringNotContainsString(self::LINK_TEXT, $html);
  }

  /**
   * The callback never adds a second link.
   */
  public function testHelpLinkAddedOnce(): void {
    $this->enable('localnodes_platform', 'localnodes_web3');

    $build = ['content' => ['button' => ['#markup' => 'Sign in']]];
    $build = SiweHelpLink::preRender(SiweHelpLink::preRender($build));
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertSame(1, substr_count($html, self::LINK_TEXT));

    // An empty block (no content) is left alone.
    $this->assertSame(['#cache' => ['max-age' => 0]], SiweHelpLink::preRender(['#cache' => ['max-age' => 0]]));
  }

  /**
   * Every network this module ships gets an "Open in Safe app" link.
   *
   * The help page tells approvers to use the Safe app if this site is down,
   * and group_treasury's fund card links it only for a network whose
   * registry entry has a safe_app_short_name. A shipped network without one
   * would silently have no link.
   */
  public function testShippedNetworksHaveSafeAppLinks(): void {
    $files = glob(dirname(__DIR__, 3) . '/config/install/safe_smart_accounts.network.*.yml') ?: [];
    $this->assertNotEmpty($files, 'The module ships networks.');
    $storage = $this->container->get('entity_type.manager')->getStorage('safe_network');
    foreach ($files as $file) {
      $values = Yaml::decode((string) file_get_contents($file));
      $this->assertIsArray($values, basename($file) . ' reads as a mapping.');
      $network = $storage->create($values);
      $this->assertInstanceOf(SafeNetworkInterface::class, $network);
      $this->assertNotSame('', $network->getSafeAppShortName(), basename($file) . ' names its Safe app short name.');
      $this->assertNotNull(SafeAppLink::url($network, self::OWNER), basename($file) . ' gets a Safe app link.');
    }
  }

  /**
   * One help link, after the element with id $control, outside the WaaP mount.
   */
  private function assertOneHelpLinkAfter(\DOMXPath $xpath, string $control): void {
    $links = $xpath->query('//a[normalize-space(.)="' . self::LINK_TEXT . '"]');
    $this->assertSame(1, $links->length, 'Exactly one help link.');
    $this->assertStringEndsWith(self::PATH, $links->item(0)->getAttribute('href'));
    $this->assertSame(0, $xpath->query('//*[@id="siwe-login-waap"]//a')->length, "Nothing inside WaaP's mount element.");
    $this->assertSame(1, $xpath->query('//a[normalize-space(.)="' . self::LINK_TEXT . '"]/preceding::*[@id="' . $control . '"]')->length, 'The link comes after the sign-in control.');
  }

  /**
   * Places the siwe_login block and renders it through the block pipeline.
   *
   * @param string $region
   *   The stark region to place it in; "header" is the top bar.
   */
  private function signInBlock(string $region = 'content'): string {
    $block = Block::create([
      'id' => 'siwe_login',
      'plugin' => 'siwe_login_block',
      'theme' => 'stark',
      'region' => $region,
      'settings' => [
        'label' => 'SIWE Login',
        'label_display' => FALSE,
        'provider' => 'siwe_login',
        'button_text' => 'Sign in with Ethereum',
      ],
    ]);
    $block->save();
    $build = $this->container->get('entity_type.manager')->getViewBuilder('block')->view($block);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Enables modules, rebuilds the router and puts the RPC mock back.
   *
   * KernelTestBase keeps modules in the order they are enabled; a site sorts
   * them by weight, then name, so localnodes_web3 comes before
   * siwe_login_waap and its block alter would run first. Callers pass the
   * modules in that order, and this checks it, so the WaaP set-ups test the
   * order a site has (localnodes_web3_module_implements_alter()).
   */
  private function enable(string ...$modules): void {
    $sorted = $modules;
    sort($sorted);
    $this->assertSame($sorted, $modules, 'Modules are enabled in the order a site lists them.');
    $this->enableModules($modules);
    $stack = HandlerStack::create($this->rpcMock);
    $stack->push(Middleware::history($this->rpcHistory));
    $this->container->set('http_client', new Client(['handler' => $stack]));
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A shared account with two approvers and a fresh snapshot.
   *
   * @return array{0: \Drupal\safe_smart_accounts\Entity\SafeAccountInterface, 1: \Drupal\user\UserInterface}
   *   The account and its owner.
   */
  private function sharedAccount(): array {
    $this->installEntitySchema('safe_network');
    $network = $this->container->get('entity_type.manager')->getStorage('safe_network')->create([
      'id' => self::NETWORK,
      'label' => 'Zeta Label',
      'chain_id' => 11155111,
      'is_testnet' => TRUE,
      'rpc_urls' => ['https://rpc-a.invalid/v1/fake-key'],
      'explorer' => [
        'name' => 'Testscan',
        'address_url' => 'https://testscan.invalid/address/{address}',
        'tx_url' => 'https://testscan.invalid/tx/{hash}',
      ],
      'native_currency' => ['name' => 'Test Ether', 'symbol' => 'ETH', 'decimals' => 18],
      'tokens' => [],
      'weight' => 0,
    ]);
    $network->save();
    $permissions = ['view own safe smart accounts', 'manage own safe smart accounts', 'create safe transactions'];
    $owner = $this->createAccountUser($permissions, self::OWNER);
    $this->createAccountUser($permissions, self::COSIGNER);
    $safe = $this->createSafeAccount($owner, [
      'network' => self::NETWORK,
      'safe_address' => self::SAFE,
      'status' => 'active',
      'threshold' => 2,
      'safe_version' => '1.3.0',
    ]);
    $this->createSafeConfiguration($safe, [self::OWNER, self::COSIGNER], 2);
    $this->setCurrentUser($owner);
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('keyvalue')->get(ChainSnapshotStore::COLLECTION)->set(ChainSnapshotStore::key($network, self::SAFE), [
      'owners' => [self::OWNER, self::COSIGNER],
      'threshold' => 2,
      'nonce' => '4',
      'version' => '1.4.1',
      'balances' => [[
        'asset' => 'native',
        'name' => 'Test Ether',
        'symbol' => 'ETH',
        'raw' => '520000000000000000',
        'decimals' => 18,
        'error' => NULL,
      ],
      ],
      'read_at' => $now,
      'last_error' => NULL,
      'last_error_at' => NULL,
    ]);
    return [$safe, $owner];
  }

  /**
   * Creates a payment on the shared account.
   */
  private function payment(SafeAccountInterface $safe, UserInterface $owner, array $values = []): SafeTransactionInterface {
    $values += [
      'nonce' => 4,
      'value' => '50000000000000000',
      'signed_by' => [],
      'to' => self::RECIPIENT,
    ];
    $signatures = [];
    foreach ($values['signed_by'] as $i => $signer) {
      $signatures[] = [
        'signer' => strtolower($signer),
        'signature' => '0x' . str_repeat('1', 128) . '1b',
        'signed_at' => 1793750400 + $i,
      ];
    }
    $tx = $this->container->get('entity_type.manager')->getStorage('safe_transaction')->create([
      'safe_account' => $safe->id(),
      'to_address' => $values['to'],
      'value' => $values['value'],
      'data' => '0x',
      'operation' => 0,
      'nonce' => $values['nonce'],
      'status' => 'pending',
      'signatures' => json_encode($signatures),
      'description' => 'Seeds for the spring planting',
      'created_by' => $owner->id(),
    ]);
    $tx->save();
    $this->assertInstanceOf(SafeTransactionInterface::class, $tx);
    return $tx;
  }

  /**
   * The payment page as approver B, who hasn't approved, sees it.
   */
  private function paymentPage(SafeTransactionInterface $tx, SafeAccountInterface $safe): string {
    $build = $this->container->get('safe_smart_accounts.payment_view')->page($tx, $safe, [
      'audience' => 'group',
      'can_send' => FALSE,
      'viewer_address' => self::COSIGNER,
    ]);
    $this->assertSame('needs_your_approval', $this->container->get('safe_smart_accounts.payment_view')->status($tx, $safe, self::COSIGNER)['key']);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * An XPath over a rendered fragment.
   */
  private static function dom(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
    libxml_clear_errors();
    return new \DOMXPath($dom);
  }

  /**
   * Collapses whitespace.
   */
  private static function squash(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
  }

}
