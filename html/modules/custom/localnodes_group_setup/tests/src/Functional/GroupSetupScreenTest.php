<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_group_setup\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_group_setup\Entity\TemplateApplication;
use Drupal\search_api\Entity\Server;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * The set-up screen: preview, change the rules, one deliberate confirm.
 *
 * The Group type, its manager role and the template id are read from config,
 * never named here. One method runs with decisions off (this module alone);
 * the other installs the decision modules first, as a fresh install does.
 *
 * @group localnodes_group_setup
 * @group phase22_functional
 */
final class GroupSetupScreenTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * The form's HTML id.
   */
  private const FORM = '#localnodes-group-setup-template-picker';

  /**
   * The Groups, keyed by name.
   *
   * @var \Drupal\group\Entity\GroupInterface[]
   */
  private array $groups = [];

  /**
   * The people, keyed by name.
   *
   * @var \Drupal\user\UserInterface[]
   */
  private array $people = [];

  /**
   * The installed template id and its definition.
   */
  private string $templateId;
  private array $definition;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // The install's final cron run can print Solr's answer.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend = $solr->getBackendConfig();
      $backend['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend)->save();
    }
    $this->container->get('module_installer')->install(['localnodes_group_setup']);
    $this->rebuildContainer();
    $this->container->get('router.builder')->rebuild();
    $this->container->get('module_handler')->loadAll();

    $names = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    $this->assertNotEmpty($names);
    $this->templateId = (string) $this->config(reset($names))->get('id');
    $this->definition = $this->container->get('localnodes_group_setup.application_manager')->definition($this->templateId);

    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $type = (string) array_key_first($types);
    $role = (string) reset($types);
    $owner = $this->createUser([], 'group_owner');
    foreach (['Alder', 'Birch'] as $label) {
      $group = Group::create([
        'type' => $type,
        'label' => "$label Group",
        'uid' => $owner->id(),
        'field_flexible_group_visibility' => 'public',
        'field_group_allowed_visibility' => [['value' => 'public'], ['value' => 'community'], ['value' => 'group']],
        'field_group_allowed_join_method' => 'added',
      ]);
      $group->save();
      $this->groups[$label] = $group;
      $this->people["{$label}_manager"] = $this->createUser([], "{$label}_manager");
      $group->addMember($this->people["{$label}_manager"], ['group_roles' => [$role]]);
      $this->people["{$label}_member"] = $this->createUser([], "{$label}_member");
      $group->addMember($this->people["{$label}_member"]);
    }
    $this->people['admin'] = $this->createUser([], 'site_admin', FALSE, ['roles' => ['administrator']]);
    $root = User::load(1);
    $root->passRaw = $this->rootUser->passRaw;
    $this->people['root'] = $root;
  }

  /**
   * With decisions off: preview, suggestion only, access, confirm, resume.
   */
  public function testScreenWithoutDecisions(): void {
    $this->assertFalse($this->container->has('localnodes_consensus.decision_rules'));
    $alder = $this->groups['Alder'];
    $setup = '/group/' . $alder->id() . '/setup';

    // Access: only the Group's current manager.
    foreach (['plain member' => $this->people['Alder_member'], 'site administrator, no membership' => $this->people['admin'], 'user 1, no membership' => $this->people['root'], 'another Group\'s manager' => $this->people['Birch_manager'], 'signed-out visitor' => NULL] as $label => $account) {
      $this->signIn($account);
      $this->drupalGet($setup);
      $this->assertRefused($label);
      $this->assertSession()->elementNotExists('css', self::FORM);
    }

    // The manager sees the tab and the page; an unknown suggestion shows no
    // preview and applies nothing.
    $this->signIn($this->people['Alder_manager']);
    $this->drupalGet('/group/' . $alder->id());
    $this->assertSession()->linkByHrefExists($setup);
    $this->drupalGet($setup, ['query' => ['group_template' => 'no_such_template']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', self::FORM);
    $this->assertSession()->pageTextNotContains((string) $this->definition['description']);
    $this->assertSession()->buttonNotExists('Confirm setup');
    $this->assertNothingApplied($alder);

    // Choosing the template previews it; still nothing is applied.
    // A required radio group is a radiogroup, and the page announces errors
    // and saved statuses.
    $this->assertSession()->elementAttributeContains('css', 'fieldset#edit-template--wrapper', 'role', 'radiogroup');
    $this->assertSession()->elementExists('css', 'script[src*="localnodes_group_setup/js/form-announce.js"]');
    $this->submitForm(['template' => $this->templateId], 'Preview');
    $this->assertPreview(FALSE);
    $this->assertNothingApplied($alder);

    // A query value only preselects: the preview shows, nothing is applied.
    $this->drupalGet($setup, ['query' => ['group_template' => $this->templateId]]);
    $this->assertPreview(FALSE);
    $this->assertNothingApplied($alder);
    $this->assertInputsLabelled();

    // Confirm: the record and the starter pages, "This group only".
    $this->submitForm([], 'Confirm setup');
    $application = $this->application($alder);
    $this->assertNotNull($application);
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());
    $this->assertFalse($application->isCommunityWide(), 'Not listed as the whole-community Group');
    $this->assertStarterPagesAreGroupOnly($application);

    // Again: already set up, no second confirm.
    $this->drupalGet($setup);
    $this->assertSession()->pageTextContains('This group is already set up.');
    $this->assertSession()->buttonNotExists('Confirm setup');

    // An interrupted record offers to continue, which finishes it.
    $birch = $this->groups['Birch'];
    $this->interruptedRecord($birch, $this->people['Birch_manager']);
    $this->signIn($this->people['Birch_manager']);
    $this->drupalGet('/group/' . $birch->id() . '/setup');
    $this->assertSession()->pageTextContains('Setup was interrupted before it finished.');
    $this->assertSession()->buttonNotExists('Confirm setup');
    $this->submitForm([], 'Continue setup');
    $resumed = $this->application($birch);
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $resumed->getStatus());
    $this->assertCount(count($this->definition['starter']), $resumed->getGeneratedIds());

    // A Group whose type is no longer listed: no tab, no page.
    $this->config('localnodes_group_setup.settings')->set('group_types', [])->save();
    $this->drupalGet('/group/' . $alder->id());
    $this->assertSession()->linkByHrefNotExists($setup);
    $this->drupalGet($setup);
    $this->assertRefused('unlisted type');
    $this->assertSession()->elementNotExists('css', self::FORM);
  }

  /**
   * With decisions on: the rules part, refusals, a changed money rule.
   */
  public function testRulesWithDecisions(): void {
    $this->container->get('module_installer')->install(['localnodes_proposal_feed', 'localnodes_consensus']);
    $this->rebuildContainer();
    $this->container->get('router.builder')->rebuild();
    $alder = $this->groups['Alder'];
    $birch = $this->groups['Birch'];
    // The starting-groups record names Alder as the whole-community Group
    // with this template; Birch is not listed.
    $this->container->get('state')->set('localnodes.community_template', [
      'groups' => [['group' => (int) $alder->id(), 'everyone' => TRUE, 'group_template' => $this->templateId]],
    ]);
    $setup = '/group/' . $alder->id() . '/setup';
    $this->signIn($this->people['Alder_manager']);
    $this->drupalGet($setup);
    $this->assertPreview(TRUE);
    $this->assertSession()->pageTextContains('Everyday and money decisions need at least two-thirds of the people who decide to respond within 7 days.');
    $this->assertSession()->pageTextContains('Changing the rules needs at least two-thirds of the people who decide to respond within 14 days.');
    $this->assertSession()->pageTextContains('After setup, changing these rules is itself a group decision.');
    $this->assertSession()->pageTextContains('New rules do not change decisions that are already open.');
    $this->assertInputsLabelled();
    $this->assertNothingApplied($alder);

    // Invalid rules are refused next to their field; nothing is saved.
    $this->submitForm([
      'rules[path]' => 'advanced',
      'rules[advanced][money][numerator]' => '4',
      'rules[advanced][money][denominator]' => '3',
      'rules[advanced][money][days]' => '0',
    ], 'Confirm setup');
    $this->assertSession()->pageTextContains('The share who must respond cannot be more than the whole roster.');
    $this->assertSession()->pageTextContains('Give at least 1 day to respond.');
    $this->assertSession()->elementAttributeContains('css', '[name="rules[advanced][money][numerator]"]', 'aria-invalid', 'true');
    $this->assertSession()->elementAttributeContains('css', '[name="rules[advanced][money][days]"]', 'aria-invalid', 'true');
    $this->assertNothingApplied($alder);
    $this->assertSame(0, $this->storedCount('localnodes_decision_rules'));

    // Money gets its own rule on the advanced path, then confirm.
    $this->submitForm([
      'rules[path]' => 'advanced',
      'rules[advanced][money][numerator]' => '3',
      'rules[advanced][money][denominator]' => '4',
      'rules[advanced][money][days]' => '10',
    ], 'Confirm setup');
    $application = $this->application($alder);
    $this->assertNotNull($application);
    $this->assertSame(TemplateApplication::STATUS_APPLIED, $application->getStatus());
    $this->assertTrue($application->isCommunityWide(), 'The starting-groups record names it the whole-community Group');
    $this->assertStarterPagesAreGroupOnly($application);
    $rules = $this->container->get('localnodes_consensus.decision_rules')->currentRules($this->reload($alder));
    $this->assertSame('advanced', $rules['path']);
    $this->assertSame([3, 4, 10], [$rules['money']['quorum_numerator'], $rules['money']['quorum_denominator'], $rules['money']['response_days']]);
    $everyday = $this->definition['decision_rules']['everyday'];
    $this->assertSame([$everyday['quorum_numerator'], $everyday['quorum_denominator'], $everyday['response_days']], [$rules['everyday']['quorum_numerator'], $rules['everyday']['quorum_denominator'], $rules['everyday']['response_days']]);

    // Birch is not in the starting-groups record: no suggestion, and not
    // the whole-community Group.
    $this->signIn($this->people['Birch_manager']);
    $this->drupalGet('/group/' . $birch->id() . '/setup');
    $this->assertSession()->buttonNotExists('Confirm setup');
    $this->submitForm(['template' => $this->templateId], 'Preview');
    $this->submitForm([], 'Confirm setup');
    $this->assertFalse($this->application($birch)->isCommunityWide());
  }

  /**
   * The preview: the definition's own values; rules only with decisions on.
   */
  private function assertPreview(bool $rules): void {
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains((string) $this->definition['label']);
    $session->pageTextContains((string) $this->definition['description']);
    foreach ($this->definition['words'] as $word) {
      $session->pageTextContains((string) $word);
    }
    foreach ($this->definition['starter'] as $item) {
      $session->pageTextContains($item['title'] . ' · Example · not adopted');
    }
    $session->pageTextContains('Nothing is set up until you confirm.');
    $session->buttonExists('Confirm setup');
    if ($rules) {
      $session->elementExists('css', '[name="rules[path]"]');
    }
    else {
      $session->elementNotExists('css', '[name^="rules["]');
      $session->pageTextNotContains('decisions need at least');
      $session->pageTextNotContains('changing these rules is itself a group decision');
    }
    $text = $this->getSession()->getPage()->find('css', self::FORM)->getText();
    $this->assertDoesNotMatchRegularExpression('/\b(wallet|chain|blockchain|token|gas|fund|funds|signature|payment)\b/i', $text);
  }

  /**
   * Every visible input in the form has a label of its own.
   */
  private function assertInputsLabelled(): void {
    $form = $this->getSession()->getPage()->find('css', self::FORM);
    $this->assertNotNull($form);
    $inputs = $form->findAll('css', 'input:not([type="hidden"]):not([type="submit"]), select, textarea');
    $this->assertNotEmpty($inputs);
    foreach ($inputs as $input) {
      $id = (string) $input->getAttribute('id');
      $this->assertNotSame('', $id, 'Input ' . $input->getAttribute('name') . ' has an id');
      $this->assertNotNull($form->find('css', 'label[for="' . $id . '"]'), 'Input ' . $input->getAttribute('name') . ' has a label');
    }
  }

  /**
   * A refusal: 403, 404, the sign-in page, or Open Social's About redirect.
   */
  private function assertRefused(string $label): void {
    $status = $this->getSession()->getStatusCode();
    $path = (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
    $this->assertTrue(in_array($status, [403, 404], TRUE) || str_ends_with($path, '/user/login') || str_ends_with($path, '/about'), "$label is refused (status $status at $path)");
  }

  /**
   * No set-up record and no starter page for the Group.
   */
  private function assertNothingApplied(GroupInterface $group): void {
    $this->assertNull($this->application($group), 'No set-up record');
    $titles = array_column($this->definition['starter'], 'title');
    $found = $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('title', $titles, 'IN')->count()->execute();
    $this->assertSame(0, (int) $found, 'No starter page');
  }

  /**
   * Each starter page exists, in the Group, readable by its members only.
   */
  private function assertStarterPagesAreGroupOnly(TemplateApplication $application): void {
    $ids = $application->getGeneratedIds();
    $this->assertCount(count($this->definition['starter']), $ids);
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    foreach ($ids as $nid) {
      $node = $storage->loadUnchanged($nid);
      $this->assertNotNull($node);
      $this->assertSame('group', $node->get('field_content_visibility')->value, $node->label() . ': This group only');
    }
  }

  /**
   * The Group's set-up record, in progress or applied.
   */
  private function application(GroupInterface $group): ?TemplateApplication {
    $this->container->get('entity_type.manager')->getStorage('localnodes_template_application')->resetCache();
    return $this->container->get('localnodes_group_setup.application_manager')->forGroup($group);
  }

  /**
   * A set-up record saved, with no step run: a request that died early.
   */
  private function interruptedRecord(GroupInterface $group, UserInterface $manager): void {
    $definition = $this->definition;
    $snapshot = [
      'template' => $definition['id'],
      'version' => $definition['version'],
      'kind' => $definition['kind'],
      'words' => $definition['words'],
      'roles_shown' => $definition['roles_shown'],
      'starter' => $definition['starter'],
      'decision_rules' => $definition['decision_rules'],
    ];
    /** @var \Drupal\localnodes_group_setup\Entity\TemplateApplication $record */
    $record = $this->container->get('entity_type.manager')->getStorage('localnodes_template_application')->create([
      'gid' => $group->id(),
      'kind' => $definition['kind'],
      'template_id' => $definition['id'],
      'template_version' => $definition['version'],
      'applied_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'community_wide' => FALSE,
      'status' => TemplateApplication::STATUS_IN_PROGRESS,
      'uid' => $manager->id(),
    ]);
    $record->addStep('recorded');
    $record->save();
  }

  /**
   * Signs in as the account, or out for NULL.
   */
  private function signIn(?UserInterface $account): void {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }
    if ($account !== NULL) {
      $this->drupalLogin($account);
    }
  }

  /**
   * Stored entities of a type.
   */
  private function storedCount(string $type): int {
    return (int) $this->container->get('entity_type.manager')->getStorage($type)->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * A fresh copy of a Group.
   */
  private function reload(GroupInterface $group): GroupInterface {
    return $this->container->get('entity_type.manager')->getStorage('group')->loadUnchanged($group->id());
  }

}
