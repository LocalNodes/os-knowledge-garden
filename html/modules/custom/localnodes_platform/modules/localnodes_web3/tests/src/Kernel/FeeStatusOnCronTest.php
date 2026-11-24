<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\gas_sponsor\Exception\SponsorNotConfiguredException;
use Drupal\gas_sponsor\Exception\SponsorUnavailableException;
use Drupal\gas_sponsor\GasSponsorInterface;
use Drupal\gas_sponsor\StatusMemory;
use Drupal\gas_sponsor\Value\SponsorStatus;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Entity\SafeNetworkInterface;
use Drupal\safe_smart_accounts\Entity\SafeTransactionInterface;
use Drupal\safe_smart_accounts\Service\ChainSnapshotStore;
use Drupal\safe_smart_accounts\Ui\PaymentView;
use Drupal\Tests\safe_smart_accounts\Kernel\SafeKernelTestBase;
use Drupal\user\UserInterface;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;

/**
 * A fresh site's group payment page can offer Send after cron has run.
 *
 * The payment page decides who pays the network fee from gas_sponsor's last
 * stored status read (PaymentView::coverage()). Before this change only the
 * Network Fee Account page stored one, so on a fresh site every payment
 * that was ready to send said "Couldn't check the network fee account right
 * now. Try again." and offered no Send until a site manager opened that
 * page. localnodes_web3's cron now reads the status the same way and stores
 * it.
 *
 * It still fails closed: a read that fails stores nothing, so the page
 * keeps that message and offers no Send. Send never shows for a payment
 * without enough approvals.
 *
 * localnodes_web3 can't be enabled in a kernel test (see
 * SponsoredExecutionDefaultTest), so its .module file is loaded and the cron
 * hook is called directly, with the sponsor service replaced by a stub.
 *
 * @group localnodes_web3
 */
final class FeeStatusOnCronTest extends SafeKernelTestBase {

  private const OWNER = '0x9F082e184259D2F7089DF5CED36679BB88bfe123';
  private const COSIGNER = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
  private const SAFE = '0x3E852dEF104033D32EB20f45e0A82B76a37BF142';
  private const RECIPIENT = '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC';
  private const NETWORK = 'test_sepolia';
  private const CHAIN_ID = 11155111;

  private const WALLET_FEE = 'This step needs a small network fee, paid from your own wallet. Members never pay it.';
  private const COMMUNITY_FEE = 'The community covers the network fee for this step.';
  private const UNKNOWN = "Couldn't check the network fee account right now. Try again.";

  /**
   * Approver A, the viewer.
   */
  private UserInterface $owner;

  /**
   * The group fund.
   */
  private SafeAccountInterface $safe;

