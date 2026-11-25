<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\gas_sponsor_fixture\FixtureSponsor;
use Drupal\group\Entity\GroupInterface;
use Drupal\safe_governance\Codec\HatId;
use Drupal\safe_governance\Codec\HatsCodec;
use Drupal\safe_governance\Entity\GovernanceMemberChangeInterface;
use Drupal\safe_governance\Entity\SafeGovernanceInterface;
use Drupal\safe_governance\Plugin\QueueWorker\MembershipStepWorker;
use Drupal\safe_governance\Plugin\QueueWorker\SetupStepWorker;
use Drupal\safe_governance\Service\HostPowerCheck;
use Drupal\safe_governance\Service\SetupRunner;
use Drupal\safe_governance\Service\MembershipRunner;
use Drupal\safe_governance\Snapshot\VotingStrategy;
use Drupal\safe_governance_group\Controller\ProposalPublishController;
use Drupal\safe_governance_group\Form\ApproveMembersForm;
use Drupal\safe_governance_group\Form\DecisionProposalForm;
use Drupal\safe_governance_hub_fixture\FixtureHubClient;
use Drupal\safe_smart_accounts\Abi\AbiDecoder;
use Drupal\safe_smart_accounts\Abi\AbiEncoder;
use Drupal\safe_smart_accounts\Controller\SafeTransactionController;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\safe_smart_accounts\Entity\SafeTransaction;
use Drupal\safe_smart_accounts\Entity\SafeTransactionInterface;
use Drupal\safe_smart_accounts\Safe\CanonicalDeployments;
use Drupal\safe_smart_accounts\Safe\Selectors;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Service\SignatureVerifier;
use Drupal\safe_smart_accounts\Value\Address;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Drupal\localnodes_web3\Votes\PublicVotesQueueHold;
use Drupal\Core\Queue\QueueWorkerInterface;
use Drupal\Tests\safe_governance_group\Kernel\GovernanceGroupKernelTestBase;
use Drupal\user\UserInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Members-only groups do not publish votes, and their fund still pays.
 *
 * A group whose visibility is "members" decides in its own record: voting
 * set-up, new proposals and publishing are refused for it, the doors to
 * them are gone, and the voting module's queued set-up steps and approved
 * role changes wait on the site until the group is public again. Public and
 * community groups are unchanged. Whatever waits, the group's fund keeps
 * paying: nothing kept for a members-only group may hold up its payments.
 *
 * Runs on the voting module's own kernel base: Open Social's Group stack on
 * real entities, a group fund, the voting records, the community's sponsor
 * fixture and a canned chain that fails the test on any request it has no
 * answer for.
 *
 * @group localnodes_web3
 */
final class PublicVotesTest extends GovernanceGroupKernelTestBase {

  /**
   * Open Social's field for a flexible group's visibility.
   */
  private const VISIBILITY = 'field_flexible_group_visibility';

  /**
   * The sentence a members-only group is refused with (literal, so the
   * tests run before the class that holds it exists).
   */
  private const SENTENCE = 'This group decides in its own record. Votes are for groups that everyone on this site can see.';

  /**
   * The route requirement the site's check serves.
   */
  private const REQUIREMENT = '_localnodes_web3_public_votes';

  /**
   * The voting steps a members-only group is refused: set-up, then new
   * proposals and publishing.
   */
  private const SETUP_ROUTES = [
    'safe_governance_group.setup',
    'safe_governance_group.setup_messages',
    'safe_governance_group.setup_approve',
    'safe_governance_group.setup_restart',
    'safe_governance_group.setup_status',
  ];
  private const PROPOSAL_ROUTES = [
    'safe_governance_group.proposal_new',
    'safe_governance_group.proposal_new_payment',
    'safe_governance_group.proposal_new_decision',
    'safe_governance_group.proposal_message',
    'safe_governance_group.proposal_publish',
  ];

  /**
   * The key-value collection of held queued steps, one key per queue and
   * voting record (literal, so the tests run before the class exists).
   */
  private const HELD_STEPS = 'localnodes_web3.held_steps';

  /**
   * The voting module's queues the site holds for a members-only group.
   */
  private const QUEUES = ['safe_governance_setup', 'safe_governance_membership'];

  /**
   * The queue workers whose outside requests count as the held steps' own:
   * the site's hold and the voting module's two workers it wraps.
   */
  private const WORKERS = [
    'Drupal\\localnodes_web3\\Votes\\PublicVotesQueueHold',
    SetupStepWorker::class,
    MembershipStepWorker::class,
  ];

  /**
   * A recipient no site account holds.
   */
  private const RECIPIENT = '0x3C44CdDdB6a900fa2b585dd299e03d12FA4293BC';

  // ---------------------------------------------------------------------
  // Copied from the voting module's ApproveMembersFormTest and
  // MembershipRunnerTest (both classes are final): what a
  // role batch needs. The fund's approvers are the organizer (Ana) and Cy;
  // two of two approve.
  // ---------------------------------------------------------------------

  /**
   * Contracts on the test network: Hats and MultiSendCallOnly.
   */
  private const HATS = '0x3bc1A0Ad72417f2d411118085256fC53CBdDd137';
  private const MULTI_SEND_CALL_ONLY = '0x9641d764fc13c8B624c04430C7356C1C7C8102e2';

  /**
   * The canonical 1.4.1 MultiSend, which a voting set-up's payment calls.
   */
  private const MULTI_SEND = '0x38869bf66a61cF6bDB996A6aE40D5853Fd43B526';

  /**
   * The approvers: Ana, the organizer, and Cy.
   */
  private const ANA = '0x9F082e184259D2F7089DF5CED36679BB88bfe123';
  private const CY = '0xc0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0c0';

  /**
   * Members waiting for a role.
   */
  private const JO = '0x15d34aaf54267db7d7c367839aaf71a00a2c6a65';
  private const MO = '0xabababababababababababababababababababab';
  private const BO = '0xb0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0';

  /**
   * One 65-byte signature per approver, so their order shows.
   */
  private const SIGNATURES = [
    self::ANA => '0x111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111b',
    self::CY => '0x222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222222221b',
  ];

  /**
   * From the voting module's ProposalCreateTest (final): the
   * voting module's contracts, the latest block and the proposer.
   */
  private const MODULE = '0xD1d1d1D1d1d1D1d1D1D1D1d1d1D1D1D1d1D1d1d1';
  private const ARBITRATOR = '0x2222222222222222222222222222222222222222';
  private const LATEST_HEX = '0x8a5f3c';

  /**
   * The fund's own hash of a payment (getTransactionHash).
   */
  /**
   * What an organizer is told when a change still waits (the site's line).
   */
  private const LINE = "This group can't be made members-only yet: a change to who may vote is still waiting to go through. Carry it out or withdraw it first.";

  /**
   * What a save that skipped validation is refused with (for developers).
   */
  private const NOT_LOCKED = "A group with a fund is made members-only only through validation, which takes the fund's lock: validate the group, then save it.";

  /**
   * What the refusal page says when a save skipped validation and nothing
   * waits (literal, so the tests run before the constant exists).
   */
  private const FROM_HERE = "This group can't be made members-only from here. Change it on the group's settings page.";

  /**
   * The payment module's refusal of a check while another send is open
   * (SafeTransactionController::NONCE_BUSY).
   */
  private const NONCE_BUSY = "Another payment from this fund is still being sent. Try again once it's done.";

  /**
   * The set-up's registry addresses (SetupApproveRouteTest).
   */
  private const SETUP_REGISTRY = [
    'create_call' => '0x9b35Af71d77eaf8d7e40252370304687390A1A52',
    'zodiac_module_factory' => '0x000000000000aDdB49795b0f9bA5BC298cDda236',
    'zodiac_reality_mastercopy' => '0x4e35DA39Fa5893a70A40Ce964F993d891E607cC0',
    'reality' => '0xaf33DcB6E8c5c4D9dDF579f53031b514d19449CA',
  ];

  /**
   * The approve screen's line in a members-only group.
   */
  private const ROLES_LINE = 'In this group, who may vote or propose is kept on this site only.';

  /**
   * The set-up approval routes, by the method of the voting module's controller.
   */
  private const APPROVAL_ROUTES = [
    'safe_governance_group.setup_messages' => 'messages',
    'safe_governance_group.setup_approve' => 'approve',
    'safe_governance_group.setup_restart' => 'restart',
  ];

  /**
   * The voting module's set-up controller.
   */
  private const SETUP_INNER = '\\Drupal\\safe_governance_group\\Controller\\SetupSignatureController::';

  /**
   * The site's controller in front of the set-up approval routes.
   */
  private const SETUP_LOCK = 'Drupal\\localnodes_web3\\Controller\\SetupApprovalLock::';

  /**
   * The voting module's publish controller.
   */
  private const PUBLISH_INNER = '\\Drupal\\safe_governance_group\\Controller\\ProposalPublishController::publish';

  /**
   * The site's controller in front of the publish route.
   */
  private const PUBLISH_LOCK = 'Drupal\\localnodes_web3\\Controller\\ProposalPublishLock::publish';

  /**
   * A member's signature on a proposal (ProposalCreateTest).
   */
  private const PROPOSAL_SIG = '0xcd34ef56ab127890cd34ef56ab127890cd34ef56ab127890cd34ef56ab127890cd34ef56ab127890cd34ef56ab127890cd34ef56ab127890cd34ef56ab1278901c';

  /**
   * The key-value collection of approvals held on the site.
   */
  private const HELD_APPROVALS = 'localnodes_web3.held_approvals';

  /**
   * The voting module's own words when an approval can't be sent.
   */
  private const APPROVE_BUSY = "Something went wrong on our side, so the changes weren't sent for approval. Try again in a minute.";

  private const SAFE_TX_HASH = '0x5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe5afe';

  /**
   * The organizer, an approver.
   */
  private UserInterface $ana;

  /**
   * The second approver.
   */
  private UserInterface $cy;

  /**
   * Hat wearers: lowercase addresses by hat id (uint256).
   *
   * @var array<string, string[]>
   */
  private array $wearers = [];

  /**
   * The address the signature check's ecrecover answers.
   */
  private string $recovers = self::ANA;

  /**
   * Receipts by lowercase transaction hash.
   *
   * @var array<string, array<string, mixed>|null>
   */
  private array $receipts = [];

  /**
   * The fund's nonce on the chain, as a decimal string.
   */
  private string $safeNonce = '0';

  /**
   * Hats reads and writes asked of the chain.
   */
  private int $hatsCalls = 0;

  /**
   * Every outside request the canned chain answered or refused.
   */
  private int $requests = 0;

  /**
   * Outside requests made inside a queued voting step (WORKERS).
   */
  private int $workerRequests = 0;

  /**
   * Funds made by fundedGroup() so far, for unique addresses.
   */
  private int $extraFunds = 0;

  /**
   * A member who may propose, once proposerFixture() ran.
   */
  private ?UserInterface $jo = NULL;

  // ---------------------------------------------------------------------
  // End of the copied block.
  // ---------------------------------------------------------------------

  /**
   * {@inheritdoc}
   *
   * Core's database lock, as production uses it (safe_smart_accounts'
   * NextNonceTest does the same): the kernel base's NullLockBackend always
   * acquires, so no busy lock could be tested. Set here, before the site's
   * services that take @lock are built.
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->register('lock', DatabaseLockBackend::class)
      ->addArgument(new Reference('database'));
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Open Social stores a flexible group's visibility in this field
    // (social_group_flexible_group's field storage: list_string, one value).
    // Kernel tests don't boot Open Social, so the storage is made here, with
    // the same three values and labels.
    FieldStorageConfig::create([
      'field_name' => self::VISIBILITY,
      'entity_type' => 'group',
      'type' => 'list_string',
      'cardinality' => 1,
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'community' => 'Community',
          'members' => 'Group members only (secret)',
        ],
      ],
    ])->save();

    $this->enableModules(['localnodes_platform', 'localnodes_web3']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->useCannedChain();
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    $this->ana = $this->createSiteUser('Ana', self::ANA);
    $this->cy = $this->createSiteUser('Cy', self::CY);
    $this->createVotingGroup(['members' => [[$this->ana, TRUE]]]);
    FieldConfig::create([
      'field_name' => self::VISIBILITY,
      'entity_type' => 'group',
      'bundle' => self::GROUP_TYPE,
      'label' => 'Group visibility',
    ])->save();
    $this->group = $this->reloadGroup();
    $this->group->set(self::VISIBILITY, 'public')->save();

    $this->network->set('contracts', ['hats' => self::HATS] + $this->network->getContracts())
      ->setThirdPartySetting('safe_governance', 'multi_send_call_only', self::MULTI_SEND_CALL_ONLY)
      ->save();
    $this->fundOwners = [self::ANA, self::CY];
    $this->fundThreshold = 2;
    $this->safe->set('threshold', 2)->set('safe_version', '1.4.1')->save();
    $this->entityTypeManager->getStorage('safe_configuration')->create([
      'id' => 'safe_' . $this->safe->id(),
      'label' => 'Safe ' . $this->safe->id(),
      'safe_account_id' => (int) $this->safe->id(),
      'signers' => [strtolower(self::ANA), strtolower(self::CY)],
      'threshold' => 2,
      'salt_nonce' => 0,
    ])->save();

    $this->setCurrentUser($this->ana);
    // The voting module's host-control check reads the voting page too.
    $this->container->get('state')->set(FixtureHubClient::SCENARIO_KEY, 'host_power_clean');
    // The community's sponsor fixture records and answers every send.
    $this->container->get('state')->set(FixtureSponsor::SCENARIO_KEY, FixtureSponsor::SCENARIO_SETUP_OK);
    $this->config('safe_smart_accounts.settings')->set('sponsored_execution', TRUE)->save();
  }

  /**
   * {@inheritdoc}
   *
   * The fund's approver record has no config schema of its own, as in
   * safe_smart_accounts' kernel base (SafeKernelTestBase).
   */
  protected function getConfigSchemaExclusions() {
    $exclusions = parent::getConfigSchemaExclusions();
    for ($id = 1; $id <= 20; $id++) {
      $exclusions[] = 'safe_smart_accounts.safe_configuration.safe_' . $id;
    }
    return $exclusions;
  }

