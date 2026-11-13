<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\localnodes_web3\ProofRoutes;
use Drupal\localnodes_web3\Routing\SignatureProofRoutes;
use Drupal\localnodes_web3\SignatureSession;
use Symfony\Component\Routing\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Every group fund and vote route needs a proven session, or says why not.
 *
 * The router is built with the fund and vote modules enabled, so routes from
 * entity route providers count too. The test finds the modules' routes on
 * its own (route name, entity type provider, handler class), so a route the
 * marking code misses still shows here. Each must be in ProofRoutes::MARKED
 * and carry the option, or be in ProofRoutes::EXEMPT with a reason and not
 * carry it.
 *
 * Forms: every form class of these modules is listed with the routes whose
 * pages show it. A form posts back to the page it is on, so the page's mark
 * covers its submit, before any validate or submit handler runs. Forms on
 * exempt pages may only be the refresh forms, which change no fund or vote.
 *
 * @group localnodes_web3
 */
final class ProofRoutesTest extends KernelTestBase {

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
    'siwe_login',
    'safe_smart_accounts',
    'gas_sponsor',
    'safe_governance',
    'entity',
    'flexible_permissions',
    'group',
    'group_treasury',
    'safe_governance_group',
    'localnodes_platform',
    'localnodes_web3',
  ];

  /**
   * Routes that move or commit funds, change who controls a fund, or take a
   * signature for one; each must stay marked whatever else changes.
   */
  private const MUST_MARK = [
    'safe_smart_accounts.api_transaction_execute',
    'safe_smart_accounts.api_transaction_sign',
    'safe_smart_accounts.api_safe_update_deployment',
    'safe_smart_accounts.transaction_create',
    'safe_smart_accounts.user_account_create',
    'safe_smart_accounts.user_account_manage',
    'group_treasury.propose_transaction',
    'group_treasury.create',
    'group_treasury.link',
    'group_treasury.unlink',
    'safe_governance_group.carry_out_retry',
    'safe_governance_group.setup_restart',
    'safe_governance_group.setup_approve',
    'safe_governance_group.members',
    'safe_governance_group.members_withdraw',
    'safe_governance_group.setup',
    'safe_governance_group.vote',
    'safe_governance_group.proposal_publish',
    'safe_governance_group.proposal_new_payment',
    'safe_governance_group.proposal_new_decision',
  ];

  /**
   * Forms that only re-read the public record. They may sit on exempt pages.
   */
  private const REFRESH_FORMS = [
    'Drupal\gas_sponsor\Form\NetworkFeeRefreshForm',
    'Drupal\group_treasury\Form\TreasuryRefreshForm',
    'Drupal\safe_governance_group\Form\RefreshVotesForm',
  ];

  /**
   * Each form class: the routes whose pages show it, or why it has none.
   */
  private const FORMS = [
    'Drupal\gas_sponsor\Form\NetworkFeeRefreshForm' => ['gas_sponsor.network_fees'],
    'Drupal\group_treasury\Form\TreasuryCreateForm' => ['group_treasury.create'],
    'Drupal\group_treasury\Form\TreasuryLinkForm' => ['group_treasury.link'],
    'Drupal\group_treasury\Form\TreasuryRefreshForm' => ['group_treasury.treasury'],
    'Drupal\group_treasury\Form\TreasuryTransactionProposeForm' => ['group_treasury.propose_transaction'],
    'Drupal\group_treasury\Form\TreasuryUnlinkForm' => ['group_treasury.unlink'],
    'Drupal\group_treasury\Form\TreasuryWizardStepForm' => 'Not routed or built anywhere; a variant of TreasuryCreateForm.',
    'Drupal\safe_governance_group\Form\ApproveMembersForm' => ['safe_governance_group.members'],
    'Drupal\safe_governance_group\Form\DecisionProposalForm' => ['safe_governance_group.proposal_new_decision'],
    'Drupal\safe_governance_group\Form\PaymentProposalForm' => ['safe_governance_group.proposal_new_payment'],
    'Drupal\safe_governance_group\Form\ProposalFormBase' => 'Abstract base of the two proposal forms.',
    'Drupal\safe_governance_group\Form\RefreshVotesForm' => ['safe_governance_group.votes', 'safe_governance_group.proposal'],
    'Drupal\safe_governance_group\Form\VotingSetupForm' => ['safe_governance_group.setup'],
    'Drupal\safe_governance_group\Form\WithdrawChangesForm' => ['safe_governance_group.members_withdraw'],
    'Drupal\safe_smart_accounts\Form\ErrorSummaryTrait' => 'A trait, not a form.',
    'Drupal\safe_smart_accounts\Form\SafeAccountCreateForm' => ['safe_smart_accounts.user_account_create'],
    'Drupal\safe_smart_accounts\Form\SafeAccountFormTrait' => 'A trait, not a form.',
    'Drupal\safe_smart_accounts\Form\SafeAccountManageForm' => ['safe_smart_accounts.user_account_manage'],
    'Drupal\safe_smart_accounts\Form\SafeNetworkForm' => ['entity.safe_network.add_form', 'entity.safe_network.edit_form'],
    'Drupal\safe_smart_accounts\Form\SafeSettingsForm' => ['safe_smart_accounts.admin_settings'],
    'Drupal\safe_smart_accounts\Form\SafeTransactionForm' => ['safe_smart_accounts.transaction_create'],
    'Drupal\safe_smart_accounts\Form\SafeTransactionFormTrait' => 'A trait, not a form.',
    'Drupal\localnodes_web3\Form\EmailLinkRequestForm' => ['localnodes_web3.email_link'],
    'Drupal\localnodes_web3\Form\EmailLinkSignInForm' => ['localnodes_web3.email_link_sign_in'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Each route of these modules is listed once: marked, or exempt with why.
   */
  public function testEveryRouteIsListed(): void {
    $found = array_keys($this->moduleRoutes());
    sort($found);
    $listed = array_merge(ProofRoutes::MARKED, array_keys(ProofRoutes::EXEMPT));
    sort($listed);
    $this->assertSame([], array_values(array_diff($found, $listed)), 'Routes of the fund and vote modules that are in neither list.');
    $this->assertSame([], array_values(array_diff($listed, $found)), 'Listed routes the router does not have.');
    $this->assertSame([], array_values(array_intersect(ProofRoutes::MARKED, array_keys(ProofRoutes::EXEMPT))), 'A route in both lists.');
    $this->assertSame(count(ProofRoutes::MARKED), count(array_unique(ProofRoutes::MARKED)), 'A route listed twice.');
    foreach (ProofRoutes::EXEMPT as $name => $reason) {
      $this->assertIsString($reason, $name);
      $this->assertGreaterThan(10, strlen(trim($reason)), $name . ' needs a reason.');
    }
    // The entity route provider's routes are among them.
    $this->assertContains('entity.safe_network.edit_form', $found);
  }

  /**
   * Marked routes carry the option; exempt ones do not.
   */
  public function testMarksOnTheBuiltRouter(): void {
    $routes = $this->moduleRoutes();
    foreach (ProofRoutes::MARKED as $name) {
      $this->assertTrue($routes[$name]->getOption(SignatureSession::ROUTE_OPTION), $name . ' is not marked.');
    }
    foreach (array_keys(ProofRoutes::EXEMPT) as $name) {
      $this->assertNull($routes[$name]->getOption(SignatureSession::ROUTE_OPTION), $name . ' is exempt but marked.');
    }
    foreach (self::MUST_MARK as $name) {
      $this->assertContains($name, ProofRoutes::MARKED, $name . ' changes funds or votes.');
    }
  }

  /**
   * A route nobody listed yet is marked; other modules' routes are not.
   */
  public function testNewRouteIsClosed(): void {
    $collection = new RouteCollection();
    $collection->add('group_treasury.something_new', new Route('/group/{group}/new', ['_controller' => '\Drupal\group_treasury\Controller\GroupTreasuryController::treasuryTab']));
    $collection->add('other.moves_funds', new Route('/other', ['_form' => '\Drupal\safe_governance_group\Form\VotingSetupForm']));
    $collection->add('entity.safe_account.something', new Route('/safe/x', ['_controller' => '\Drupal\system\Controller\Http4xxController::on404']));
    $collection->add('node.add', new Route('/node/add', ['_controller' => '\Drupal\system\Controller\Http4xxController::on404']));
    $collection->add('group_treasury.treasury', new Route('/group/{group}/treasury', ['_controller' => '\Drupal\group_treasury\Controller\GroupTreasuryController::treasuryTab']));
    (new SignatureProofRoutes($this->container->get('entity_type.manager')))->onAlterRoutes(new RouteBuildEvent($collection));
    $this->assertTrue($collection->get('group_treasury.something_new')->getOption(SignatureSession::ROUTE_OPTION));
    $this->assertTrue($collection->get('other.moves_funds')->getOption(SignatureSession::ROUTE_OPTION), 'Another module routing to a fund form.');
    $this->assertTrue($collection->get('entity.safe_account.something')->getOption(SignatureSession::ROUTE_OPTION));
    $this->assertNull($collection->get('node.add')->getOption(SignatureSession::ROUTE_OPTION));
    $this->assertNull($collection->get('group_treasury.treasury')->getOption(SignatureSession::ROUTE_OPTION));
  }

  /**
   * Every form class is placed; exempt pages show only refresh forms.
   */
  public function testEveryFormIsOnAMarkedPage(): void {
    $classes = [];
    $embedded = [];
    foreach ($this->sourceDirectories() as $namespace => $directory) {
      foreach (glob($directory . '/Form/*.php') as $file) {
        $classes[] = 'Drupal\\' . $namespace . '\Form\\' . basename($file, '.php');
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
          continue;
        }
        $code = file_get_contents($file->getPathname());
        preg_match_all('/getForm\(\s*\\\\?([A-Za-z_\\\\]+)::class/', $code, $matches);
        foreach ($matches[1] as $short) {
          $embedded[] = $short;
        }
        $this->assertDoesNotMatchRegularExpression('/getForm\(\s*[\'"$]/', $code, $file->getPathname() . ' builds a form this test cannot name.');
      }
    }
    sort($classes);
    $known = array_keys(self::FORMS);
    sort($known);
    $this->assertSame($known, $classes, 'Form classes in the fund and vote modules changed; place each one.');

    // Embedded forms are named by short class; each is a known form.
    $short = array_map(fn ($class) => substr(strrchr($class, '\\'), 1), $known);
    foreach ($embedded as $name) {
      $this->assertContains(substr(strrchr('\\' . $name, '\\'), 1), $short, $name . ' is built on a page; place it.');
    }

    $routes = $this->container->get('router.route_provider');
    foreach (self::FORMS as $class => $hosts) {
      if (is_string($hosts)) {
        continue;
      }
      foreach ($hosts as $name) {
        $route = $routes->getRouteByName($name);
        if (isset(ProofRoutes::EXEMPT[$name])) {
          $this->assertTrue(in_array($class, self::REFRESH_FORMS, TRUE) || str_starts_with($name, 'localnodes_web3.email_link'), $class . ' changes something but its page ' . $name . ' is exempt.');
        }
        else {
          $this->assertTrue($route->getOption(SignatureSession::ROUTE_OPTION), $class . ' on ' . $name);
        }
      }
    }
  }

  /**
   * The group fund submodule adds no routes of its own.
   */
  public function testSocialGroupTreasuryHasNoRoutes(): void {
    $path = $this->root . '/' . $this->container->get('extension.list.module')->getPath('social_group_treasury');
    $this->assertFileDoesNotExist($path . '/social_group_treasury.routing.yml');
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php' || $file->getExtension() === 'module') {
        $this->assertDoesNotMatchRegularExpression('/RoutingEvents|alterRoutes|getForm\(/', file_get_contents($file->getPathname()), $file->getPathname());
      }
    }
  }

  /**
   * The routes of the covered modules, found without ProofRoutes::covers().
   *
   * @return array<string, \Symfony\Component\Routing\Route>
   */
  private function moduleRoutes(): array {
    $providers = [];
    foreach ($this->container->get('entity_type.manager')->getDefinitions() as $id => $definition) {
      if (in_array($definition->getProvider(), ProofRoutes::MODULES, TRUE)) {
        $providers[] = $id;
      }
    }
    $names = '(' . implode('|', ProofRoutes::MODULES) . ')';
    $types = '(' . implode('|', $providers) . ')';
    $found = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $handlers = implode(' ', array_filter(array_map(
        fn ($key) => is_string($route->getDefault($key)) ? ltrim($route->getDefault($key), '\\') : NULL,
        ['_controller', '_form', '_title_callback', '_entity_form', '_entity_list', '_entity_view'],
      )));
      if (preg_match('/^' . $names . '\./', $name)
        || preg_match('/^entity\.' . $types . '\./', $name)
        || preg_match('/(^| )Drupal\\\\' . $names . '\\\\/', $handlers)
        || preg_match('/(^| )' . $types . '\./', $handlers)) {
        $found[$name] = $route;
      }
    }
    return $found;
  }

  /**
   * The fund and vote scripts load the panel first; vendor files do not.
   */
  public function testScriptsLoadThePanel(): void {
    $discovery = $this->container->get('library.discovery');
    $panel = 'localnodes_web3/signature_proof';
    foreach ([['safe_governance', 'vote'], ['safe_governance', 'propose'], ['safe_smart_accounts', 'transaction_manager'], ['safe_smart_accounts', 'safe_deployment']] as [$extension, $name]) {
      $library = $discovery->getLibraryByName($extension, $name);
      $this->assertIsArray($library, "$extension/$name exists.");
      $this->assertContains($panel, $library['dependencies'] ?? [], "$extension/$name needs the panel.");
    }
    foreach (['ethers', 'safe_sdk'] as $name) {
      $this->assertNotContains($panel, $discovery->getLibraryByName('safe_smart_accounts', $name)['dependencies'] ?? [], "safe_smart_accounts/$name is a vendor file.");
    }
    // The wallet signer is used only where the installed sign-in module has it.
    $signer = (bool) $discovery->getLibraryByName('siwe_login', 'signer');
    $own = $discovery->getLibraryByName('localnodes_web3', 'signature_proof');
    $this->assertSame($signer, in_array('siwe_login/signer', $own['dependencies'] ?? [], TRUE));
  }

  /**
   * Post forms on marked pages carry the mark for signed-in members only.
   */
  public function testFormsOnMarkedPagesAreMarked(): void {
    $provider = $this->container->get('router.route_provider');
    $user = $this->container->get('current_user');
    $member = new UserSession(['uid' => 5, 'roles' => ['authenticated']]);
    $cases = [
      ['group_treasury.create', $member, 'post', TRUE],
      ['safe_governance_group.vote', $member, 'post', TRUE],
      ['group_treasury.create', new AnonymousUserSession(), 'post', FALSE],
      ['group_treasury.create', $member, 'get', FALSE],
      ['group_treasury.treasury', $member, 'post', FALSE],
      ['system.admin', $member, 'post', FALSE],
    ];
    foreach ($cases as [$name, $account, $method, $marked]) {
      $request = Request::create('/');
      $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $provider->getRouteByName($name));
      $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
      $this->container->get('request_stack')->push($request);
      $user->setAccount($account);
      $form = ['#method' => $method];
      _localnodes_web3_signature_proof_form($form);
      $this->container->get('request_stack')->pop();
      $label = "$name, uid {$account->id()}, $method";
      $this->assertSame($marked, isset($form['#attributes']['data-ln-signature-proof']), $label);
      $this->assertSame($marked, in_array('localnodes_web3/signature_proof', $form['#attached']['library'] ?? [], TRUE), $label);
      $this->assertContains('route', $form['#cache']['contexts'], $label);
      $this->assertContains('user.roles:authenticated', $form['#cache']['contexts'], $label);
    }
  }

  /**
   * Group's generic relationship pages need the proof for fund relations.
   */
  public function testGroupRelationshipPagesOfFundRelations(): void {
    $guard = $this->container->get('localnodes_web3.signature_guard');
    $provider = $this->container->get('router.route_provider');
    $types = array_keys($this->container->get('group_relation_type.manager')->getDefinitions());
    $fund = array_values(array_filter($types, fn ($id) => str_starts_with($id, 'group_safe_account')));
    $this->assertNotEmpty($fund, 'group_treasury provides a relation type.');
    $request = function (string $route, array $attributes) use ($provider): Request {
      $request = Request::create('/', 'POST');
      $request->attributes->add(['_route' => $route, '_route_object' => $provider->getRouteByName($route)] + $attributes);
      return $request;
    };
    foreach (['entity.group_content.add_form', 'entity.group_content.create_form'] as $route) {
      $this->assertTrue($guard->needsProof($request($route, ['plugin_id' => $fund[0]])), "$route, fund");
      $this->assertFalse($guard->needsProof($request($route, ['plugin_id' => 'group_membership'])), "$route, membership");
      $this->assertTrue($guard->needsProof($request($route, ['plugin_id' => 'no_such_relation'])), "$route, unknown: closed");
    }
    foreach (['entity.group_content.delete_form', 'entity.group_content.edit_form'] as $route) {
      $relationship = $this->createMock(GroupRelationshipInterface::class);
      $relationship->method('getPluginId')->willReturn($fund[0]);
      $this->assertTrue($guard->needsProof($request($route, ['group_content' => $relationship])), "$route, fund");
      $membership = $this->createMock(GroupRelationshipInterface::class);
      $membership->method('getPluginId')->willReturn('group_membership');
      $this->assertFalse($guard->needsProof($request($route, ['group_content' => $membership])), "$route, membership");
    }
    $this->assertFalse($guard->needsProof($request('system.admin', ['plugin_id' => $fund[0]])), 'Only relationship pages look at a plugin ID.');
    $this->assertTrue($guard->needsProof($request('system.admin', ['group_content' => $relationship])), 'Any page handed a fund relationship needs it.');
    $this->assertFalse($guard->needsProof($request('system.admin', ['group_content' => $membership])));
    $this->assertTrue($guard->needsProof($request('group_treasury.unlink', [])), 'Marked routes still need it.');
  }

  /**
   * A real POST to a fund form cannot pass as another method.
   *
   * Symfony honours X-HTTP-Method-Override on a POST, and the form still
   * reads the posted values, so the guard must go by the method sent.
   */
  public function testMethodOverrideOnAFundForm(): void {
    foreach (['OPTIONS', 'GET', 'HEAD'] as $override) {
      $request = $this->routedRequest('group_treasury.unlink', 'POST', ['HTTP_X_HTTP_METHOD_OVERRIDE' => $override]);
      $this->assertSame($override, $request->getMethod());
      $this->assertSame('POST', $request->getRealMethod());
      $this->assertSame(403, $this->guardAnswer($request)?->getStatusCode(), "group_treasury.unlink, POST overridden to $override.");
    }
    $this->assertNull($this->guardAnswer($this->routedRequest('group_treasury.unlink', 'GET')), 'The form page itself still shows.');
  }

  /**
   * Preparing the voting setup approvals is a GET that saves setup state.
   *
   * SetupSignatureController::messages() prepares the approvals on first
   * read, so a read of that route changes the fund's voting setup and needs
   * the proof like a post does.
   */
  public function testSetupMessagesNeedProofOnRead(): void {
    foreach (['GET', 'HEAD'] as $method) {
      $response = $this->guardAnswer($this->routedRequest('safe_governance_group.setup_messages', $method));
      $this->assertSame(403, $response?->getStatusCode(), "safe_governance_group.setup_messages, $method.");
    }
    $this->assertNull($this->guardAnswer($this->routedRequest('safe_governance_group.setup', 'GET')), 'Other marked pages still show on GET.');
  }

  /**
   * Routes needing proof on reads are marked routes, and only they carry it.
   */
  public function testReadMarks(): void {
    $this->assertContains('safe_governance_group.setup_messages', ProofRoutes::PROOF_ON_READ);
    $this->assertSame([], array_values(array_diff(ProofRoutes::PROOF_ON_READ, ProofRoutes::MARKED)), 'A route needing proof on reads must be marked.');
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      $this->assertSame(in_array($name, ProofRoutes::PROOF_ON_READ, TRUE) ? TRUE : NULL, $route->getOption(SignatureSession::READ_OPTION), $name);
    }
  }

  /**
   * A request on a built route, for a signed-in member with no proof.
   */
  private function routedRequest(string $name, string $method, array $server = []): Request {
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 5, 'roles' => ['authenticated']]));
    $request = Request::create('/', $method, [], [], [], $server + ['HTTP_ACCEPT' => 'application/json']);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($name));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $name);
    return $request;
  }

  /**
   * The guard's answer to a request, or NULL when it lets it through.
   */
  private function guardAnswer(Request $request): ?\Symfony\Component\HttpFoundation\Response {
    $event = new RequestEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST);
    $this->container->get('localnodes_web3.signature_guard')->onRequest($event);
    return $event->getResponse();
  }

  /**
   * Namespace => src directory, for each covered module.
   *
   * @return array<string, string>
   */
  private function sourceDirectories(): array {
    $list = $this->container->get('extension.list.module');
    $directories = [];
    foreach (ProofRoutes::MODULES as $module) {
      $directory = $this->root . '/' . $list->getPath($module) . '/src';
      $this->assertDirectoryExists($directory, $module);
      $directories[$module] = $directory;
    }
    return $directories;
  }

}