  /**
   * The registry entry.
   */
  private SafeNetworkInterface $network;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['gas_sponsor']);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($this->rpcMock)]));
    $this->config('safe_smart_accounts.settings')->set('sponsored_execution', TRUE)->save();
    require_once dirname(__DIR__, 3) . '/localnodes_web3.module';
    $this->createSafe();
  }

  /**
   * Fresh site: no Send before cron; after cron, the Send the status allows.
   */
  public function testCronLetsFreshSiteOfferSend(): void {
    [$text, $settings, $html] = $this->page(2);
    $this->assertStringContainsString(self::UNKNOWN, $text, 'Before cron the fresh site has no status read.');
    $this->assertStringNotContainsString('safe-transaction-execute', $html);
    $this->assertTrue($settings['safeSmartAccounts']['sponsorUnknown'] ?? NULL);

    // A fund the sponsor doesn't cover: the organizer's own-wallet Send.
    $this->sponsorAnswers($this->status([]));
    localnodes_web3_cron();
    [$text, $settings, $html] = $this->page(2);
    $this->assertStringContainsString(self::WALLET_FEE, $text);
    $this->assertStringNotContainsString(self::UNKNOWN, $text);
    $this->assertStringContainsString('safe-transaction-execute', $html);
    $this->assertArrayNotHasKey('sponsorUnknown', $settings['safeSmartAccounts'] ?? []);

    // A fund the sponsor covers: the community's Send.
    $this->sponsorAnswers($this->status([[self::CHAIN_ID, self::SAFE]]));
    localnodes_web3_cron();
    [$text, $settings, $html] = $this->page(2);
    $this->assertStringContainsString(self::COMMUNITY_FEE, $text);
    $this->assertStringContainsString('safe-transaction-execute', $html);
    $this->assertTrue($settings['safeSmartAccounts']['sponsored'] ?? NULL);
  }

  /**
   * A zero balance is stored like any good read.
   *
   * The Network Fee Account page and the readiness check treat a zero
   * balance as a working fee account too: a practice relayer is funded
   * after hand-over.
   */
  public function testZeroBalanceIsStored(): void {
    $this->sponsorAnswers($this->status([], '0'));
    localnodes_web3_cron();
    $stored = $this->container->get('gas_sponsor.status_memory')->lastKnown();
    $this->assertInstanceOf(SponsorStatus::class, $stored['status']);
    $this->assertSame('0', $stored['status']->balanceWei(self::CHAIN_ID));
  }

  /**
   * A read that fails stores nothing: the page keeps failing closed.
   */
  public function testFailedReadStoresNothingAndOffersNoSend(): void {
    $failures = [
      'unreachable' => new SponsorUnavailableException('Down.'),
      'not set up' => new SponsorNotConfiguredException('No relayer.'),
      'anything else' => new \RuntimeException('0xSECRET https://rpc.example/KEY-SENTINEL'),
    ];
    foreach ($failures as $case => $exception) {
      $sponsor = $this->createMock(GasSponsorInterface::class);
      $sponsor->method('status')->willThrowException($exception);
      $this->container->set('gas_sponsor.sponsor', $sponsor);

      localnodes_web3_cron();

      $this->assertNull($this->container->get('state')->get(StatusMemory::KEY), $case);
      [$text, $settings, $html] = $this->page(2);
      $this->assertStringContainsString(self::UNKNOWN, $text, $case);
      $this->assertStringNotContainsString('Send payment', $text, $case);
      $this->assertStringNotContainsString('safe-transaction-execute', $html, $case);
      $this->assertTrue($settings['safeSmartAccounts']['sponsorUnknown'] ?? NULL, $case);
    }
  }

  /**
   * A failed read keeps the last good one, as the network fee page does.
   */
  public function testFailedReadKeepsTheLastGoodRead(): void {
    $this->sponsorAnswers($this->status([]));
    localnodes_web3_cron();
    $before = $this->container->get('state')->get(StatusMemory::KEY);
    $this->assertIsArray($before);

    $sponsor = $this->createMock(GasSponsorInterface::class);
    $sponsor->method('status')->willThrowException(new SponsorUnavailableException('Down.'));
    $this->container->set('gas_sponsor.sponsor', $sponsor);
    localnodes_web3_cron();

    $this->assertSame($before, $this->container->get('state')->get(StatusMemory::KEY));
  }

  /**
   * With a stored read, a payment short of its approvals still has no Send.
   */
  public function testNoSendWithoutEnoughApprovals(): void {
    foreach (['not covered' => [], 'covered' => [[self::CHAIN_ID, self::SAFE]]] as $case => $groups) {
      $this->sponsorAnswers($this->status($groups));
      localnodes_web3_cron();
      foreach ([0, 1] as $approvals) {
        [$text, , $html] = $this->page($approvals);
        $this->assertStringNotContainsString('Send payment', $text, "$case, $approvals of 2");
        $this->assertStringNotContainsString('safe-transaction-execute', $html, "$case, $approvals of 2");
      }
    }
  }

  /**
   * Without gas_sponsor's sponsor service, cron does nothing and doesn't fail.
   */
  public function testCronWithoutSponsorDoesNothing(): void {
    $this->disableModules(['gas_sponsor']);
    localnodes_web3_cron();
    $this->assertNull($this->container->get('state')->get(StatusMemory::KEY));
  }

  /**
   * Replaces the sponsor service with one answering this status.
   */
  private function sponsorAnswers(SponsorStatus $status): void {
    $sponsor = $this->createMock(GasSponsorInterface::class);
    $sponsor->method('status')->willReturn($status);
    $this->container->set('gas_sponsor.sponsor', $sponsor);
  }

  /**
   * A relayer status body covering the given funds.
   *
   * @param array<int, array{0: int, 1: string}> $groups
   *   Pairs of chain id and Safe address.
   * @param string $balance
   *   The fee account's balance in wei.
   */
  private function status(array $groups, string $balance = '42000000000000000'): SponsorStatus {
    return SponsorStatus::fromRelayer([
      'configured' => TRUE,
      'address' => '0xa0Ee7A142d267C1f36714E4a8F75612F20a79720',
      'chains' => [(string) self::CHAIN_ID => ['balanceWei' => $balance, 'pending' => 0]],
      'cap' => [
        'dayWei' => '50000000000000000',
        'monthWei' => '500000000000000000',
        'spentDayWei' => '0',
        'spentMonthWei' => '0',
        'dayResetsAt' => '2026-11-09T21:30:00.000Z',
        'monthResetsAt' => '2026-12-01T00:00:00.000Z',
        'monthStartsAt' => '2026-11-01T00:00:00.000Z',
      ],
      'groups' => array_map(static fn(array $group): array => [
        'chainId' => $group[0],
        'safe' => $group[1],
        'space' => 'garden-tools.eth',
        'community' => 'Test Community',
        'module' => NULL,
      ], $groups),
    ]);
  }

  /**
   * Renders a group payment page with this many approvals of 2.
   *
   * @return array{string, array<string, mixed>, string}
   *   The page text, its drupalSettings and its HTML.
   */
  private function page(int $approvals): array {
    $this->snapshot();
    $tx = $this->payment($approvals);
    $view = $this->container->get('safe_smart_accounts.payment_view');
    $this->assertInstanceOf(PaymentView::class, $view);
    $build = $view->page($tx, $this->safe, [
      'audience' => 'group',
      'back_url' => NULL,
      'can_send' => TRUE,
      'viewer_address' => self::OWNER,
    ]);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    BubbleableMetadata::createFromRenderArray($build);
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    // One payment at a time: the next page renders its own.
    $tx->delete();
    return [$text, $build['#attached']['drupalSettings'] ?? [], $html];
  }

  /**
   * The group fund, its registry entry and its two approvers.
   */
  private function createSafe(): void {
    $network = $this->container->get('entity_type.manager')->getStorage('safe_network')->create([
      'id' => self::NETWORK,
      'label' => 'Test Sepolia',
      'chain_id' => self::CHAIN_ID,
      'is_testnet' => TRUE,
      'rpc_urls' => ['https://rpc-a.invalid/v1/fake-key'],
      'explorer' => [],
      'native_currency' => ['name' => 'Test Ether', 'symbol' => 'ETH', 'decimals' => 18],
      'tokens' => [],
      'weight' => 0,
    ]);
    $network->save();
    $this->assertInstanceOf(SafeNetworkInterface::class, $network);
    $this->network = $network;
    $permissions = ['view own safe smart accounts', 'manage own safe smart accounts'];
    $this->owner = $this->createAccountUser($permissions, self::OWNER);
    $this->createAccountUser($permissions, self::COSIGNER);
    $this->safe = $this->createSafeAccount($this->owner, [
      'network' => self::NETWORK,
      'safe_address' => self::SAFE,
      'status' => 'active',
      'threshold' => 2,
      'safe_version' => '1.3.0',
    ]);
    $this->createSafeConfiguration($this->safe, [self::OWNER, self::COSIGNER], 2);
    $this->setCurrentUser($this->owner);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A payment at the Safe's nonce with this many approvals.
   */
  private function payment(int $approvals): SafeTransactionInterface {
    $signatures = [];
    foreach (array_slice([self::OWNER, self::COSIGNER], 0, $approvals) as $i => $signer) {
      $signatures[] = [
        'signer' => strtolower($signer),
        'signature' => '0x' . str_repeat('1', 128) . '1b',
        'signed_at' => 1793750400 + $i,
      ];
    }
    $tx = $this->container->get('entity_type.manager')->getStorage('safe_transaction')->create([
      'safe_account' => $this->safe->id(),
      'to_address' => self::RECIPIENT,
      'value' => '50000000000000000',
      'data' => '0x',
      'operation' => 0,
      'nonce' => 4,
      'status' => 'pending',
      'signatures' => json_encode($signatures),
      'description' => 'Seeds for the spring planting',
      'created_by' => $this->owner->id(),
    ]);
    $tx->save();
    $this->assertInstanceOf(SafeTransactionInterface::class, $tx);
    return $tx;
  }

  /**
   * A fresh chain snapshot, so rendering makes no RPC call.
   */
  private function snapshot(): void {
    $now = (int) $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('keyvalue')
      ->get(ChainSnapshotStore::COLLECTION)
      ->set(ChainSnapshotStore::key($this->network, self::SAFE), [
        'owners' => [self::OWNER, self::COSIGNER],
        'threshold' => 2,
        'nonce' => '4',
        'version' => '1.3.0',
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
  }

}