  /**
   * A members-only group's fund pays end to end with no on-chain role.
   *
   * The group has no voting record and no role row. An approver proposes a
   * payment at the fund's next number, both approvers approve it and the
   * community's sponsor sends it: it is next in line before the send, the
   * sponsor is asked for that payment alone, its receipt is confirmed, and
   * no Hats read or write is asked of the chain.
   */
  public function testMembersOnlyFundStillPays(): void {
    $this->visibility('members');
    $this->assertNull($this->governanceRecord());
    $this->assertSame([], $this->entityTypeManager->getStorage('governance_member_change')->getQuery()->accessCheck(FALSE)->execute());

    $payment = $this->proposePayment();
    $this->approvePayment($payment);
    $this->assertNextInLine($payment);

    $body = $this->sendSponsored($payment);
    $this->assertSame('confirmed', $body['receipt_status'] ?? NULL, (string) json_encode($body));

    $calls = $this->sponsorCalls();
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'The sponsor is asked for one send.');
    [$chain_id, $safe, $sent] = $calls[0]['args'];
    $this->assertSame(11155111, $chain_id);
    $this->assertSame(strtolower(self::SEPOLIA_SAFE), strtolower((string) $safe));
    $this->assertSame(strtolower(self::RECIPIENT), strtolower((string) $sent['to']), 'The send is the payment.');
    $this->assertSame('1000', (string) $sent['value']);
    $this->assertTrue($this->loadTransaction((int) $payment->id())->isExecuted());
    $this->assertSame(0, $this->hatsCalls, 'No Hats read or write was asked of the chain.');
  }

  /**
   * Approving a role change in a members-only group stores no fund payment.
   *
   * The organizer approves one waiting change on Approve new members. No
   * fund payment is stored, the row still waits, and a payment proposed
   * afterwards is next in line and is sent.
   */
  public function testHeldRoleBatchLeavesFundPaying(): void {
    $this->member('Jo', self::JO);
    $record = $this->readyRecord();
    $jo = $this->row($record, self::JO);
    $this->visibility('members');
    $before = $this->transactionCount();

    [, $state] = $this->submitApprove([(int) $jo->id() => ['select' => 1]]);
    $this->assertSame([], self::errors($state));
    $this->assertSame($before, $this->transactionCount(), 'No fund payment is stored.');
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $this->reloadRow($jo)->getStatus());

    $payment = $this->proposePayment();
    $this->approvePayment($payment);
    $this->assertNextInLine($payment);
    $body = $this->sendSponsored($payment);
    $this->assertSame('confirmed', $body['receipt_status'] ?? NULL, (string) json_encode($body));
    $this->assertTrue($this->loadTransaction((int) $payment->id())->isExecuted());
  }

  /**
   * A set-up whose fund payment went through does not hold up payments.
   *
   * Voting set-up was approved while the group was public, its fund payment
   * carried out and the record moved on to naming. The group is then made
   * members-only and the set-up queue is not run. A payment proposed and
   * approved afterwards is next in line.
   */
  public function testHeldSetUpLeavesFundPaying(): void {
    $setup = $this->entityTypeManager->getStorage('safe_transaction')->create([
      'safe_account' => $this->safe->id(),
      'to_address' => self::MULTI_SEND,
      'value' => '0',
      'data' => '0x8d80ff0a',
      'operation' => 1,
      'nonce' => 0,
      'status' => 'executed',
      'created_by' => $this->ana->id(),
    ]);
    $this->assertInstanceOf(SafeTransaction::class, $setup);
    $setup->markServerBuilt(CanonicalDeployments::MULTI_SEND);
    $setup->save();
    $this->safeNonce = '1';
    $this->setupRecord(SafeGovernanceInterface::STATUS_NAMING, (int) $setup->id());
    $this->visibility('members');

    $payment = $this->proposePayment();
    $this->assertSame('1', (string) $payment->get('nonce')->value, 'The payment takes the number after the set-up.');
    $this->approvePayment($payment);
    $this->assertNextInLine($payment);
  }

  /**
   * A members-only group can't open voting set-up, and is told why.
   *
   * The organizer's request for the set-up page answers 403 with the one
   * sentence and a link back to the Votes tab, never cached. Public and
   * community groups open it as before.
   */
  public function testMembersGroupCannotOpenVotingSetup(): void {
    $this->visibility('members');
    $id = $this->group->id();
    $response = $this->container->get('http_kernel')->handle(Request::create("/group/$id/votes/setup"));
    $this->assertSame(403, $response->getStatusCode());
    $html = (string) $response->getContent();
    $this->assertStringContainsString(self::SENTENCE, self::text($html));
    $this->assertNotNull(self::first(self::dom($html), "//a[@href='/group/$id/votes']"), 'A link back to the Votes tab.');
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

    foreach (['public', 'community'] as $value) {
      $this->visibility($value);
      $this->assertTrue($this->mayOpen('safe_governance_group.setup', $this->ana), "$value: the organizer opens voting set-up.");
    }
  }

  /**
   * "Set up group voting" shows only where voting is allowed.
   *
   * The fund is ready and voting isn't set up. The organizer sees the button
   * in a public and a community group, and no link to voting set-up in a
   * members-only group. The card's text is the same in all three.
   */
  public function testSetUpButtonOnlyWhereVotingIsAllowed(): void {
    $id = $this->group->id();
    foreach (['public' => TRUE, 'community' => TRUE, 'members' => FALSE] as $value => $shown) {
      $this->visibility($value);
      $html = $this->renderBuild($this->votesController()->votes($this->group));
      $dom = self::dom($html);
      $this->assertStringContainsString('Choose how your group decides', self::text($html), $value);
      $button = self::first($dom, "//a[@href='/group/$id/votes/setup']");
      if ($shown) {
        $this->assertNotNull($button, "$value: the button shows.");
        $this->assertSame('Set up group voting', trim($button->textContent));
      }
      else {
        $this->assertSame(0, $dom->query("//a[contains(@href, '/votes/setup')]")->length, "$value: no link to voting set-up.");
      }
    }
  }

  /**
   * Every set-up, new-proposal and publish route refuses a members group.
   *
   * With the reason the sentence, so a refusal for another cause can't pass
   * as this one: the organizer on the set-up routes, a member who may
   * propose on the proposal routes. The new-proposal page says it in words.
   */
  public function testEveryVotingStartRouteIsRefusedForMembersGroup(): void {
    $this->proposerFixture();
    $this->visibility('members');
    $this->assertRefused(self::SETUP_ROUTES, $this->ana);
    $this->assertRefused(self::PROPOSAL_ROUTES, $this->jo);

    $this->setCurrentUser($this->jo);
    $id = $this->group->id();
    $response = $this->container->get('http_kernel')->handle(Request::create("/group/$id/votes/new"));
    $this->assertSame(403, $response->getStatusCode());
    $this->assertStringContainsString(self::SENTENCE, self::text((string) $response->getContent()));
  }

  /**
   * Public and community groups open every route for the same people.
   *
   * The positive control: the organizer opens the set-up routes and a
   * member who may propose opens the proposal routes, and the site's own
   * check answers allowed, not neutral.
   */
  public function testPublicAndCommunityGroupsStillOpenEveryRoute(): void {
    $this->proposerFixture();
    foreach (['public', 'community'] as $value) {
      $this->visibility($value);
      foreach (self::SETUP_ROUTES as $route) {
        $this->assertTrue($this->mayOpen($route, $this->ana), "$value: $route for the organizer.");
      }
      foreach (self::PROPOSAL_ROUTES as $route) {
        $this->assertTrue($this->mayOpen($route, $this->jo), "$value: $route for a member who may propose.");
      }
      $answer = $this->container->get('localnodes_web3.public_votes')->forGroupId($this->group->id());
      $this->assertInstanceOf(AccessResultInterface::class, $answer);
      $this->assertTrue($answer->isAllowed(), "$value: allowed, not neutral.");
    }
  }

  /**
   * Only the routes that start or publish a vote carry the check.
   *
   * A route a later release adds under those paths gets it too; a path that
   * only begins with the same letters does not.
   */
  public function testOnlyTheseRoutesCarryTheCheck(): void {
    $carrying = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if ($route->hasRequirement(self::REQUIREMENT)) {
        $carrying[] = $name;
      }
    }
    $expected = [...self::SETUP_ROUTES, ...self::PROPOSAL_ROUTES];
    sort($carrying);
    sort($expected);
    $this->assertSame($expected, $carrying);
    $this->assertEqualsCanonicalizing($expected, PublicVotes::ROUTES);
    foreach (['votes', 'proposal', 'proposal_recipient', 'vote_message', 'vote', 'carry_out_status', 'carry_out_retry', 'members', 'members_withdraw', 'members_autocomplete'] as $name) {
      $this->assertNotContains("safe_governance_group.$name", $carrying, $name);
    }
    $this->assertNotContains('group_treasury.public_notice', $carrying);

    $collection = new RouteCollection();
    $collection->add('later.new_other', new Route('/group/{group}/votes/new/other'));
    $collection->add('later.newsletter', new Route('/group/{group}/votes/newsletter'));
    $this->container->get('localnodes_web3.public_votes_routes')->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertSame('TRUE', $collection->get('later.new_other')?->getRequirement(self::REQUIREMENT), 'A new route under the new-proposal path is refused until listed.');
    $this->assertFalse($collection->get('later.newsletter')?->hasRequirement(self::REQUIREMENT), 'Another path is not.');
  }

  /**
   * The scripts behind set-up and publishing get the sentence as JSON.
   */
  public function testScriptRoutesAnswerTheSentenceInJson(): void {
    $this->proposerFixture();
    $this->visibility('members');
    $id = $this->group->id();
    foreach (["/group/$id/votes/setup/messages" => $this->ana, "/group/$id/votes/publish/message" => $this->jo] as $path => $who) {
      $this->setCurrentUser($who);
      $response = $this->container->get('http_kernel')->handle(Request::create($path, 'GET', ['_format' => 'json']));
      $this->assertSame(403, $response->getStatusCode(), $path);
      $this->assertSame(['error' => self::SENTENCE], json_decode((string) $response->getContent(), TRUE), $path);
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $path);
    }
  }

  /**
   * The sentence only for those who can see the group.
   *
   * The sentence is shown to people who can see the group; others get
   * Drupal's own refusal.
   */
  public function testSentenceOnlyForThoseWhoCanSeeTheGroup(): void {
    $this->visibility('members');
    $outsider = $this->createSiteUser('Zed', '0x' . str_repeat('7', 40));
    $this->assertFalse($this->group->access('view', $outsider));
    foreach ([[$outsider, FALSE], [$this->ana, TRUE]] as [$who, $told]) {
      $this->setCurrentUser($who);
      $event = $this->refusalEvent('safe_governance_group.setup', "/group/{$this->group->id()}/votes/setup");
      $this->container->get('localnodes_web3.public_votes_refusal')->onException($event);
      $this->assertSame($told, $event->getResponse() !== NULL, $who->getAccountName());
    }
  }

  /**
   * Which groups are refused: members-only and the legacy secret groups.
   */
  public function testWhichGroupsAreRefused(): void {
    foreach (['public' => FALSE, 'community' => FALSE, 'members' => TRUE] as $value => $refused) {
      $this->visibility($value);
      $this->assertSame($refused, PublicVotes::refuses($this->group), $value);
    }
    $this->group->set(self::VISIBILITY, NULL);
    $this->assertFalse(PublicVotes::refuses($this->group), 'An empty value.');

    $plain = $this->createGroup(['type' => $this->createGroupType(['id' => 'plain_group'])->id(), 'label' => 'Plain']);
    $this->assertFalse(PublicVotes::refuses($plain), 'A group type without the field.');
    $secret = $this->createGroup(['type' => $this->createGroupType(['id' => 'secret_group'])->id(), 'label' => 'Secret']);
    $this->assertTrue(PublicVotes::refuses($secret), 'A legacy secret group.');
  }

  /**
   * The voting module's queued steps wait for a members-only group, kept.
   *
   * Set-up records in each working status, queued while the group was
   * public, and a signed role batch made after it turned members-only: core
   * cron runs them, nothing is sent or published, nothing of the voting
   * module's is changed, each is marked held once, with one notice, and
   * the queues keep no item.
   */
  public function testQueuedStepsHeldForMembersGroup(): void {
    // One voting record per fund (the voting module's rule), so each set-up
    // record has a group and fund of its own.
    $records = [];
    $groups = [];
    foreach (SetupRunner::WORKING as $status) {
      [$groups[], $safe] = $this->fundedGroup('public');
      $records[] = $this->setupRecord($status, NULL, $safe);
    }
    $queue = $this->container->get('queue')->get('safe_governance_setup');
    foreach ($records as $record) {
      $queue->createItem(['governance' => (int) $record->id()]);
    }
    $this->member('Jo', self::JO);
    $ready = $this->readyRecord();
    $row = $this->row($ready, self::JO);
    foreach ($groups as $group) {
      $this->visibilityOf($group, 'members');
    }
    $this->visibility('members');
    $batch = $this->runner()->propose($ready, [(int) $row->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $this->confirmsOnChain();

    $before = array_map(fn(SafeGovernanceInterface $record): array => $this->recordState($record), $records);
    $posts = $this->container->get('state')->get(FixtureHubClient::POSTS_KEY);
    $sends = count($this->sponsorCalls());
    $worker_requests = $this->workerRequests;
    $logs = $this->recordLogs();

    $this->runCoreCron();

    $this->assertSame($worker_requests, $this->workerRequests, 'No outside request from the held steps.');
    $this->assertCount($sends, $this->sponsorCalls(), 'Nothing sent.');
    $this->assertSame($posts, $this->container->get('state')->get(FixtureHubClient::POSTS_KEY), 'Nothing published.');
    foreach ($records as $i => $record) {
      $this->assertSame($before[$i], $this->recordState($record), "The {$record->getStatus()} record is as it was.");
    }
    $kept = $this->reloadRow($row);
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $kept->getStatus());
    $this->assertSame((int) $batch->id(), $kept->getBatchId());
    $stored = $this->loadTransaction((int) $batch->id());
    $this->assertFalse($stored->isExecuted());
    $this->assertNotSame('cancelled', $stored->getStatus());
    $held = $this->heldSteps();
    foreach ($records as $record) {
      $this->assertArrayHasKey('safe_governance_setup:' . $record->id(), $held);
    }
    $this->assertArrayHasKey('safe_governance_membership:' . $ready->id(), $held);
    foreach (self::QUEUES as $name) {
      $this->assertSame(0, $this->container->get('queue')->get($name)->numberOfItems(), "$name keeps no item.");
    }
    $this->assertCount(5, $this->holdNotices($logs), 'One notice per held record.');

    // The voting module's cron queues them again: held, no second notice.
    $this->runCoreCron();
    $this->assertSame($worker_requests, $this->workerRequests);
    $this->assertCount($sends, $this->sponsorCalls());
    $this->assertCount(5, $this->holdNotices($logs), 'No second notice.');
  }

  /**
   * Public and community groups' queued steps run as before.
   */
  public function testQueuedStepsStillRunForPublicAndCommunity(): void {
    $this->member('Jo', self::JO);
    $this->member('Mo', self::MO);
    $ready = $this->readyRecord();
    $logs = $this->recordLogs();
    foreach (['public' => self::JO, 'community' => self::MO] as $value => $address) {
      $this->visibility($value);
      // A fresh record each time, on a fund of its own (one record per
      // fund): a step that stops the same way twice keeps one revision.
      [, $safe] = $this->fundedGroup($value);
      $record = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $safe);
      $this->container->get('queue')->get('safe_governance_setup')->createItem(['governance' => (int) $record->id()]);
      $before = $this->recordState($record);
      $worker_requests = $this->workerRequests;

      $row = $this->row($ready, $address);
      $batch = $this->runner()->propose($ready, [(int) $row->id()], (int) $this->ana->id());
      $this->approveAs(self::ANA, $batch);
      $this->approveAs(self::CY, $batch);
      $sends = count($this->sponsorCalls());

      $this->runCoreCron();
      $after = $this->recordState($record);
      $this->assertTrue($after !== $before || $this->workerRequests > $worker_requests, "$value: the set-up step ran.");
      $calls = array_slice($this->sponsorCalls(), $sends);
      $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), "$value: the role batch is sent.");
      $this->assertSame(strtolower($this->loadTransaction((int) $batch->id())->getData()), strtolower((string) $calls[0]['args'][2]['data']));

      // The batch lands, so the fund's next one can go.
      $this->confirmsOnChain();
      $this->wearers[HatId::toUint('5000.1')][] = strtolower($address);
      $this->safeNonce = (string) ((int) $this->safeNonce + 1);
      $this->runCoreCron();
      $this->assertSame('done', $this->reloadRow($row)->getStatus(), "$value: the role change is done.");
    }
    $this->assertSame([], $this->holdNotices($logs), 'Nothing was held.');
    $this->assertSame([], $this->heldSteps());
  }

  /**
   * Held steps are carried out once the group is public again.
   *
   * A hold, not a drop: the set-up step and the role batch wait while the
   * group is members-only and run at the next cron once it is public.
   */
  public function testHeldStepsCarriedOutOnceGroupIsPublicAgain(): void {
    // The set-up record has a group and fund of its own (one record per
    // fund); both groups turn members-only and then public together.
    [$setup_group, $setup_safe] = $this->fundedGroup('public');
    $record = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $setup_safe);
    $this->member('Jo', self::JO);
    $ready = $this->readyRecord();
    $row = $this->row($ready, self::JO);
    $this->visibilityOf($setup_group, 'members');
    $this->visibility('members');
    $batch = $this->runner()->propose($ready, [(int) $row->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $before = $this->recordState($record);
    $worker_requests = $this->workerRequests;
    $sends = count($this->sponsorCalls());

    $this->runCoreCron();
    $this->assertSame($worker_requests, $this->workerRequests, 'Nothing asked while held.');
    $this->assertCount($sends, $this->sponsorCalls(), 'Nothing sent while held.');
    $this->assertSame($before, $this->recordState($record));
    $this->assertArrayHasKey('safe_governance_setup:' . $record->id(), $this->heldSteps());
    $this->assertArrayHasKey('safe_governance_membership:' . $ready->id(), $this->heldSteps());

    $this->visibilityOf($setup_group, 'public');
    $this->visibility('public');
    $this->runCoreCron();
    $this->assertNotSame($before, $this->recordState($record), 'The set-up step ran.');
    $calls = array_slice($this->sponsorCalls(), $sends);
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'The role batch is sent.');
    $this->assertSame([], $this->heldSteps(), 'Neither is held any more.');
  }

  /**
   * Holds and releases of two funds' steps keep each other's marks.
   *
   * Each queued step's hold is marked under its own key: releasing one
   * group's step removes that mark only, and a step held again is marked
   * again with one notice.
   */
  public function testHeldStepMarksOfTwoFundsAreTheirOwn(): void {
    [$first, $first_safe] = $this->fundedGroup('public');
    $first_record = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $first_safe);
    [$second, $second_safe] = $this->fundedGroup('public');
    $second_record = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $second_safe);
    $logs = $this->recordLogs();
    $first_key = 'safe_governance_setup:' . $first_record->id();
    $second_key = 'safe_governance_setup:' . $second_record->id();

    $this->visibilityOf($first, 'members');
    $this->visibilityOf($second, 'members');
    $this->runCoreCron();
    $this->assertEqualsCanonicalizing([$first_key, $second_key], array_keys($this->heldSteps()));
    $this->assertCount(2, $this->holdNotices($logs));
    $second_since = $this->heldSteps()[$second_key];

    // The first group turns public: its mark goes, the second's stays.
    $this->visibilityOf($first, 'public');
    $this->runCoreCron();
    $this->assertSame([$second_key => $second_since], $this->heldSteps());
    $this->assertCount(2, $this->holdNotices($logs), 'The second fund is not told again.');

    // Then the other way round, at the same run.
    $this->visibilityOf($first, 'members');
    $this->visibilityOf($second, 'public');
    $this->runCoreCron();
    $this->assertSame([$first_key], array_keys($this->heldSteps()));
    $this->assertCount(3, $this->holdNotices($logs), 'The first fund is told once more, for its new hold.');
  }

  /**
   * A group can't turn members-only while a role change or set-up waits.
   *
   * A role batch whose fund payment is stored (draft) or sent (pending),
   * and a voting set-up whose payment is stored: setting the group to
   * members, from public or from community, gives the line at the
   * visibility choice, and the fund's lock is free again. With nothing
   * waiting, a lock another request holds gives the line after the wait;
   * a free lock gives no violation and stays taken until the save.
   */
  public function testMembersOnlyRefusedWhileChangeWaits(): void {
    $refused = [self::VISIBILITY . ': ' . self::LINE];
    [, , $batch] = $this->waitingBatch();
    foreach (['draft', 'pending'] as $status) {
      $this->setStatus($batch, $status);
      foreach (['public', 'community'] as $from) {
        $this->visibility($from);
        $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
        $this->assertSame($refused, self::violations($group), "Role batch $status, from $from: refused.");
        $this->assertLockFree($this->fundLock(), "Role batch $status, from $from: the lock is free again.");
      }
    }

    [$other, $other_safe] = $this->fundedGroup('public');
    $this->storedSetUp($other_safe, 'draft');
    foreach (['public', 'community'] as $from) {
      $this->visibilityOf($other, $from);
      $group = $this->groupNow($other)->set(self::VISIBILITY, 'members');
      $this->assertSame($refused, self::violations($group), "Set-up payment stored, from $from: refused.");
      $this->assertLockFree($this->fundLock($other_safe), "Set-up, from $from: the lock is free again.");
    }

    // Nothing waits: the lock decides.
    [$quiet, $quiet_safe] = $this->fundedGroup('public');
    $lock = $this->fundLock($quiet_safe);
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($lock, 60.0));
    $group = $this->groupNow($quiet)->set(self::VISIBILITY, 'members');
    $start = microtime(TRUE);
    $this->assertSame($refused, self::violations($group), 'Another request holds the lock: refused.');
    $this->assertGreaterThanOrEqual(4.0, microtime(TRUE) - $start, 'Refused after the wait.');
    $holder->release($lock);

    $this->assertSame([], self::violations($group), 'The lock is free and nothing waits.');
    $this->assertFalse($this->otherHolder()->acquire($lock, 60.0), 'Validation keeps the lock for the save.');
    $group->save();
    $this->assertSame('members', $this->groupNow($quiet)->get(self::VISIBILITY)->value);
    $this->assertLockFree($lock, 'Released once the save is done.');
  }

  /**
   * A payment stored after validation stops the save (the fresh re-check).
   *
   * Validation passed and took the fund's lock; then a payment is stored
   * directly: a set-up
   * payment through the voting module's prepare(), a role batch through
   * its propose(), and a batch sent (pending). The save is refused with
   * the line, the stored visibility is unchanged, and the lock is free.
   */
  public function testSaveRefusesMembersOnlyWhileChangeWaits(): void {
    $record = $this->approvingRecord();
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([], self::violations($group), 'Nothing waits yet.');
    $this->container->get('safe_governance.setup_approvals')->prepare($record, 'Garden Tools');
    $setup = $this->container->get('safe_governance.setup_approvals')->setupTransaction($record);
    $this->assertNotNull($setup);
    $this->assertSame('draft', $setup->getStatus());
    $this->assertSaveRefused($group, self::LINE);
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Set-up: still public.');
    $this->assertLockFree($this->fundLock(), 'Set-up: the lock is free after the refused save.');

    // The set-up payment is withdrawn and the record is made ready.
    $this->setStatus($setup, 'cancelled');
    $record = $this->makeReady($record);
    // The record has new members vote, so Jo joining adds his waiting row.
    $this->member('Jo', self::JO);
    $rows = $this->entityTypeManager->getStorage('governance_member_change')->loadByProperties([
      'governance' => $record->id(),
      'status' => GovernanceMemberChangeInterface::STATUS_WAITING,
      'address' => strtolower(self::JO),
    ]);
    $this->assertCount(1, $rows, 'Jo\'s change waits.');
    $row = reset($rows);
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([], self::violations($group), 'Nothing waits again.');
    $batch = $this->runner()->propose($record, [(int) $row->id()], (int) $this->ana->id());
    $this->assertSame('draft', $this->loadTransaction((int) $batch->id())->getStatus());
    $this->assertSaveRefused($group, self::LINE);
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Role batch: still public.');
    $this->assertLockFree($this->fundLock(), 'Role batch: the lock is free after the refused save.');

    // From community, a batch that turns pending after validation.
    $this->setStatus($batch, 'cancelled');
    $this->visibility('community');
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([], self::violations($group), 'The batch was withdrawn.');
    $this->setStatus($batch, 'pending');
    $this->assertSaveRefused($group, self::LINE);
    $this->assertSame('community', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Pending: still community.');
    $this->assertLockFree($this->fundLock(), 'Pending: the lock is free after the refused save.');
  }

  /**
   * Once nothing waits, the switch goes through; other saves are untouched.
   *
   * A role batch or set-up payment executed, cancelled or failed: the
   * switch validates and saves. A group with no fund, a new group created
   * members-only, public to community and back, and a members-only group
   * saved with another change are saved without validating, go through,
   * and leave the fund's lock free.
   */
  public function testMembersOnlyAllowedOnceNothingWaits(): void {
    [, , $batch] = $this->waitingBatch();
    foreach (['executed', 'cancelled', 'failed'] as $status) {
      $this->setStatus($batch, $status);
      $this->visibility('members');
      $this->assertSame('members', $this->reloadGroup()->get(self::VISIBILITY)->value, "Role batch $status: members.");
      $this->assertLockFree($this->fundLock(), "Role batch $status: the lock is free.");
      $this->visibility('public');
    }

    [$other, $other_safe] = $this->fundedGroup('public');
    $setup = $this->storedSetUp($other_safe, 'draft');
    foreach (['executed', 'cancelled', 'failed'] as $status) {
      $this->setStatus($setup, $status);
      $this->visibilityOf($other, 'members');
      $this->assertSame('members', $this->groupNow($other)->get(self::VISIBILITY)->value, "Set-up $status: members.");
      $this->assertLockFree($this->fundLock($other_safe), "Set-up $status: the lock is free.");
      $this->visibilityOf($other, 'public');
    }

    // Saved without validating.
    $plain = $this->createGroup(['type' => self::GROUP_TYPE, 'label' => 'Reading circle']);
    $plain->set(self::VISIBILITY, 'public')->save();
    $plain = $this->groupNow($plain)->set(self::VISIBILITY, 'members');
    $plain->save();
    $this->assertSame('members', $this->groupNow($plain)->get(self::VISIBILITY)->value, 'No fund: members.');

    $new = $this->createGroup(['type' => self::GROUP_TYPE, 'label' => 'Quiet room', self::VISIBILITY => 'members']);
    $this->assertSame('members', $this->groupNow($new)->get(self::VISIBILITY)->value, 'A new group: members.');

    foreach (['community', 'public'] as $value) {
      $this->reloadGroup()->set(self::VISIBILITY, $value)->save();
      $this->assertSame($value, $this->reloadGroup()->get(self::VISIBILITY)->value, "Funded group: $value.");
    }

    $this->visibility('members');
    $this->reloadGroup()->set('label', 'Garden tools and seeds')->save();
    $this->assertSame('Garden tools and seeds', $this->reloadGroup()->label(), 'A members-only group saved with another change.');
    $this->assertSame('members', $this->reloadGroup()->get(self::VISIBILITY)->value);
    $this->assertLockFree($this->fundLock(), 'The fund\'s lock is free.');
  }

  /**
   * A funded group turns members-only only through validation.
   *
   * (1) Saved without validating: refused, and the stored visibility is
   * unchanged.
   * (2) Inside an open database transaction validation refuses at once:
   * the lock is never taken there. (3) Validated, then saved: it goes
   * through and the lock is free afterwards.
   */
  public function testSwitchNeedsTheFundLock(): void {
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSaveRefused($group, self::NOT_LOCKED);
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Not saved without validation.');

    $transaction = $this->container->get('database')->startTransaction();
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $start = microtime(TRUE);
    $lines = self::violations($group);
    $elapsed = microtime(TRUE) - $start;
    $transaction->rollBack();
    unset($transaction);
    $this->assertSame([self::VISIBILITY . ': ' . self::LINE], $lines, 'Inside a transaction: refused.');
    $this->assertLessThan(2.0, $elapsed, 'At once: nothing is waited for.');
    $this->assertLockFree($this->fundLock(), 'Not taken inside the transaction.');

    $this->visibility('members');
    $this->assertSame('members', $this->reloadGroup()->get(self::VISIBILITY)->value);
    $this->assertLockFree($this->fundLock(), 'Released once the save is done.');
  }

  /**
   * The edit form says why a group can't turn members-only yet.
   *
   * With a waiting role batch, the line follows the visibility choice once;
   * with nothing waiting, or a group already members-only, it doesn't. The
   * form carries the group's cache tags.
   */
  public function testEditFormSaysWhyMembersOnlyWaits(): void {
    EntityFormDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => self::GROUP_TYPE,
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent(self::VISIBILITY, ['type' => 'options_buttons'])->save();
    [, , $batch] = $this->waitingBatch();

    $form = $this->editForm();
    $this->assertSame(1, self::countText($form, self::LINE), 'A change waits: the line once.');
    $this->assertStringContainsString(self::LINE, (string) ($form[self::VISIBILITY]['#suffix'] ?? ''), 'Right after the visibility choice.');
    $this->assertContains('group:' . $this->group->id(), $form['#cache']['tags'] ?? []);

    $this->setStatus($batch, 'executed');
    $form = $this->editForm();
    $this->assertSame(0, self::countText($form, self::LINE), 'Nothing waits: no line.');
    $this->assertContains('group:' . $this->group->id(), $form['#cache']['tags'] ?? []);

    $this->visibility('members');
    $this->setStatus($batch, 'draft');
    $form = $this->editForm();
    $this->assertSame(0, self::countText($form, self::LINE), 'Already members-only: no line.');
    $this->assertContains('group:' . $this->group->id(), $form['#cache']['tags'] ?? []);
  }

  /**
   * In a members-only group an approval is held on the site, not paid for.
   *
   * No fund payment, the fund's next number unchanged, the row still
   * waiting and now selected, the other row untouched, nothing deleted or
   * batched, one held entry naming the organizer; cron changes none of it.
   */
  public function testApprovalInMembersOnlyGroupIsHeld(): void {
    [$record, $jo, $mo] = $this->twoWaiting();
    $this->visibility('members');
    $count = $this->transactionCount();
    $nonce = $this->nextNonce();

    [, $state] = $this->submitApprove([(int) $jo->id() => ['select' => 1], (int) $mo->id() => ['select' => 0]]);
    $this->assertSame([], self::errors($state));
    foreach (['after the approval', 'after cron'] as $when) {
      $this->assertSame($count, $this->transactionCount(), "$when: no fund payment.");
      $this->assertSame($nonce, $this->nextNonce(), "$when: the fund's next number is unchanged.");
      $held = $this->reloadRow($jo);
      $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $held->getStatus(), $when);
      $this->assertTrue($held->isSelected(), "$when: the approved row is selected.");
      $other = $this->reloadRow($mo);
      $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $other->getStatus(), $when);
      $this->assertFalse($other->isSelected(), "$when: the other row is untouched.");
      $this->assertCount(2, $this->rowsOf($record), "$when: no row deleted.");
      $entries = $this->heldApprovals();
      $this->assertSame([$record->id() . ':' . $jo->id()], array_keys($entries), $when);
      $this->assertSame((int) $this->ana->id(), (int) $entries[$record->id() . ':' . $jo->id()]['uid'], "$when: it names the organizer.");
      if ($when === 'after the approval') {
        $this->runCoreCron();
      }
    }
  }

  /**
   * A held approval is batched once the group is public, then carried out.
   *
   * The same from members-only to community.
   */
  public function testHeldApprovalBatchedOnceGroupIsPublic(): void {
    [$record, $jo, $mo] = $this->twoWaiting();
    foreach (['public' => $jo, 'community' => $mo] as $to => $row) {
      $this->visibility('members');
      $nonce = $this->nextNonce();
      [, $state] = $this->submitApprove([(int) $row->id() => ['select' => 1]]);
      $this->assertSame([], self::errors($state));
      $this->assertCount(1, $this->heldApprovals(), "$to: held.");

      $this->visibility($to);
      $this->runCoreCron();
      $batched = $this->reloadRow($row);
      $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $batched->getStatus(), "$to: the held row is batched.");
      $batch = $this->loadTransaction((int) $batched->getBatchId());
      $this->assertSame('draft', $batch->getStatus());
      $this->assertSame((string) $nonce, (string) $batch->get('nonce')->value, "$to: at the fund's next number.");
      $this->assertSame((int) $this->ana->id(), (int) $batch->get('created_by')->target_id, "$to: proposed by the organizer who approved.");
      $this->assertSame([], $this->heldApprovals(), "$to: the entry is gone.");
      $others = array_filter($this->rowsOf($record), fn($r) => (int) $r->id() !== (int) $row->id() && $r->getStatus() === GovernanceMemberChangeInterface::STATUS_BATCHED);
      $this->assertSame([], $others, "$to: exactly the held row.");

      $this->completeBatch($batch, $to === 'public' ? self::JO : self::MO);
    }
  }

  /**
   * In public and community groups the approve button stores the batch.
   */
  public function testApprovalInPublicGroupUnchanged(): void {
    [, $jo, $mo] = $this->twoWaiting();
    foreach (['public' => $jo, 'community' => $mo] as $value => $row) {
      $this->visibility($value);
      $count = $this->transactionCount();
      [, $state] = $this->submitApprove([(int) $row->id() => ['select' => 1]]);
      $this->assertSame([], self::errors($state));
      $this->assertSame($count + 1, $this->transactionCount(), "$value: the batch is stored at once.");
      $batched = $this->reloadRow($row);
      $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $batched->getStatus());
      $this->assertSame([], $this->heldApprovals(), "$value: nothing held.");
      $this->completeBatch($this->loadTransaction((int) $batched->getBatchId()), $value === 'public' ? self::JO : self::MO);
    }
  }

  /**
   * Approval and the switch, in both orders, and a busy lock.
   */
  public function testApprovalAndSwitchInBothOrders(): void {
    [, $jo, $mo, $bo] = $this->twoWaiting(TRUE);

    // (a) Approval first: stored, then the switch is refused.
    $nonce = $this->nextNonce();
    $this->submitApprove([(int) $jo->id() => ['select' => 1]]);
    $batch = $this->loadTransaction((int) $this->reloadRow($jo)->getBatchId());
    $this->assertSame('draft', $batch->getStatus());
    $this->assertSame((string) $nonce, (string) $batch->get('nonce')->value);
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([self::VISIBILITY . ': ' . self::LINE], self::violations($group), '(a) the switch is refused.');
    $this->assertSaveRefused($this->reloadGroup()->set(self::VISIBILITY, 'members'), self::NOT_LOCKED);
    $this->completeBatch($batch, self::JO);

    // (b) Switch first, on a page built while the group was public.
    $count = $this->transactionCount();
    $this->submitApprove([(int) $mo->id() => ['select' => 1]], function (): void {
      $this->visibility('members');
    });
    $this->assertSame($count, $this->transactionCount(), '(b) no fund payment.');
    $held = $this->reloadRow($mo);
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $held->getStatus());
    $this->assertTrue($held->isSelected());
    $this->assertCount(1, $this->heldApprovals(), '(b) one entry held.');

    // (c) Busy, in a public and then a members-only group.
    foreach (['public', 'members'] as $value) {
      $this->visibility($value);
      $holder = $this->otherHolder();
      $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
      $count = $this->transactionCount();
      $entries = $this->heldApprovals();
      $this->container->get('messenger')->deleteAll();
      $this->submitApprove([(int) $bo->id() => ['select' => 1]]);
      $holder->release($this->fundLock());
      $this->assertSame($count, $this->transactionCount(), "(c) $value: nothing stored.");
      $this->assertSame($entries, $this->heldApprovals(), "(c) $value: nothing held.");
      $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $this->reloadRow($bo)->getStatus());
      $errors = array_map('strval', $this->container->get('messenger')->messagesByType('error'));
      $this->assertSame([self::APPROVE_BUSY], $errors, "(c) $value: the voting module's own words.");
    }
  }

  /**
   * Held approvals are kept one per row; the first approval stands.
   */
  public function testHeldApprovalsKeptOnePerRow(): void {
    // Cy becomes an organizer before the record is made, so joining adds
    // no row of his own.
    $this->group->addMember($this->cy, ['group_roles' => [self::GROUP_TYPE . '-admin']]);
    [$record, $jo, $mo] = $this->twoWaiting(TRUE);
    [, $other_safe] = $this->fundedGroup('public');
    $other = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $other_safe);
    $other_key = $other->id() . ':999';
    $other_entry = ['record' => (int) $other->id(), 'row' => 999, 'uid' => (int) $this->cy->id(), 'at' => 1.0];
    $this->container->get('keyvalue')->get(self::HELD_APPROVALS)->set($other_key, $other_entry);
    $this->visibility('members');

    $this->submitApprove([(int) $jo->id() => ['select' => 1]]);
    $this->setCurrentUser($this->cy);
    $this->submitApprove([(int) $mo->id() => ['select' => 1]]);
    $this->submitApprove([(int) $jo->id() => ['select' => 1]]);
    $this->setCurrentUser($this->ana);
    $jo_key = $record->id() . ':' . $jo->id();
    $mo_key = $record->id() . ':' . $mo->id();
    $entries = $this->heldApprovals();
    $this->assertEqualsCanonicalizing([$jo_key, $mo_key, $other_key], array_keys($entries), 'Two entries, and the other record\'s.');
    $this->assertSame((int) $this->ana->id(), (int) $entries[$jo_key]['uid'], 'The first approval stands.');
    $this->assertSame((int) $this->cy->id(), (int) $entries[$mo_key]['uid']);
    $this->assertSame($other_entry, $entries[$other_key], 'The other record\'s entry is untouched.');

    $this->visibility('public');
    $this->runCoreCron();
    $batch_id = $this->reloadRow($jo)->getBatchId();
    $this->assertNotNull($batch_id);
    $this->assertSame($batch_id, $this->reloadRow($mo)->getBatchId(), 'One batch for both.');
    $this->assertSame((int) $this->ana->id(), (int) $this->loadTransaction((int) $batch_id)->get('created_by')->target_id, 'Proposed by the earliest approver.');
    $this->assertSame([$other_key => $other_entry], $this->heldApprovals(), 'Exactly those two removed.');
  }

  /**
   * The release and the switch, in both orders, and a busy lock.
   */
  public function testReleaseAndSwitchInBothOrders(): void {
    [, $jo, $mo] = $this->twoWaiting();

    // (a) Release first: batched, then the switch is refused.
    $this->visibility('members');
    $this->submitApprove([(int) $jo->id() => ['select' => 1]]);
    $this->visibility('public');
    $this->runCoreCron();
    $batched = $this->reloadRow($jo);
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $batched->getStatus(), '(a) released.');
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([self::VISIBILITY . ': ' . self::LINE], self::violations($group), '(a) the switch is refused.');
    $this->assertSaveRefused($this->reloadGroup()->set(self::VISIBILITY, 'members'), self::NOT_LOCKED);
    $this->completeBatch($this->loadTransaction((int) $batched->getBatchId()), self::JO);

    // (b) Switch first: public, then members again before cron.
    $this->visibility('members');
    $this->submitApprove([(int) $mo->id() => ['select' => 1]]);
    $this->visibility('public');
    $this->visibility('members');
    $count = $this->transactionCount();
    $this->runCoreCron();
    $this->assertSame($count, $this->transactionCount(), '(b) nothing batched.');
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $this->reloadRow($mo)->getStatus());
    $this->assertCount(1, $this->heldApprovals(), '(b) the entry is kept.');

    // (c) Busy: public, but another request holds the fund's lock.
    $this->visibility('public');
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
    $this->runCoreCron();
    $this->assertSame($count, $this->transactionCount(), '(c) nothing batched while busy.');
    $this->assertCount(1, $this->heldApprovals(), '(c) the entry is kept.');
    $holder->release($this->fundLock());
    $this->runCoreCron();
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $this->reloadRow($mo)->getStatus(), '(c) batched once free.');
    $this->assertSame([], $this->heldApprovals());
  }

  /**
   * The set-up's approval routes run under the fund's lock, fresh.
   *
   * In the order: (b) the switch first, after the route's check; (c) a busy
   * lock; (a) the set-up first, then the switch.
   */
  public function testSetUpApprovalAndSwitchInBothOrders(): void {
    $provider = $this->container->get('router.route_provider');
    foreach (self::APPROVAL_ROUTES as $name => $method) {
      $route = $provider->getRouteByName($name);
      $this->assertSame(self::SETUP_LOCK . $method, $route->getDefault('_controller'), "$name: behind the site's controller.");
      $this->assertSame(self::SETUP_INNER . $method, $route->getDefault('_localnodes_web3_inner'), "$name: the voting module's controller kept.");
    }
    $record = $this->approvingRecord();
    $this->setCurrentUser($this->ana);
    $id = $this->group->id();

    // (b) The route's check ran while the group was public; then it was
    // saved as members-only; then the controller the router names runs.
    $stale = $this->reloadGroup();
    $this->visibility('members');
    $count = $this->transactionCount();
    foreach (self::APPROVAL_ROUTES as $name => $method) {
      $response = $this->routeController($name)($stale, $this->setupRequest("/group/$id/votes/setup/$method", $method === 'messages' ? 'GET' : 'POST'));
      $this->assertSame(403, $response->getStatusCode(), "(b) $name");
      $this->assertSame(['error' => self::SENTENCE], json_decode((string) $response->getContent(), TRUE), "(b) $name");
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "(b) $name");
    }
    $this->assertSame($count, $this->transactionCount(), '(b) nothing stored.');
    $this->assertLockFree($this->fundLock());

    // (c) Busy: another request holds the fund's lock.
    $this->visibility('public');
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
    $busy = $this->routeController('safe_governance_group.setup_messages')($this->reloadGroup(), $this->setupRequest("/group/$id/votes/setup/messages", 'GET'));
    $this->assertSame(503, $busy->getStatusCode());
    $this->assertSame(['error' => "We couldn't prepare the approvals right now.", 'code' => 'busy'], json_decode((string) $busy->getContent(), TRUE));
    $this->assertNotStored($busy, '(c) messages busy');
    $busy = $this->routeController('safe_governance_group.setup_approve')($this->reloadGroup(), $this->setupRequest("/group/$id/votes/setup/approve", 'POST'));
    $this->assertSame(503, $busy->getStatusCode());
    $this->assertSame(['error' => "We couldn't check this approval right now."], json_decode((string) $busy->getContent(), TRUE));
    $this->assertNotStored($busy, '(c) approve busy');
    $this->assertSame($count, $this->transactionCount(), '(c) nothing stored while busy.');
    $holder->release($this->fundLock());

    // (a) The set-up first: the controller the router names, called with
    // the request (the site's signature-proof check, a separate gate, sits
    // in front of the route), stores the payment as the voting module stores it; then
    // the switch is refused.
    $response = $this->routeController('safe_governance_group.setup_messages')($this->reloadGroup(), $this->setupRequest("/group/$id/votes/setup/messages", 'GET'));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertArrayHasKey('messages', json_decode((string) $response->getContent(), TRUE));
    $this->assertSame($count + 1, $this->transactionCount(), '(a) the set-up payment is stored.');
    $records = $this->entityTypeManager->getStorage('safe_governance');
    $records->resetCache([$record->id()]);
    $setup = $this->container->get('safe_governance.setup_approvals')->setupTransaction($records->load($record->id()));
    $this->assertNotNull($setup);
    $this->assertSame('draft', $setup->getStatus());
    $this->assertLockFree($this->fundLock());
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([self::VISIBILITY . ': ' . self::LINE], self::violations($group), '(a) the switch is refused.');
    $this->assertSaveRefused($this->reloadGroup()->set(self::VISIBILITY, 'members'), self::NOT_LOCKED);
  }

  /**
   * A queued step waits while another request holds the fund's lock.
   */
  public function testQueuedStepWaitsForTheFundLock(): void {
    [, $safe] = $this->fundedGroup('public');
    $record = $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $safe);
    $queue = $this->container->get('queue')->get('safe_governance_setup');
    $queue->createItem(['governance' => (int) $record->id()]);
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock($safe), 60.0));
    $before = $this->recordState($record);
    $worker_requests = $this->workerRequests;
    $logs = $this->recordLogs();

    $this->runCoreCron();
    $this->assertSame($worker_requests, $this->workerRequests, 'Nothing asked while the lock is busy.');
    $this->assertSame($before, $this->recordState($record), 'The step did not run.');
    $this->assertArrayNotHasKey('safe_governance_setup:' . $record->id(), $this->heldSteps(), 'It waited; it was not held.');
    $this->assertSame([], $this->holdNotices($logs), 'Nothing logged as held.');
    $this->assertSame(0, $queue->numberOfItems(), 'The queue keeps no item.');

    $holder->release($this->fundLock($safe));
    $this->runCoreCron();
    $this->assertTrue($this->recordState($record) !== $before || $this->workerRequests > $worker_requests, 'The step ran once the lock was free.');
  }

  /**
   * A role batch of a members-only group is held.
   *
   * The batch is held, and the
   * fund's later payments wait behind it, stored and sent alike. The ways
   * out: the group made public again, and Withdraw These Changes.
   */
  public function testEarlierBatchInMembersOnlyGroup(): void {
    [$record, $jo, $mo] = $this->twoWaiting();
    $this->visibility('members');

    // As the voting module's approve button stores it.
    $batch = $this->runner()->propose($record, [(int) $jo->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $payment = NULL;
    foreach (['stored' => '', 'sent' => '0x' . str_repeat('ab', 32)] as $case => $hash) {
      $this->loadTransaction((int) $batch->id())->set('blockchain_tx_hash', $hash)->save();
      $worker_requests = $this->workerRequests;
      $sends = count($this->sponsorCalls());
      $this->runCoreCron();
      $this->assertSame($worker_requests, $this->workerRequests, "$case: held, nothing asked.");
      $this->assertSame([], array_column(array_slice($this->sponsorCalls(), $sends), 'intent'), "$case: not sent.");
      if ($payment === NULL) {
        $this->assertSame('pending', $this->loadTransaction((int) $batch->id())->getStatus(), 'Signed to the threshold, unsent.');
        // Signed by both approvers; behind the batch it can't turn pending.
        $payment = $this->proposePayment();
        $this->approveAs(self::ANA, $payment);
        $this->approveAs(self::CY, $payment);
        $this->assertSame('draft', $this->loadTransaction((int) $payment->id())->getStatus(), 'Not next in line.');
      }
      $state = $this->paymentState($payment);
      $this->assertFalse($state['next_in_line'], "$case: the later payment waits.");
      $this->assertSame((int) $batch->id(), (int) $state['blocking_id'], "$case: behind the batch.");
    }
    // The fixture's batch goes back to unsent, as the stored case.
    $this->loadTransaction((int) $batch->id())->set('blockchain_tx_hash', '')->save();

    // Way out 1: the group made public again.
    $this->visibility('public');
    $sends = count($this->sponsorCalls());
    $this->runCoreCron();
    $calls = array_slice($this->sponsorCalls(), $sends);
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'public: the batch is sent once.');
    $this->assertSame(strtolower($this->loadTransaction((int) $batch->id())->getData()), strtolower((string) $calls[0]['args'][2]['data']));
    $this->confirmsOnChain();
    $this->wearers[HatId::toUint('5000.1')][] = strtolower(self::JO);
    $this->safeNonce = (string) ((int) $this->safeNonce + 1);
    $this->runCoreCron();
    $this->assertSame('executed', $this->loadTransaction((int) $batch->id())->getStatus());
    $this->assertSame('done', $this->reloadRow($jo)->getStatus());
    $this->assertNextInLine($payment);
    $this->sendSponsored($payment);
    $this->safeNonce = (string) ((int) $this->safeNonce + 1);

    // Way out 2: Withdraw These Changes, on a second stored batch.
    $this->visibility('members');
    $second = $this->runner()->propose($record, [(int) $mo->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $second);
    $this->approveAs(self::CY, $second);
    $later = $this->proposePayment();
    $this->approveAs(self::ANA, $later);
    $this->approveAs(self::CY, $later);
    $state = $this->paymentState($later);
    $this->assertFalse($state['next_in_line'], 'withdraw: the later payment waits first.');
    $this->assertSame((int) $second->id(), (int) $state['blocking_id']);
    $count = $this->transactionCount();
    // As WithdrawChangesForm calls it.
    $this->runner()->withdraw($this->governanceRecord(), (int) $this->ana->id());
    // Seen: the batch is cancelled and its row waits again; the voting module stores
    // a filler at the batch's number, and the later payment waits behind
    // it until the filler is approved and sent.
    $this->assertSame('cancelled', $this->loadTransaction((int) $second->id())->getStatus(), 'withdraw: the batch is cancelled.');
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_WAITING, $this->reloadRow($mo)->getStatus(), 'withdraw: the row waits again.');
    $this->assertSame($count + 1, $this->transactionCount(), 'withdraw: one filler is stored.');
    $ids = $this->entityTypeManager->getStorage('safe_transaction')->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
    $filler = $this->loadTransaction((int) reset($ids));
    $this->assertSame((string) $this->loadTransaction((int) $second->id())->get('nonce')->value, (string) $filler->get('nonce')->value, 'withdraw: the filler takes the batch\'s number.');
    $state = $this->paymentState($later);
    $this->assertFalse($state['next_in_line'], 'withdraw: the later payment still waits.');
    $this->assertSame((int) $filler->id(), (int) $state['blocking_id'], 'withdraw: behind the filler.');
  }

  /**
   * A held step is sent once the group is public again.
   *
   * The funded group is members-only, a
   * role batch stored and signed before it is held, and a later ordinary
   * payment waits behind it. Nothing is sent while the group is
   * members-only. An organizer makes the group public through the
   * validated path: the batch is sent once and carried out, and the later
   * payment is next in line and is sent.
   */
  public function testHeldStepIsSentOnceTheGroupIsPublicAgain(): void {
    [$record, $jo] = $this->twoWaiting();
    $this->visibility('members');
    $batch = $this->runner()->propose($record, [(int) $jo->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $payment = $this->proposePayment();
    $this->approveAs(self::ANA, $payment);
    $this->approveAs(self::CY, $payment);
    $state = $this->paymentState($payment);
    $this->assertFalse($state['next_in_line'], 'The later payment waits.');
    $this->assertSame((int) $batch->id(), (int) $state['blocking_id'], 'Behind the batch.');

    $hub = $this->hubPosts();
    $worker_requests = $this->workerRequests;
    $sends = count($this->sponsorCalls());
    $this->runCoreCron();
    $this->assertSame([], array_slice($this->sponsorCalls(), $sends), 'Members-only: nothing is sent.');
    $this->assertSame($worker_requests, $this->workerRequests, 'Members-only: nothing is asked.');
    $this->assertSame($hub, $this->hubPosts(), 'Members-only: nothing is published.');
    $this->assertSame('', (string) $this->loadTransaction((int) $batch->id())->get('blockchain_tx_hash')->value, 'Members-only: the batch is unsent.');
    $this->assertSame(GovernanceMemberChangeInterface::STATUS_BATCHED, $this->reloadRow($jo)->getStatus(), 'Members-only: the role change waits in its batch.');

    // An organizer makes the group public again, validated.
    $this->visibility('public');
    $sends = count($this->sponsorCalls());
    $this->runCoreCron();
    $calls = array_slice($this->sponsorCalls(), $sends);
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'Public: the batch is sent once.');
    $this->assertSame(strtolower($this->loadTransaction((int) $batch->id())->getData()), strtolower((string) $calls[0]['args'][2]['data']), 'Public: the batch is what is sent.');
    $this->confirmsOnChain();
    $this->wearers[HatId::toUint('5000.1')][] = strtolower(self::JO);
    $this->safeNonce = (string) ((int) $this->safeNonce + 1);
    $this->runCoreCron();
    $this->assertSame('executed', $this->loadTransaction((int) $batch->id())->getStatus(), 'Public: the batch is carried out.');
    $this->assertSame('done', $this->reloadRow($jo)->getStatus(), 'Public: the role change is done.');
    $this->assertNextInLine($payment);
    $sends = count($this->sponsorCalls());
    $this->sendSponsored($payment);
    $this->assertSame(['sponsorSafeExecution'], array_column(array_slice($this->sponsorCalls(), $sends), 'intent'), 'Public: the later payment is sent.');
  }

  /**
   * A held role batch is not sent through the fund's payment API.
   *
   * The execute route of a fund payment sits behind the site's controller.
   * In a members-only group a signed role batch is refused there, asking
   * first or sent by the sponsor, and nothing is sent; an ordinary payment
   * of the same fund is sent as before, and the same batch is sent once the
   * group is public again.
   */
  public function testHeldBatchIsNotSentThroughTheFundApi(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('safe_smart_accounts.api_transaction_execute');
    $this->assertSame('Drupal\\localnodes_web3\\Controller\\FundSendLock::execute', ltrim((string) $route->getDefault('_controller'), '\\'), 'Behind the site\'s controller.');

    [$record, $jo] = $this->twoWaiting();
    $this->visibility('members');
    $payment = $this->proposePayment();
    $this->approvePayment($payment);
    $batch = $this->runner()->propose($record, [(int) $jo->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);

    // An ordinary payment of the same fund is sent.
    $sends = count($this->sponsorCalls());
    $response = $this->routeSend($payment, ['sponsored' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['sponsorSafeExecution'], array_column(array_slice($this->sponsorCalls(), $sends), 'intent'), 'The ordinary payment is sent.');
    $this->safeNonce = (string) ((int) $this->safeNonce + 1);
    $this->runCoreCron();
    $this->assertSame('executed', $this->loadTransaction((int) $payment->id())->getStatus());
    $this->assertNextInLine($batch);

    // The held batch: refused, nothing sent.
    $sends = count($this->sponsorCalls());
    foreach (['check' => ['check' => TRUE], 'sponsored' => ['sponsored' => TRUE]] as $case => $body) {
      $response = $this->routeSend($batch, $body);
      $this->assertSame(403, $response->getStatusCode(), "$case: refused. " . $response->getContent());
      $this->assertSame(['error' => self::SENTENCE], json_decode((string) $response->getContent(), TRUE), "$case: the sentence.");
      $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "$case: never cached.");
    }
    $this->assertSame([], array_slice($this->sponsorCalls(), $sends), 'Members-only: the batch is not sent.');
    $this->assertSame('', (string) $this->loadTransaction((int) $batch->id())->get('blockchain_tx_hash')->value, 'Members-only: the batch is unsent.');
    $this->assertLockFree($this->fundLock());

    // Public again: the same batch is sent, once.
    $this->visibility('public');
    $response = $this->routeSend($batch, ['sponsored' => TRUE]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $calls = array_slice($this->sponsorCalls(), $sends);
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'Public: the batch is sent once.');
    $this->assertSame(strtolower($this->loadTransaction((int) $batch->id())->getData()), strtolower((string) $calls[0]['args'][2]['data']));
    $this->assertLockFree($this->fundLock());
  }

  /**
   * A public group whose fund is shared with a members-only group.
   *
   * Two groups share one fund: this public group and a members-only one,
   * each linked through group_treasury's addTreasury(), the call its create
   * and link paths end in. Such a fund neither sets up voting nor publishes
   * a vote for the public group.
   * (a) The set-up's approval routes, as an organizer's browser calls them.
   * (b) The publish route, as a member of both groups posts a signed
   * proposal: the sentence.
   * Both answers are collected first, so a failure shows each.
   */
  public function testPublicGroupSharingAFundWithAMembersOnlyGroup(): void {
    $treasury = $this->container->get('group_treasury.treasury_service');
    $other = $this->createGroup(['type' => self::GROUP_TYPE, 'label' => 'Seed circle']);
    $other->addMember($this->ana, ['group_roles' => [self::GROUP_TYPE . '-admin']]);
    $other->set(self::VISIBILITY, 'public')->save();
    $treasury->addTreasury($other, $this->safe);
    $this->visibilityOf($other, 'members');
    $this->assertSame('members', $this->groupNow($other)->get(self::VISIBILITY)->value, 'The other group is members-only.');
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'This group is public.');
    $shared = array_map(static fn ($group): int => (int) $group->id(), $treasury->getGroupsForTreasury($this->safe));
    sort($shared);
    $this->assertSame([(int) $this->group->id(), (int) $other->id()], $shared, 'One fund, both groups.');

    $seen = [];
    $expected = [];
    // (a) Voting set-up for the public group.
    $record = $this->approvingRecord();
    $this->setCurrentUser($this->ana);
    $id = $this->group->id();
    $count = $this->transactionCount();
    foreach (self::APPROVAL_ROUTES as $name => $method) {
      $response = $this->routeController($name)($this->reloadGroup(), $this->setupRequest("/group/$id/votes/setup/$method", $method === 'messages' ? 'GET' : 'POST'));
      $seen["(a) set-up $method"] = $response->getStatusCode() . ' ' . $response->getContent();
      $expected["(a) set-up $method"] = '403 ' . json_encode(['error' => self::SENTENCE]);
    }
    $seen['(a) set-up stores no payment'] = $this->transactionCount() === $count;
    $expected['(a) set-up stores no payment'] = TRUE;

    // (b) Publishing a vote for the public group, its voting ready.
    $record->delete();
    [$draft, $body] = $this->signedDraft();
    $other->addMember($this->jo);
    $this->assertTrue($this->groupNow($other)->access('view', $this->jo), 'Jo can see the other group.');
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $seen['(b) publish'] = $response->getStatusCode() . ' ' . $response->getContent();
    $expected['(b) publish'] = '403 ' . json_encode(['error' => self::SENTENCE]);
    $seen['(b) nothing reaches the voting service'] = $this->hubPosts() === [];
    $expected['(b) nothing reaches the voting service'] = TRUE;

    $this->assertSame($expected, $seen, 'A fund shared with a members-only group neither sets up nor publishes for the public group.');
  }

  /**
   * A vote is not published from a fund a members-only group also uses.
   *
   * The sentence is shown to people who can see a members-only group of the
   * fund; a member of the public group only gets the publish route's own
   * answer when it can't publish now, the same as a busy fund. Nothing
   * reaches the voting service, and no answer is stored.
   */
  public function testPublishFromAFundSharedWithAGroupThePublisherCannotSee(): void {
    $other = $this->sharedFundGroup('members');
    [$draft, $body] = $this->signedDraft();
    $this->assertFalse($this->groupNow($other)->access('view', $this->jo), 'Jo cannot see the other group.');
    $this->assertTrue($this->groupNow($this->group)->access('view', $this->jo), 'Jo can see this group.');

    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $this->assertSame(503, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['error' => 'unavailable'], json_decode((string) $response->getContent(), TRUE), 'The publish route\'s own answer.');
    $this->assertStringNotContainsString(self::SENTENCE, (string) $response->getContent(), 'No group sentence.');
    $this->assertNotStored($response, 'refused');
    $this->assertSame([], $this->hubPosts(), 'Nothing reaches the voting service.');

    // The fund's lock busy: the same answer.
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
    $busy = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $holder->release($this->fundLock());
    $this->assertSame($busy->getStatusCode(), $response->getStatusCode(), 'Busy: the same status.');
    $this->assertSame((string) $busy->getContent(), (string) $response->getContent(), 'Busy: the same body.');
    $this->assertNotStored($busy, 'busy');
    $this->assertSame([], $this->hubPosts(), 'Nothing reaches the voting service.');
  }

  /**
   * A public group whose fund is shared only with public groups publishes.
   */
  public function testPublishFromAFundSharedOnlyWithPublicGroups(): void {
    $this->sharedFundGroup('public');
    [$draft, $body] = $this->signedDraft();
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertCount(1, $this->hubPosts(), 'The vote reaches the voting service.');
  }

  /**
   * Another group, linked to this group's fund, with a visibility.
   */
  private function sharedFundGroup(string $visibility): GroupInterface {
    $treasury = $this->container->get('group_treasury.treasury_service');
    $other = $this->createGroup(['type' => self::GROUP_TYPE, 'label' => 'Seed circle']);
    $other->addMember($this->ana, ['group_roles' => [self::GROUP_TYPE . '-admin']]);
    $other->set(self::VISIBILITY, 'public')->save();
    $treasury->addTreasury($other, $this->safe);
    if ($visibility !== 'public') {
      $this->visibilityOf($other, $visibility);
    }
    $this->assertSame($visibility, $this->groupNow($other)->get(self::VISIBILITY)->value);
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'This group is public.');
    $this->assertCount(2, $treasury->getGroupsForTreasury($this->safe), 'One fund, both groups.');
    return $other;
  }

  /**
   * A held batch's refusal: the sentence for those who can see the group.
   *
   * An approver of the fund who is not a member of the members-only group
   * asks whether the held role batch may be sent: the answer is the payment
   * module's own refusal of a check, the same body the busy fund lock
   * gives, with no group sentence. A member asking the same is told the
   * sentence. Nothing is sent either way, and every refusal and busy answer
   * is never stored.
   */
  public function testHeldBatchRefusalSentenceIsForThoseWhoCanSeeTheGroup(): void {
    [$record, $jo] = $this->twoWaiting();
    $this->visibility('members');
    $batch = $this->runner()->propose($record, [(int) $jo->id()], (int) $this->ana->id());
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $this->assertNextInLine($batch);
    $sends = count($this->sponsorCalls());

    $generic = ['error' => self::NONCE_BUSY];
    $outsider = $this->createSiteUser('Zed', '0x' . str_repeat('7', 40));
    $this->assertFalse($this->group->access('view', $outsider), 'Zed cannot see the group.');
    $this->setCurrentUser($outsider);
    foreach (['check' => ['check' => TRUE], 'sponsored' => ['sponsored' => TRUE]] as $case => $body) {
      $response = $this->routeSend($batch, $body);
      $this->assertSame(409, $response->getStatusCode(), "Zed, $case: the generic refusal. " . $response->getContent());
      $this->assertSame($generic, json_decode((string) $response->getContent(), TRUE), "Zed, $case: the same body as any other refused check.");
      $this->assertStringNotContainsString(self::SENTENCE, (string) $response->getContent(), "Zed, $case: no group sentence.");
      $this->assertNotStored($response, "Zed, $case");
    }

    $this->assertTrue($this->group->access('view', $this->ana), 'Ana can see the group.');
    $this->setCurrentUser($this->ana);
    $response = $this->routeSend($batch, ['check' => TRUE]);
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['error' => self::SENTENCE], json_decode((string) $response->getContent(), TRUE), 'A member: the sentence.');
    $this->assertNotStored($response, 'member');

    // The fund's lock busy: the same body, the payment module's own refusal
    // of a check.
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
    $busy = $this->routeSend($batch, ['check' => TRUE]);
    $holder->release($this->fundLock());
    $this->assertSame(409, $busy->getStatusCode(), (string) $busy->getContent());
    $this->assertSame($generic, json_decode((string) $busy->getContent(), TRUE), 'Busy: the same body.');
    $this->assertNotStored($busy, 'busy');

    $this->assertSame([], array_slice($this->sponsorCalls(), $sends), 'Nothing is sent.');
    $this->assertSame('', (string) $this->loadTransaction((int) $batch->id())->get('blockchain_tx_hash')->value, 'The batch is unsent.');
    $this->assertLockFree($this->fundLock());
  }

  /**
   * Validation's hold on the fund's lock survives a nested publish.
   *
   * In one request: the group is validated as members-only (the fund's
   * lock is taken for the save), a vote is published while the stored
   * group is still public (the publish takes and gives back the lock),
   * then the group is saved. The publish gives back only its own hold: the
   * lock stays taken until the save, the save goes through, and no lock is
   * left afterwards.
   */
  public function testValidationLockSurvivesNestedPublish(): void {
    [$draft, $body] = $this->signedDraft();
    $this->setCurrentUser($this->ana);
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    $this->assertSame([], self::violations($group), 'Validated.');
    $this->setCurrentUser($this->jo);
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([$body], $this->hubPosts(), 'Published while the stored group is public.');
    $holder = $this->otherHolder();
    $this->assertFalse($holder->acquire($this->fundLock(), 60.0), 'The publish kept validation\'s hold on the lock.');
    $this->setCurrentUser($this->ana);
    $group->save();
    $this->assertSame('members', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Saved.');
    $this->assertLockFree($this->fundLock(), 'No lock is left.');
    $rows = $this->container->get('database')->select('semaphore', 's')->condition('name', $this->fundLock())->countQuery()->execute()->fetchField();
    $this->assertSame(0, (int) $rows, 'No lock row is left.');
  }

  /**
   * A failing voting worker gives the fund's lock back.
   *
   * The queue hold calls the voting module's worker under the fund's lock;
   * when that worker throws, the error goes on and the lock is free.
   */
  public function testDelegateExceptionGivesTheLockBack(): void {
    $record = $this->readyRecord();
    $inner = $this->createMock(QueueWorkerInterface::class);
    $inner->method('processItem')->willThrowException(new \RuntimeException('The voting worker failed.'));
    $hold = new PublicVotesQueueHold(
      [],
      'safe_governance_setup',
      [],
      $inner,
      $this->entityTypeManager,
      $this->container->get('logger.channel.localnodes_web3'),
      $this->container->get('keyvalue'),
      $this->container->get('datetime.time'),
      $this->container->get('localnodes_web3.members_only_wait'),
    );
    try {
      $hold->processItem(['governance' => (int) $record->id()]);
      $this->fail('The worker\'s error went missing.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('The voting worker failed.', $e->getMessage());
    }
    $this->assertLockFree($this->fundLock(), 'The lock is given back when the voting worker fails.');
  }

  /**
   * Publishing a vote runs under the fund's lock, with a fresh read.
   *
   * The route's check passed while the group was public; the group is then
   * saved as members-only; the publish is refused and nothing reaches the
   * voting service. In the other order it publishes. Votes on proposals
   * already published stay open: only the publish route and the set-up's
   * approval routes are behind the site's controllers.
   */
  public function testPublishRefusedOnceGroupTurnsMembersOnly(): void {
    $route = $this->container->get('router.route_provider')->getRouteByName('safe_governance_group.proposal_publish');
    $this->assertSame(self::PUBLISH_LOCK, $route->getDefault('_controller'), 'Behind the site\'s controller.');
    $this->assertSame(self::PUBLISH_INNER, $route->getDefault('_localnodes_web3_inner'));
    $wrapped = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $candidate) {
      if (str_starts_with(ltrim((string) $candidate->getDefault('_controller'), '\\'), 'Drupal\\localnodes_web3\\Controller\\SetupApprovalLock::')
        || str_starts_with(ltrim((string) $candidate->getDefault('_controller'), '\\'), 'Drupal\\localnodes_web3\\Controller\\ProposalPublishLock::')) {
        $wrapped[] = $name;
      }
    }
    sort($wrapped);
    $this->assertSame(['safe_governance_group.proposal_publish', 'safe_governance_group.setup_approve', 'safe_governance_group.setup_messages', 'safe_governance_group.setup_restart'], $wrapped, 'Nothing else, so votes stay open.');

    [$draft, $body] = $this->signedDraft();
    $stale = $this->reloadGroup();
    $this->setCurrentUser($this->ana);
    $this->visibility('members');
    $this->setCurrentUser($this->jo);
    $response = $this->routeController('safe_governance_group.proposal_publish')($stale, $this->publishRequest($draft, $body));
    $this->assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['error' => self::SENTENCE], json_decode((string) $response->getContent(), TRUE));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame([], $this->hubPosts(), 'Nothing reached the voting service.');
    $this->assertLockFree($this->fundLock());

    // The other order: public again, then publish.
    $this->setCurrentUser($this->ana);
    $this->visibility('public');
    $this->setCurrentUser($this->jo);
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([$body], $this->hubPosts(), 'Published.');
    $this->assertLockFree($this->fundLock());
  }

  /**
   * A busy fund lock gets the publish route's own busy answer.
   */
  public function testPublishWaitsForTheFundLock(): void {
    [$draft, $body] = $this->signedDraft();
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($this->fundLock(), 60.0));
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $holder->release($this->fundLock());
    $this->assertSame(503, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(['error' => 'unavailable'], json_decode((string) $response->getContent(), TRUE));
    $this->assertNotStored($response, 'publish busy');
    $this->assertSame([], $this->hubPosts(), 'Nothing reached the voting service.');
    $response = $this->routeController('safe_governance_group.proposal_publish')($this->reloadGroup(), $this->publishRequest($draft, $body));
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame([$body], $this->hubPosts(), 'Published once the lock was free.');
  }

  /**
   * Every door to set-up and publishing is gone only for a members group.
   *
   * Set-up not finished: the organizer's "Open voting setup" on the Votes
   * tab and on Approve new members. Voting ready: the organizer's "Voting
   * setup" and, for the member who may propose, "Create a proposal". The
   * text around each door is the same in all three.
   */
  public function testDoorsGoneOnlyForMembersGroup(): void {
    // Set-up not finished, on a group and fund of its own (one record per
    // fund).
    [$setup_group, $setup_safe] = $this->fundedGroup('public');
    $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, NULL, $setup_safe);
    $this->setCurrentUser($this->ana);
    $sid = $setup_group->id();
    $texts = [];
    foreach (['public', 'community', 'members'] as $value) {
      $this->visibilityOf($setup_group, $value);
      $group = $this->groupNow($setup_group);
      $pages = [
        'votes' => $this->renderBuild($this->votesController()->votes($group)),
        'approve' => $this->renderBuild($this->container->get('form_builder')->getForm(ApproveMembersForm::class, $group)),
      ];
      foreach ($pages as $page => $html) {
        $dom = self::dom($html);
        if ($value === 'members') {
          $this->assertSame(0, $dom->query("//a[contains(@href, '/votes/setup')]")->length, "set-up, $page, members: no link to voting set-up.");
        }
        else {
          $this->assertNotNull(self::first($dom, "//a[@href='/group/$sid/votes/setup'][normalize-space(.)='Open voting setup']"), "set-up, $page, $value: the door shows.");
        }
        $texts["setup $page"][$value] = self::around($html);
      }
    }

    // Voting ready.
    $this->proposerFixture();
    $id = $this->group->id();
    foreach (['public', 'community', 'members'] as $value) {
      $this->setCurrentUser($this->ana);
      $this->visibility($value);
      $html = $this->renderBuild($this->votesController()->votes($this->reloadGroup()));
      $door = self::first(self::dom($html), "//a[@href='/group/$id/votes/setup'][normalize-space(.)='Voting setup']");
      $value === 'members' ? $this->assertNull($door, "ready, organizer, members: no door.") : $this->assertNotNull($door, "ready, organizer, $value: the door shows.");
      $this->assertSame(0, $value === 'members' ? self::dom($html)->query("//a[contains(@href, '/votes/setup')]")->length : 0);
      $texts['ready organizer'][$value] = self::around($html);

      $this->setCurrentUser($this->jo);
      $html = $this->renderBuild($this->votesController()->votes($this->reloadGroup()));
      $door = self::first(self::dom($html), "//a[contains(@class, 'btn-primary')][@href='/group/$id/votes/new'][normalize-space(.)='Create a proposal']");
      $value === 'members' ? $this->assertNull($door, "ready, member, members: no door.") : $this->assertNotNull($door, "ready, member, $value: the door shows.");
      $this->assertSame(0, $value === 'members' ? self::dom($html)->query("//a[contains(@href, '/votes/new')]")->length : 0);
      $texts['ready member'][$value] = self::around($html);
    }
    foreach ($texts as $page => $by_value) {
      $this->assertSame($by_value['public'], $by_value['community'], "$page: the text around the door is the same.");
      $this->assertSame($by_value['public'], $by_value['members'], "$page: the text around the door is the same.");
    }
  }

  /**
   * The check, and a lone door, vary with the group.
   */
  public function testCheckVariesByTheGroup(): void {
    $id = $this->group->id();
    $route = $this->container->get('router.route_provider')->getRouteByName('safe_governance_group.setup');
    foreach (['public' => TRUE, 'members' => FALSE] as $value => $allowed) {
      $this->visibility($value);
      $group = $this->reloadGroup();
      $answer = $this->container->get('localnodes_web3.public_votes')->access(new RouteMatch('safe_governance_group.setup', $route, ['group' => $group], ['group' => (string) $id]), $this->ana);
      $this->assertSame($allowed, $answer->isAllowed(), $value);
      $this->assertContains('group:' . $id, $answer->getCacheTags(), "$value: the answer carries the group's tags.");
      $this->assertContains('route.group', $answer->getCacheContexts(), "$value: and varies with the route's group.");

      $link = [
        '#type' => 'link',
        '#title' => 'Propose',
        '#url' => Url::fromRoute('safe_governance_group.proposal_new', ['group' => $id]),
      ];
      $this->container->get('renderer')->renderRoot($link);
      $this->assertContains('group:' . $id, $link['#cache']['tags'] ?? [], "$value: a lone door bubbles the group's tags.");
    }
  }

  /**
   * A cached door follows a change of visibility, with no cache clear.
   */
  public function testCachedDoorFollowsVisibilityChange(): void {
    $id = $this->group->id();
    $door = [
      '#cache' => ['keys' => ['localnodes_web3_test_door', (string) $id]],
      'link' => [
        '#type' => 'link',
        '#title' => 'Propose',
        '#url' => Url::fromRoute('safe_governance_group.proposal_new', ['group' => $id]),
      ],
    ];
    // A rendered array keeps #printed: each render starts from the original.
    $render = function () use ($door): string {
      $copy = $door;
      return (string) $this->container->get('renderer')->renderRoot($copy);
    };
    $this->assertStringContainsString("/group/$id/votes/new", $render(), 'Public: the door.');
    $this->visibility('members');
    $this->assertStringNotContainsString("/group/$id/votes/new", $render(), 'Members: no door, at once.');
    $this->visibility('public');
    $this->assertStringContainsString("/group/$id/votes/new", $render(), 'Public again: the door is back.');
  }

  /**
   * A group that turns members-only keeps what it has.
   */
  public function testGroupThatTurnsMembersOnlyKeepsWhatItHas(): void {
    $record = $this->proposerFixture();
    $counts = function (): array {
      $counts = [];
      foreach (['safe_governance', 'governance_proposal', 'governance_member_change', 'safe_transaction'] as $type) {
        $counts[$type] = (int) $this->entityTypeManager->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
      }
      return $counts;
    };
    $before = $counts();
    $state = $this->recordState($record);
    $this->setCurrentUser($this->ana);
    $this->visibility('members');
    $this->assertSame($before, $counts(), 'Nothing created.');
    $this->assertSame($state, $this->recordState($record), 'The voting record is unchanged.');
    $this->assertSame(SafeGovernanceInterface::STATUS_READY, $this->governanceRecord()->getStatus());

    $this->setCurrentUser($this->jo);
    $html = $this->renderBuild($this->votesController()->votes($this->reloadGroup()));
    $this->assertStringContainsString('How this group votes', self::text($html));
    $this->assertStringContainsString('Proposals', self::text($html));
    $this->assertNull(self::first(self::dom($html), "//a[normalize-space(.)='Create a proposal']"));
    $this->assertTrue($this->mayOpen('safe_governance_group.votes', $this->jo), 'The Votes tab stays open.');
    $this->assertTrue($this->mayOpen('safe_governance_group.members', $this->ana), 'Approve new members stays open.');
    $provider = $this->container->get('router.route_provider');
    foreach (['vote', 'vote_message', 'carry_out_status', 'carry_out_retry'] as $name) {
      $this->assertFalse($provider->getRouteByName('safe_governance_group.' . $name)->hasRequirement(PublicVotes::REQUIREMENT), "$name carries no requirement.");
    }
  }

  /**
   * Approve new members in a members group says roles stay on this site.
   */
  public function testApproveScreenSaysRolesStayOnSite(): void {
    $this->proposerFixture();
    $id = $this->group->id();
    $texts = [];
    foreach (['public', 'community', 'members'] as $value) {
      $this->setCurrentUser($this->ana);
      $this->visibility($value);
      $form = $this->container->get('form_builder')->getForm(ApproveMembersForm::class, $this->reloadGroup());
      $this->assertSame($value === 'members' ? 1 : 0, self::countText($form, self::ROLES_LINE), "$value: the line.");
      if ($value === 'members') {
        $children = Element::children($form, TRUE);
        $this->assertSame(1, self::countText($form[reset($children)], self::ROLES_LINE), 'At the top.');
      }
      $this->assertContains('group:' . $id, $form['#cache']['tags'] ?? [], "$value: the form carries the group's tags.");
      $texts[$value] = self::around($this->renderBuild($form));
    }
    $this->assertSame($texts['public'], $texts['community'], 'The rest of the form is the same.');
    $this->assertSame($texts['public'], $texts['members'], 'The rest of the form is the same.');
  }

  /**
   * Only the save check's refusal gets the plain page; nothing else does.
   *
   * The save check refuses with its own exception, which the storage wraps.
   * On a web request that is answered with the plain refusal page: the
   * plain line (never the developer line of a save that skipped
   * validation), status 409, never cached. Any other exception, wrapped or not,
   * keeps the site's normal error handling ("unexpected error", 500).
   */
  public function testOnlyTheSaveRefusalIsAnsweredPlainly(): void {
    $class = 'Drupal\localnodes_web3\Votes\MembersOnlyRefusedException';
    $this->assertTrue(class_exists($class), 'The save check has its own refusal.');
    $dispatcher = $this->container->get('event_dispatcher');
    $path = '/group/' . $this->group->id() . '/revisions/1/revert';

    $refused = new EntityStorageException(self::LINE, 0, new $class(self::LINE, (string) $this->group->label()));
    $event = $this->exceptionEvent($path, $refused);
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    $response = $event->getResponse();
    $this->assertNotNull($response, 'The refusal is answered.');
    $this->assertSame(409, $response->getStatusCode());
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $body = html_entity_decode(strip_tags((string) $response->getContent()), ENT_QUOTES);
    $this->assertStringContainsString(self::LINE, $body);
    $this->assertStringNotContainsString('unexpected error', $body);

    // A save that skipped validation: the refusal carries the developer
    // line for drush and the log; the page shows the plain line.
    $unlocked = new EntityStorageException(self::NOT_LOCKED, 0, new $class(self::NOT_LOCKED, (string) $this->group->label()));
    $this->assertSame(self::NOT_LOCKED, $unlocked->getPrevious()->getMessage(), 'The refusal keeps the developer line.');
    $event = $this->exceptionEvent($path, $unlocked);
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    $response = $event->getResponse();
    $this->assertNotNull($response, 'Not locked: the refusal is answered.');
    $this->assertSame(409, $response->getStatusCode(), 'Not locked: 409.');
    $body = html_entity_decode(strip_tags((string) $response->getContent()), ENT_QUOTES);
    $this->assertStringContainsString(self::LINE, $body, 'Not locked: the page has the plain line.');
    $this->assertStringNotContainsString(self::NOT_LOCKED, $body, 'Not locked: the page has no developer line.');
    $event = $this->exceptionEvent($path, $unlocked);
    $event->getRequest()->setRequestFormat('json');
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    $response = $event->getResponse();
    $this->assertNotNull($response, 'Not locked, JSON: the refusal is answered.');
    $this->assertSame(409, $response->getStatusCode(), 'Not locked, JSON: 409.');
    $this->assertSame(['error' => self::LINE], json_decode((string) $response->getContent(), TRUE), 'Not locked, JSON: the plain line.');

    foreach ([
      'plain' => new \RuntimeException(self::LINE),
      'wrapped' => new EntityStorageException(self::LINE, 0, new \RuntimeException(self::LINE)),
    ] as $kind => $other) {
      $event = $this->exceptionEvent($path, $other);
      // Core's logging writes every 500 to PHP's error log, which the test
      // harness reads as a failure; it is sent nowhere for this dispatch.
      $error_log = ini_set('error_log', '/dev/null');
      try {
        $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
      }
      finally {
        ini_set('error_log', (string) $error_log);
      }
      $response = $event->getResponse();
      $this->assertNotNull($response, "$kind: the site's own handling answers.");
      $this->assertSame(500, $response->getStatusCode(), "$kind: the site's normal error.");
      $this->assertStringContainsString('unexpected error', (string) $response->getContent(), "$kind: the site's normal error page.");
    }
  }

  /**
   * A save that skipped validation: the page's line follows what waits.
   *
   * The group set to members and saved without validating is refused
   * with the developer line for
   * drush and the log. The page and the JSON answer show "from here" while
   * nothing waits for the fund, and the edit form's line while a change
   * waits (the edit form's own read), never the developer line.
   */
  public function testRevertRefusalLineFollowsWhatWaits(): void {
    $this->assertSame([self::FROM_HERE, self::LINE], $this->refusedSaveLines(), 'Nothing waits: "from here".');
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'Nothing waits: not saved.');

    $this->waitingBatch();
    $this->assertSame([self::LINE, self::FROM_HERE], $this->refusedSaveLines(), 'A change waits: the edit form\'s line.');
    $this->assertSame('public', $this->reloadGroup()->get(self::VISIBILITY)->value, 'A change waits: not saved.');
  }

  /**
   * Saves the group as members without validating; answers the refusal.
   *
   * @return array{0: string, 1: string}
   *   The line the page and the JSON answer show, and the other plain line,
   *   after asserting the page has the first and neither the second nor
   *   the developer line.
   */
  private function refusedSaveLines(): array {
    $group = $this->reloadGroup()->set(self::VISIBILITY, 'members');
    try {
      $group->save();
      $this->fail('The save went through.');
    }
    catch (EntityStorageException $e) {
      $refused = $e;
    }
    $this->assertSame(self::NOT_LOCKED, $refused->getMessage(), 'drush and the log: the developer line.');
    $path = '/group/' . $this->group->id() . '/revisions/1/revert';
    $dispatcher = $this->container->get('event_dispatcher');

    $event = $this->exceptionEvent($path, $refused);
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    $this->assertNotNull($event->getResponse(), 'The refusal is answered.');
    $this->assertSame(409, $event->getResponse()->getStatusCode());
    $body = html_entity_decode(strip_tags((string) $event->getResponse()->getContent()), ENT_QUOTES);

    $event = $this->exceptionEvent($path, $refused);
    $event->getRequest()->setRequestFormat('json');
    $dispatcher->dispatch($event, KernelEvents::EXCEPTION);
    $this->assertNotNull($event->getResponse(), 'JSON: the refusal is answered.');
    $this->assertSame(409, $event->getResponse()->getStatusCode());
    $json = json_decode((string) $event->getResponse()->getContent(), TRUE);

    $shown = $json['error'] ?? '';
    $this->assertContains($shown, [self::FROM_HERE, self::LINE], 'JSON: a plain line.');
    $other = $shown === self::FROM_HERE ? self::LINE : self::FROM_HERE;
    $this->assertStringContainsString($shown, $body, 'The page has the JSON answer\'s line.');
    $this->assertStringNotContainsString($other, $body, 'The page has only one plain line.');
    $this->assertStringNotContainsString(self::NOT_LOCKED, $body, 'The page has no developer line.');
    return [$shown, $other];
  }

  /**
   * Sets the group's visibility, validates it as the edit form does, saves.
   *
   * Validation takes the fund's lock for a switch to members-only; the save
   * that follows finds it held.
   */
  private function visibility(string $value): void {
    $this->group = $this->reloadGroup();
    $this->group->set(self::VISIBILITY, $value);
    $this->assertSame([], self::violations($this->group), "No violation for $value.");
    $this->group->save();
  }

  /**
   * Jo and Mo (and Bo) join; the record is ready; each has a waiting change.
   *
   * They join before the record is made, so joining adds no row of its own.
   *
   * @return array{0: \Drupal\safe_governance\Entity\SafeGovernanceInterface, 1: \Drupal\safe_governance\Entity\GovernanceMemberChangeInterface, 2: \Drupal\safe_governance\Entity\GovernanceMemberChangeInterface, 3?: \Drupal\safe_governance\Entity\GovernanceMemberChangeInterface}
   *   The record and the rows.
   */
  private function twoWaiting(bool $bo = FALSE): array {
    $this->member('Jo', self::JO);
    $this->member('Mo', self::MO);
    if ($bo) {
      $this->member('Bo', self::BO);
    }
    $record = $this->readyRecord();
    $rows = [$record, $this->row($record, self::JO), $this->row($record, self::MO)];
    if ($bo) {
      $rows[] = $this->row($record, self::BO);
    }
    return $rows;
  }

  /**
   * A record's role rows, read fresh.
   *
   * @return \Drupal\safe_governance\Entity\GovernanceMemberChangeInterface[]
   *   The rows.
   */
  private function rowsOf(SafeGovernanceInterface $record): array {
    $storage = $this->entityTypeManager->getStorage('governance_member_change');
    $storage->resetCache();
    return $storage->loadByProperties(['governance' => $record->id()]);
  }

  /**
   * The approvals held on the site.
   *
   * @return array<string, array<string, mixed>>
   *   The entries, by key.
   */
  private function heldApprovals(): array {
    return $this->container->get('keyvalue')->get(self::HELD_APPROVALS)->getAll();
  }

  /**
   * The fund's next number, as the payment service gives it.
   */
  private function nextNonce(): int {
    return $this->container->get('safe_smart_accounts.transaction_service')->getNextNonce($this->safe);
  }

  /**
   * A role batch is approved, sent, lands and is done.
   */
  private function completeBatch(SafeTransactionInterface $batch, string $address): void {
    $this->approveAs(self::ANA, $batch);
    $this->approveAs(self::CY, $batch);
    $sends = count($this->sponsorCalls());
    $this->runCoreCron();
    $calls = array_slice($this->sponsorCalls(), $sends);
    $this->assertSame(['sponsorSafeExecution'], array_column($calls, 'intent'), 'The batch is sent once.');
    $this->assertSame(strtolower($this->loadTransaction((int) $batch->id())->getData()), strtolower((string) $calls[0]['args'][2]['data']));
    $this->confirmsOnChain();
    $this->wearers[HatId::toUint('5000.1')][] = strtolower($address);
    $this->safeNonce = (string) ((int) $this->safeNonce + 1);
    $this->runCoreCron();
  }

  /**
   * The group's edit form, as an organizer opens it.
   *
   * @return array<string, mixed>
   *   The built form.
   */
  private function editForm(): array {
    return $this->container->get('entity.form_builder')->getForm($this->reloadGroup(), 'edit');
  }

  /**
   * How often a text appears in a form's shown text.
   *
   * The text properties of the element and of its render children; a built
   * form holds cyclic arrays elsewhere, so nothing else is walked.
   *
   * @param array<string, mixed> $element
   *   The form or one of its elements.
   */
  private static function countText(array $element, string $text): int {
    $count = 0;
    foreach (['#prefix', '#suffix', '#markup', '#title', '#description', '#value'] as $key) {
      $value = $element[$key] ?? NULL;
      if (is_string($value) || $value instanceof \Stringable) {
        $count += substr_count(html_entity_decode((string) $value, ENT_QUOTES), $text);
      }
    }
    foreach (Element::children($element) as $child) {
      if (is_array($element[$child])) {
        $count += self::countText($element[$child], $text);
      }
    }
    return $count;
  }

  /**
   * The controller the router names for a route, as the kernel resolves it.
   */
  private function routeController(string $name): callable {
    $route = $this->container->get('router.route_provider')->getRouteByName($name);
    $controller = $this->container->get('controller_resolver')->getControllerFromDefinition((string) $route->getDefault('_controller'));
    $this->assertIsCallable($controller);
    return $controller;
  }

  /**
   * A request to a set-up approval route, as js/setup-approve.js sends it.
   */
  private function setupRequest(string $path, string $method): Request {
    $content = $method === 'POST' ? (string) json_encode(['kind' => 'settings', 'signer' => self::ANA, 'signature' => '0x' . str_repeat('1', 130)]) : NULL;
    $request = Request::create($path, $method, ['_format' => 'json'], [], [], ['CONTENT_TYPE' => 'application/json'], $content);
    $request->cookies->set($this->container->get('session_configuration')->getOptions($request)['name'], 'session');
    return $request;
  }

  /**
   * Jo's decision draft and the envelope he signed for it, while public.
   *
   * Copied from the voting module's ProposalCreateTest: the
   * form keeps a draft; the message route builds the message; the browser
   * posts the signed envelope.
   *
   * @return array{0: string, 1: array<string, mixed>}
   *   The draft id and the envelope.
   */
  private function signedDraft(): array {
    $this->proposerFixture();
    $this->setCurrentUser($this->jo);
    $values = [
      'title' => 'Move the monthly meeting to Thursdays',
      'body' => 'Most of us are free on Thursday evenings.',
      'op' => 'Publish proposal',
    ];
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->setUserInput($values);
    $state->addBuildInfo('args', [$this->group]);
    $state->setProgrammed();
    $form_id = $builder->getFormId(DecisionProposalForm::class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->prepareForm($form_id, $form, $state);
    $builder->processForm($form_id, $form, $state);
    $this->assertSame([], self::errors($state));
    $draft = $state->get('safe_governance_draft');
    $this->assertIsString($draft);
    $message = ProposalPublishController::create($this->container)->message($this->group, Request::create('/group/' . $this->group->id() . '/votes/publish/message', 'GET', ['draft' => $draft]));
    $this->assertSame(200, $message->getStatusCode(), (string) $message->getContent());
    $payload = json_decode((string) $message->getContent(), TRUE)['typedData'];
    return [$draft, [
      'address' => $payload['message']['from'],
      'sig' => self::PROPOSAL_SIG,
      'data' => [
        'domain' => $payload['domain'],
        'types' => ['Proposal' => $payload['types']['Proposal']],
        'message' => $payload['message'],
      ],
    ]];
  }

  /**
   * A POST of a signed proposal, as propose.js sends it (ProposalCreateTest).
   *
   * @param string $draft
   *   The draft id.
   * @param array<string, mixed> $body
   *   The JSON body.
   */
  private function publishRequest(string $draft, array $body): Request {
    $request = Request::create(
      '/group/' . $this->group->id() . '/votes/publish?draft=' . rawurlencode($draft),
      'POST',
      [],
      [],
      [],
      ['CONTENT_TYPE' => 'application/json'],
      (string) json_encode($body),
    );
    $request->cookies->set($this->container->get('session_configuration')->getOptions($request)['name'], 'session');
    return $request;
  }

  /**
   * What the voting service was sent.
   *
   * @return array<int, mixed>
   *   The posted bodies.
   */
  private function hubPosts(): array {
    return $this->container->get('state')->get(FixtureHubClient::POSTS_KEY) ?? [];
  }

  /**
   * What the fund's payment state reads for a payment now.
   *
   * @return array<string, mixed>
   *   PaymentState::evaluate()'s answer.
   */
  private function paymentState(SafeTransactionInterface $payment): array {
    $this->snapshotNow();
    $this->container->set('safe_smart_accounts.payment_state', NULL);
    return $this->container->get('safe_smart_accounts.payment_state')->evaluate($this->loadTransaction((int) $payment->id()), $this->safe);
  }

  /**
   * A page's text with its doors' labels and the roles line taken out.
   */
  private static function around(string $html): string {
    $text = str_replace([self::ROLES_LINE, 'Open voting setup', 'Voting setup', 'Create a proposal'], '', self::text($html));
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * An exception event for a web page request, as the kernel raises it.
   */
  private function exceptionEvent(string $path, \Throwable $throwable): ExceptionEvent {
    $request = Request::create($path, 'POST');
    $request->setSession($this->container->get('session'));
    return new ExceptionEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
  }

  /**
   * A group's violations, as "field: message".
   *
   * @return list<string>
   *   One line per violation.
   */
  private static function violations(GroupInterface $group): array {
    $lines = [];
    foreach ($group->validate() as $violation) {
      $lines[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
    }
    return $lines;
  }

  /**
   * A group as stored now, past every cache.
   */
  private function groupNow(GroupInterface $group): GroupInterface {
    $loaded = $this->entityTypeManager->getStorage('group')->loadUnchanged($group->id());
    $this->assertInstanceOf(GroupInterface::class, $loaded);
    return $loaded;
  }

  /**
   * Asserts a save is refused with a message, and rolled back.
   */
  private function assertSaveRefused(GroupInterface $group, string $message): void {
    try {
      $group->save();
    }
    catch (EntityStorageException $e) {
      $this->assertSame($message, $e->getMessage());
      return;
    }
    $this->fail('The save went through.');
  }

  /**
   * Asserts another request can take a lock at once, and gives it back.
   */
  private function assertLockFree(string $name, string $message = ''): void {
    $holder = $this->otherHolder();
    $this->assertTrue($holder->acquire($name, 60.0), $message ?: "$name is free.");
    $holder->release($name);
  }

  /**
   * One role change of the main group, approved: its batch is stored.
   *
   * @return array{0: \Drupal\safe_governance\Entity\SafeGovernanceInterface, 1: \Drupal\safe_governance\Entity\GovernanceMemberChangeInterface, 2: \Drupal\safe_smart_accounts\Entity\SafeTransactionInterface}
   *   The ready record, the row and its batch (draft).
   */
  private function waitingBatch(): array {
    $this->member('Jo', self::JO);
    $record = $this->readyRecord();
    $row = $this->row($record, self::JO);
    $batch = $this->runner()->propose($record, [(int) $row->id()], (int) $this->ana->id());
    return [$record, $row, $batch];
  }

  /**
   * Sets a fund payment's status, as stored.
   */
  private function setStatus(SafeTransactionInterface $payment, string $status): void {
    $storage = $this->entityTypeManager->getStorage('safe_transaction');
    $storage->resetCache([(int) $payment->id()]);
    $stored = $storage->load($payment->id());
    $this->assertInstanceOf(SafeTransactionInterface::class, $stored);
    $stored->set('status', $status)->save();
  }

  /**
   * A fund's voting set-up whose payment is stored, with that status.
   */
  private function storedSetUp(SafeAccountInterface $safe, string $status): SafeTransactionInterface {
    $setup = $this->entityTypeManager->getStorage('safe_transaction')->create([
      'safe_account' => $safe->id(),
      'to_address' => self::MULTI_SEND,
      'value' => '0',
      'data' => '0x8d80ff0a',
      'operation' => 1,
      'nonce' => 0,
      'status' => $status,
      'created_by' => $this->ana->id(),
    ]);
    $this->assertInstanceOf(SafeTransaction::class, $setup);
    $setup->markServerBuilt(CanonicalDeployments::MULTI_SEND);
    $setup->save();
    $this->setupRecord(SafeGovernanceInterface::STATUS_SETTING_UP, (int) $setup->id(), $safe);
    return $setup;
  }

  /**
   * The main fund's voting record, approving, before anything is prepared.
   *
   * The network carries the set-up's registry and voting page settings, as
   * the voting module's SetupApproveRouteTest sets them.
   */
  private function approvingRecord(): SafeGovernanceInterface {
    $settings = $this->network->getThirdPartySettings('safe_governance');
    $settings += [
      'multi_send' => self::MULTI_SEND,
      'create_call' => self::SETUP_REGISTRY['create_call'],
      'zodiac_module_factory' => self::SETUP_REGISTRY['zodiac_module_factory'],
      'zodiac_reality_mastercopy' => self::SETUP_REGISTRY['zodiac_reality_mastercopy'],
      'reality_template_id' => '7',
      'snapshot_network_id' => '11155111',
      'snapshot_hub' => 'https://hub.governance.invalid/graphql',
      'snapshot_sequencer' => 'https://seq.governance.invalid/',
    ];
    foreach ($settings as $key => $value) {
      $this->network->setThirdPartySetting('safe_governance', $key, $value);
    }
    $this->network->set('contracts', ['reality' => self::SETUP_REGISTRY['reality']] + $this->network->getContracts())->save();
    $record = $this->entityTypeManager->getStorage('safe_governance')->create([
      'safe_account' => $this->safe->id(),
      'network' => $this->safe->getNetwork(),
      'space' => 'garden-tools.eth',
      'quorum_votes' => 2,
      'voting_period' => 259200,
      'proposer_rule' => SafeGovernanceInterface::PROPOSER_ANY_MEMBER,
      'reality_timeout' => 3600,
      'new_member_votes' => TRUE,
      'status' => SafeGovernanceInterface::STATUS_APPROVING,
    ]);
    $this->assertInstanceOf(SafeGovernanceInterface::class, $record);
    $record->save();
    return $record;
  }

  /**
   * The record made ready, with readyRecord()'s roles.
   */
  private function makeReady(SafeGovernanceInterface $record): SafeGovernanceInterface {
    $stored = $this->entityTypeManager->getStorage('safe_governance')->loadUnchanged($record->id());
    $this->assertInstanceOf(SafeGovernanceInterface::class, $stored);
    $stored->set('status', SafeGovernanceInterface::STATUS_READY)
      ->set('proposer_rule', SafeGovernanceInterface::PROPOSER_HAT)
      ->set('top_hat', HatId::toUint('5000'))
      ->set('member_hat', HatId::toUint('5000.1'))
      ->set('proposer_hat', HatId::toUint('5000.2'))
      ->set('owners_seen', [strtolower(self::ANA), strtolower(self::CY)])
      ->set('voting_strategy', VotingStrategy::memberHat(self::HATS, '5000.1'))
      ->save();
    return $stored;
  }

  /**
   * The fund's lock name for the test fund, as the site names it.
   */
  private function fundLock(?SafeAccountInterface $safe = NULL): string {
    return 'localnodes_web3.members_only_fund:' . ($safe ?? $this->safe)->id();
  }

  /**
   * Another request's lock: core's database lock with its own lock id.
   *
   * The container's lock can't stand for one: taking again a name this
   * process holds only extends it.
   */
  private function otherHolder(): DatabaseLockBackend {
    return new DatabaseLockBackend($this->container->get('database'));
  }

  /**
   * Sets another group's visibility and saves it.
   */
  private function visibilityOf(GroupInterface $group, string $value): void {
    $current = $this->group;
    $this->group = $group;
    $this->visibility($value);
    $this->group = $current;
  }

  /**
   * Asserts each route is refused for a person, with the sentence.
   *
   * @param string[] $routes
   *   The route names.
   */
  private function assertRefused(array $routes, UserInterface $who): void {
    foreach ($routes as $route) {
      $answer = $this->container->get('access_manager')->checkNamedRoute($route, ['group' => $this->group->id()], $who, TRUE);
      $this->assertInstanceOf(AccessResultInterface::class, $answer);
      $this->assertTrue($answer->isForbidden(), "$route is refused.");
      $this->assertInstanceOf(AccessResultReasonInterface::class, $answer);
      $this->assertSame(self::SENTENCE, $answer->getReason(), "$route is refused for being members-only.");
    }
  }

  /**
   * A 403 as the router throws it for a voting step of the current group.
   */
  private function refusalEvent(string $route, string $path): ExceptionEvent {
    $request = Request::create($path);
    $request->attributes->set('_route', $route);
    $request->attributes->set('group', $this->reloadGroup());
    return new ExceptionEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException());
  }

  /**
   * What a held step must leave as it is: revision, status and step.
   *
   * @return array{0: int|string|null, 1: string, 2: string|null}
   *   The stored values.
   */
  private function recordState(SafeGovernanceInterface $record): array {
    $storage = $this->entityTypeManager->getStorage('safe_governance');
    $storage->resetCache([$record->id()]);
    $stored = $storage->load($record->id());
    $this->assertInstanceOf(SafeGovernanceInterface::class, $stored);
    return [$stored->getRevisionId(), $stored->getStatus(), $stored->get('step_detail')->value];
  }

  /**
   * The held queued steps: "queue:record" => when first held.
   *
   * @return array<string, int>
   *   The marks.
   */
  private function heldSteps(): array {
    return $this->container->get('keyvalue')->get(self::HELD_STEPS)->getAll();
  }

  /**
   * Replaces the site module's logger with one that records, and returns
   * what it records.
   */
  private function recordLogs(): \ArrayObject {
    $logs = new \ArrayObject();
    $this->container->set('logger.channel.localnodes_web3', new class($logs) extends AbstractLogger {

      /**
       * Constructs the recorder.
       */
      public function __construct(private readonly \ArrayObject $logs) {}

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->logs[] = [$level, (string) $message, $context];
      }

    });
    return $logs;
  }

  /**
   * The hold's notices among recorded logs.
   *
   * @return array<int, array{0: string, 1: string, 2: array<string, mixed>}>
   *   The notices.
   */
  private function holdNotices(\ArrayObject $logs): array {
    return array_values(array_filter($logs->getArrayCopy(), static fn(array $log): bool => $log[0] === 'notice' && str_contains($log[1], 'members-only')));
  }

  /**
   * Runs core's cron, as the site's scheduler does.
   */
  private function runCoreCron(): void {
    $before = $this->container->get('current_user')->getAccount();
    $this->container->get('cron')->run();
    $this->container->get('current_user')->setAccount($before);
  }

  /**
   * Copied from the voting module's ProposalCreateTest (final):
   * what the proposal routes' allowed path needs.
   *
   * Jo, a member with a linked account, and a ready record under "any
   * member" with the voting module's contracts; Ana and Jo wear the member
   * role and Ana the proposer role; the host-control reads find the fund
   * in charge; the voting service publishes.
   */
  private function proposerFixture(): SafeGovernanceInterface {
    $this->jo = $this->member('Jo', self::JO);
    $this->network->setThirdPartySetting('safe_governance', 'snapshot_network_id', '11155111')
      ->setThirdPartySetting('safe_governance', 'multi_send', self::MULTI_SEND)
      ->save();
    $this->takenNames['garden-tools'] = (string) $this->safe?->getSafeAddress();
    $this->setSnapshot(['owners' => [self::ANA, self::CY], 'threshold' => 2, 'balances' => [self::nativeRow('1000000000000000000')]]);
    $record = $this->readyRecord([
      'quorum_votes' => 5,
      'proposer_rule' => SafeGovernanceInterface::PROPOSER_ANY_MEMBER,
      'module' => self::MODULE,
      'arbitrator' => self::ARBITRATOR,
    ]);
    $this->wearers[HatId::toUint('5000.1')] = [strtolower(self::ANA), strtolower(self::JO)];
    $this->wearers[HatId::toUint('5000.2')] = [strtolower(self::ANA)];
    $state = $this->container->get('state');
    $state->set(FixtureHubClient::SCENARIO_KEY, 'proposal_published');
    $state->delete(FixtureHubClient::CALLS_KEY);
    $state->delete(FixtureHubClient::POSTS_KEY);
    return $record;
  }

  /**
   * A 32-byte word holding an address (ProposalCreateTest).
   */
  private static function word(string $address): string {
    return AbiEncoder::encode(['address'], [$address]);
  }

  /**
   * A viewHat() answer whose eligibility and toggle are one address
   * (ProposalCreateTest).
   */
  private static function viewHat(string $address): string {
    $word = static fn(string $hex): string => str_pad($hex, 64, '0', STR_PAD_LEFT);
    $padded = $word(substr(strtolower($address), 2));
    return '0x' . $word(dechex(9 * 32)) . $word(dechex(4294967295)) . $word('2') . $padded . $padded
      . $word(dechex(10 * 32)) . $word('0') . $word('1') . $word('1') . $word('0') . $word('0');
  }

  /**
   * The current group, read again from storage.
   */
  private function reloadGroup(): GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');
    $storage->resetCache([$this->group->id()]);
    $group = $storage->load($this->group->id());
    $this->assertInstanceOf(GroupInterface::class, $group);
    return $group;
  }

  /**
   * An approver proposes a payment at the fund's next number.
   */
  private function proposePayment(): SafeTransactionInterface {
    $this->assertInstanceOf(SafeAccount::class, $this->safe);
    $payment = $this->container->get('safe_smart_accounts.transaction_service')->createTransaction($this->safe, [
      'to' => self::RECIPIENT,
      'value' => '1000',
      'data' => '0x',
      'operation' => 0,
    ], (int) $this->ana->id());
    $this->assertInstanceOf(SafeTransactionInterface::class, $payment);
    return $payment;
  }

  /**
   * Both approvers approve a payment.
   */
  private function approvePayment(SafeTransactionInterface $payment): void {
    $this->approveAs(self::ANA, $payment);
    $this->approveAs(self::CY, $payment);
    $this->assertSame('pending', $this->loadTransaction((int) $payment->id())->getStatus(), 'Fully approved.');
  }

  /**
   * The fund's payment state reads the payment next in line, held by none.
   */
  private function assertNextInLine(SafeTransactionInterface $payment): void {
    $this->snapshotNow();
    $this->container->set('safe_smart_accounts.payment_state', NULL);
    $state = $this->container->get('safe_smart_accounts.payment_state')->evaluate($this->loadTransaction((int) $payment->id()), $this->safe);
    $this->assertTrue($state['next_in_line'], 'The payment is next in line; it waits behind ' . var_export($state['blocking_id'], TRUE) . '.');
    $this->assertNull($state['blocking_id']);
  }

  /**
   * Stores the fund's snapshot as the chain answers it now.
   */
  private function snapshotNow(): void {
    $this->setSnapshot([
      'owners' => [self::ANA, self::CY],
      'threshold' => 2,
      'nonce' => $this->safeNonce,
    ]);
  }

  /**
   * The community's sponsor sends a fully approved payment.
   *
   * @return array<string, mixed>
   *   The execute endpoint's answer.
   */
  private function sendSponsored(SafeTransactionInterface $payment): array {
    $this->confirmsOnChain();
    $tx = $this->loadTransaction((int) $payment->id());
    $this->assertInstanceOf(SafeTransaction::class, $tx);
    $this->assertInstanceOf(SafeAccount::class, $this->safe);
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode(['sponsored' => TRUE]));
    $response = SafeTransactionController::create($this->container)->executeTransaction($this->safe, $tx, $request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsArray($body);
    return $body;
  }

  /**
   * Asserts an answer is never stored: private, no-store, max-age=0.
   */
  private function assertNotStored(\Symfony\Component\HttpFoundation\Response $response, string $message): void {
    $header = (string) $response->headers->get('Cache-Control');
    foreach (['private', 'no-store', 'max-age=0'] as $directive) {
      $this->assertStringContainsString($directive, $header, "$message: $directive.");
    }
  }

  /**
   * Posts to a fund payment's execute route, through its routed controller.
   *
   * @param array<string, mixed> $body
   *   The JSON body.
   */
  private function routeSend(SafeTransactionInterface $payment, array $body): JsonResponse {
    $this->confirmsOnChain();
    $tx = $this->loadTransaction((int) $payment->id());
    $request = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    $response = $this->routeController('safe_smart_accounts.api_transaction_execute')($this->safe, $tx, $request);
    $this->assertInstanceOf(JsonResponse::class, $response);
    return $response;
  }

  /**
   * The number of stored fund payments.
   */
  private function transactionCount(): int {
    return (int) $this->entityTypeManager->getStorage('safe_transaction')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Another group with a fund of its own, on the test network.
   *
   * The organizer is Ana; the group is saved with the visibility given
   * before its fund is linked.
   *
   * @return array{0: \Drupal\group\Entity\GroupInterface, 1: \Drupal\safe_smart_accounts\Entity\SafeAccountInterface}
   *   The group and its fund.
   */
  private function fundedGroup(string $visibility): array {
    $n = ++$this->extraFunds;
    $group = $this->createGroup(['type' => self::GROUP_TYPE, 'label' => "Tool library $n"]);
    $group->addMember($this->ana, ['group_roles' => [self::GROUP_TYPE . '-admin']]);
    $group->set(self::VISIBILITY, $visibility)->save();
    $safe = $this->entityTypeManager->getStorage('safe_account')->create([
      'user_id' => $this->ana->id(),
      'network' => $this->network?->id(),
      'safe_address' => Address::checksum(sprintf('0x%040x', 0x5afe0000 + $n)),
      'threshold' => 1,
      'status' => 'active',
    ]);
    $this->assertInstanceOf(SafeAccountInterface::class, $safe);
    $safe->save();
    $this->container->get('group_treasury.treasury_service')->addTreasury($group, $safe);
    return [$group, $safe];
  }

  /**
   * A voting record part way through set-up, with its set-up payment.
   */
  private function setupRecord(string $status, ?int $setup_tx = NULL, ?SafeAccountInterface $safe = NULL): SafeGovernanceInterface {
    $values = ['status' => $status];
    if ($safe !== NULL) {
      $values['safe_account'] = $safe->id();
      $values['network'] = $safe->getNetwork();
    }
    if ($setup_tx !== NULL) {
      $values['setup_tx'] = [['safe_transaction' => $setup_tx]];
    }
    return $this->readyRecord($values);
  }

  // ---------------------------------------------------------------------
  // Copied from the voting module's ApproveMembersFormTest and
  // MembershipRunnerTest: the role-batch fixture.
  // ---------------------------------------------------------------------

  /**
   * Adds a member with a linked address.
   */
  private function member(string $name, ?string $address, bool $manager = FALSE): UserInterface {
    $user = $this->createSiteUser($name, $address);
    $this->group->addMember($user, $manager ? ['group_roles' => [self::GROUP_TYPE . '-admin']] : []);
    return $user;
  }

  /**
   * A waiting row.
   */
  private function row(SafeGovernanceInterface $record, string $address, string $change = 'add_member', string $reason = 'joined'): GovernanceMemberChangeInterface {
    $row = $this->entityTypeManager->getStorage('governance_member_change')->create([
      'governance' => $record->id(),
      'address' => $address,
      'change' => $change,
      'reason' => $reason,
      'selected' => FALSE,
    ]);
    $this->assertInstanceOf(GovernanceMemberChangeInterface::class, $row);
    $row->save();
    return $row;
  }

  /**
   * A row, read again.
   */
  private function reloadRow(GovernanceMemberChangeInterface $row): GovernanceMemberChangeInterface {
    $storage = $this->entityTypeManager->getStorage('governance_member_change');
    $storage->resetCache();
    $loaded = $storage->load($row->id());
    $this->assertInstanceOf(GovernanceMemberChangeInterface::class, $loaded);
    return $loaded;
  }

  /**
   * A voting record for the group's fund, ready unless the values say.
   *
   * @param array<string, mixed> $values
   *   Values to set or override.
   */
  private function readyRecord(array $values = []): SafeGovernanceInterface {
    $record = $this->entityTypeManager->getStorage('safe_governance')->create($values + [
      'safe_account' => $this->safe?->id(),
      'network' => $this->safe?->getNetwork(),
      'space' => 'garden-tools.eth',
      'quorum_votes' => 2,
      'voting_period' => 259200,
      'proposer_rule' => SafeGovernanceInterface::PROPOSER_HAT,
      'reality_timeout' => 3600,
      'status' => SafeGovernanceInterface::STATUS_READY,
      'top_hat' => HatId::toUint('5000'),
      'member_hat' => HatId::toUint('5000.1'),
      'proposer_hat' => HatId::toUint('5000.2'),
      'owners_seen' => [strtolower(self::ANA), strtolower(self::CY)],
      'voting_strategy' => VotingStrategy::memberHat(self::HATS, '5000.1'),
    ]);
    $this->assertInstanceOf(SafeGovernanceInterface::class, $record);
    $record->save();
    return $record;
  }

  /**
   * Submits Approve new members' approve button as the browser's POST does.
   *
   * @param array<int, array{select: int|null}> $changes
   *   The rows, by id.
   * @param callable|null $between
   *   Runs after the page is built and before it is submitted.
   *
   * @return array{0: array<string, mixed>, 1: \Drupal\Core\Form\FormStateInterface}
   *   The form and its state.
   */
  private function submitApprove(array $changes, ?callable $between = NULL): array {
    $builder = $this->container->get('form_builder');
    $values = ['changes' => $changes];
    $state = new FormState();
    $state->addBuildInfo('args', [$this->group]);
    $form_id = $builder->getFormId(ApproveMembersForm::class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $builder->prepareForm($form_id, $form, $state);
    $pressed = self::findByName($form, 'approve');
    $this->assertNotNull($pressed, 'The form has the approve button.');
    $input = $values + ['approve' => (string) $pressed['#value'], 'form_id' => $form_id];
    $state = (new FormState())->setUserInput($input)->setValues($values);
    $state->addBuildInfo('args', [$this->group]);
    $state->setProgrammed();
    $state->setProgrammedBypassAccessCheck(FALSE);
    $builder->getFormId(ApproveMembersForm::class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $builder->prepareForm($form_id, $form, $state);
    if ($between !== NULL) {
      $between();
    }
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->processForm($form_id, $form, $state);
    return [$form, $state];
  }

  /**
   * The element with a #name, searched depth first.
   *
   * @param array<string, mixed> $element
   *   The form or one of its elements.
   * @param string $name
   *   The #name.
   *
   * @return array<string, mixed>|null
   *   The element.
   */
  private static function findByName(array $element, string $name): ?array {
    if (($element['#name'] ?? NULL) === $name && isset($element['#value'])) {
      return $element;
    }
    foreach ($element as $key => $child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) {
        $found = self::findByName($child, $name);
        if ($found !== NULL) {
          return $found;
        }
      }
    }
    return NULL;
  }

  /**
   * The form errors as strings.
   *
   * @return string[]
   *   The messages.
   */
  protected static function errors(FormStateInterface $state): array {
    return array_values(array_map(static fn($message): string => strip_tags((string) $message), $state->getErrors()));
  }

  /**
   * The membership runner.
   */
  private function runner(): MembershipRunner {
    $runner = $this->container->get('safe_governance.membership_runner');
    $this->assertInstanceOf(MembershipRunner::class, $runner);
    return $runner;
  }

  /**
   * One approver approves a payment through the fund's signature check.
   */
  private function approveAs(string $owner, SafeTransactionInterface $tx): void {
    $user = $owner === self::ANA ? $this->ana : $this->cy;
    $this->recovers = $owner;
    $tx = $this->loadTransaction((int) $tx->id());
    $this->assertInstanceOf(SafeTransaction::class, $tx);
    $this->container->get('safe_smart_accounts.signature_verifier')->verify($this->safe, $tx, self::SIGNATURES[$owner], $owner, $user);
    $this->assertTrue($this->container->get('safe_smart_accounts.transaction_service')->addSignature($tx, self::SIGNATURES[$owner], strtolower($owner)));
    $this->recovers = self::ANA;
  }

  /**
   * The sponsor's send lands, with the fund's ExecutionSuccess event.
   */
  private function confirmsOnChain(string $status = '0x1'): void {
    $this->receipts[strtolower(FixtureSponsor::SETUP_HASH)] = [
      'transactionHash' => FixtureSponsor::SETUP_HASH,
      'status' => $status,
      'blockNumber' => '0x10',
      'logs' => $status === '0x1' ? [
        [
          'address' => strtolower(self::SEPOLIA_SAFE),
          'topics' => [Selectors::EXECUTION_SUCCESS_TOPIC, self::SAFE_TX_HASH],
          'data' => '0x' . str_repeat('0', 64),
        ],
      ] : [],
    ];
  }

  /**
   * The sends the community's sponsor fixture recorded.
   *
   * @return array<int, array{intent: string, args: array<int, mixed>}>
   *   The calls, in order.
   */
  private function sponsorCalls(): array {
    return FixtureSponsor::calls($this->container->get('state'));
  }

  /**
   * A fund payment, read again.
   */
  private function loadTransaction(int $id): SafeTransactionInterface {
    $storage = $this->entityTypeManager->getStorage('safe_transaction');
    $storage->resetCache();
    $tx = $storage->load($id);
    $this->assertInstanceOf(SafeTransactionInterface::class, $tx);
    return $tx;
  }

  /**
   * {@inheritdoc}
   *
   * Adds to the voting module's canned chain what a payment and a role batch
   * need: the fund's nonce as the test sets it, its transaction hash, Hats
   * reads, the signature check's ecrecover, receipts and block reads. Reads
   * of contracts this test doesn't set up (the host-control check) are an
   * outage, which that check reports as "couldn't check".
   */
  public function chainAnswer(RequestInterface $request): Response {
    $this->requests++;
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
      if (($frame['function'] ?? '') === 'processItem' && in_array($frame['class'] ?? '', self::WORKERS, TRUE)) {
        $this->workerRequests++;
        break;
      }
    }
    $body = json_decode((string) $request->getBody(), TRUE);
    $method = (string) ($body['method'] ?? '');
    $params = $body['params'] ?? [];
    $call = $params[0] ?? [];
    $to = is_array($call) ? strtolower((string) ($call['to'] ?? '')) : '';
    $data = is_array($call) ? strtolower((string) ($call['data'] ?? '')) : '';
    $safe = strtolower(self::SEPOLIA_SAFE);
    $result = NULL;
    if ($method === 'eth_call' && $to === $safe && str_starts_with($data, Selectors::NONCE)) {
      $result = AbiEncoder::encode(['uint256'], [$this->safeNonce]);
    }
    elseif ($method === 'eth_call' && $to === $safe && str_starts_with($data, Selectors::GET_TRANSACTION_HASH)) {
      $result = self::SAFE_TX_HASH;
    }
    elseif ($method === 'eth_call' && $to === $safe && str_starts_with($data, Selectors::GET_MESSAGE_HASH)) {
      // As SetupApproveRouteTest: a hash of the call itself.
      $result = '0x' . hash('sha256', 'message' . $data);
    }
    elseif ($method === 'eth_call' && $to === strtolower(self::HATS)) {
      $this->hatsCalls++;
      if (str_starts_with($data, HatsCodec::IS_WEARER_OF_HAT)) {
        $words = AbiDecoder::words('0x' . substr($data, 10));
        $wearer = strtolower(AbiDecoder::address($words[0]));
        $hat = (string) AbiDecoder::uint($words[1]);
        // The fund wears the top hat (ProposalCreateTest).
        $wears = $hat === HatId::toUint('5000')
          ? $wearer === $safe
          : in_array($wearer, $this->wearers[$hat] ?? [], TRUE);
        $result = AbiEncoder::encode(['bool'], [$wears]);
      }
      elseif (str_starts_with($data, HostPowerCheck::VIEW_HAT)) {
        $result = self::viewHat($safe);
      }
      elseif ($data === strtolower(HatsCodec::lastTopHatId())) {
        $result = AbiEncoder::encode(['uint256'], ['4999']);
      }
    }
    elseif ($method === 'eth_call' && in_array($to, [strtolower(self::MODULE), strtolower(self::ARBITRATOR)], TRUE)
      && in_array(substr($data, 0, 10), [HostPowerCheck::OWNER, HostPowerCheck::AVATAR, HostPowerCheck::TARGET], TRUE)) {
      $result = self::word($safe);
    }
    elseif ($method === 'eth_call' && $to === strtolower(SignatureVerifier::ECRECOVER_PRECOMPILE)) {
      $result = AbiEncoder::encode(['address'], [$this->recovers]);
    }
    elseif ($method === 'eth_getTransactionReceipt') {
      $hash = strtolower((string) $call);
      if (!array_key_exists($hash, $this->receipts)) {
        return parent::chainAnswer($request);
      }
      $result = $this->receipts[$hash];
      if ($result === NULL) {
        return self::rpc($body, NULL);
      }
    }
    elseif ($method === 'eth_blockNumber') {
      $result = self::LATEST_HEX;
    }
    elseif ($method === 'eth_getBalance') {
      $result = '0x0';
    }
    elseif ($method === 'eth_getLogs') {
      $result = [];
    }
    elseif ($method === 'eth_call' && !in_array($to, [$safe, strtolower(self::ENS_HELPER)], TRUE)) {
      return new Response(503, [], 'down');
    }
    if ($result === NULL) {
      return parent::chainAnswer($request);
    }
    return self::rpc($body, $result);
  }

  /**
   * A JSON-RPC answer.
   *
   * @param array<string, mixed>|null $body
   *   The request.
   * @param mixed $result
   *   The result.
   */
  private static function rpc(?array $body, mixed $result): Response {
    return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
      'jsonrpc' => '2.0',
      'id' => $body['id'] ?? 1,
      'result' => $result,
    ]));
  }

}
