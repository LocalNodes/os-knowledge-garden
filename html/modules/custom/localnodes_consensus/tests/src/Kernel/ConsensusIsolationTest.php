<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\node\Entity\Node;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;

/**
 * No consensus record reaches search, vectors or the assistant.
 *
 * The checks enumerate what is really there: the entity types the two
 * modules provide (by provider), every search_api and ai_search index in
 * active config and in our modules' shipped config, both modules' service
 * definitions and event subscriptions, their config directories and their
 * PHP classes. A future consensus entity type, service or config file is
 * caught without editing this test. Each check is first shown to fire on a
 * planted registration.
 *
 * @group localnodes_consensus
 */
final class ConsensusIsolationTest extends GroupAccessKernelTestBase {

  /**
   * The modules whose records must stay out of search and the assistant.
   */
  protected const MODULES = ['localnodes_consensus', 'localnodes_group_setup'];

  /**
   * Service id prefixes of search, vectors and the assistant.
   */
  protected const AI_SERVICE = '/^@?\??(social_ai_indexing|ai|ai_search|ai_agents|ai_assistant_api|search_api)[._]/';

  /**
   * Namespaces of search, vectors and the assistant.
   */
  protected const AI_NAMESPACE = '/\\\\?Drupal\\\\(social_ai_indexing|ai|ai_search|ai_agents|ai_assistant_api|search_api)\\\\/';

  /**
   * Event name prefixes of search, vectors and the assistant.
   */
  protected const AI_EVENT = '/^(ai|ai_agents|ai_assistant_api|ai_search|search_api|social_ai_indexing)[._]/';

  /**
   * Tags that would register a tool, a retrieval source or an index.
   */
  protected const AI_TAG = '/ai|search|assistant|tool|retriev|rag|vector|embedding|index/i';

  /**
   * Config files a module must not ship.
   */
  protected const CONFIG_FILE = '/^(comment\.type\.|search_api\.|ai_search\.|ai_assistant_api\.|ai_agents\.|ai\.)/';

  /**
   * Class names of stored turns, caches, rechecks or retrieval.
   */
  protected const CLASS_NAME = '/Turn|Cache|Recheck|Retriev|^Rag|Rag[A-Z]|Citation|Vector|Embedding|Index|Prompt/';

  /**
   * The listed Group type and its manager role.
   */
  protected string $groupType;
  protected string $managerRole;

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    if ($container->hasDefinition('extension.list.module')) {
      $container->getDefinition('extension.list.module')->setClass(AllProfilesModuleExtensionList::class);
    }
    if ($container->hasDefinition('config.storage.schema')) {
      $container->getDefinition('config.storage.schema')->setClass(AllProfilesSchemaStorage::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_installer')->install(['localnodes_group_setup', 'localnodes_proposal_feed', 'localnodes_consensus'], FALSE);
    $this->installConfig(['entity_access_by_field']);
    $types = $this->config('localnodes_group_setup.settings')->get('group_types');
    $this->groupType = (string) array_key_first($types);
    $this->managerRole = (string) reset($types);
  }

  /**
   * The entity type ids the two modules provide.
   *
   * @return string[]
   *   Ids, sorted.
   */
  protected function consensusTypes(): array {
    $ids = [];
    foreach ($this->container->get('entity_type.manager')->getDefinitions() as $id => $type) {
      if (in_array($type->getProvider(), self::MODULES, TRUE)) {
        $ids[] = $id;
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * A module's directory.
   */
  protected function moduleDir(string $module): string {
    return DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath($module);
  }

  /**
   * Every index and assistant config: active config plus our shipped files.
   *
   * @return array<string, array>
   *   Config data keyed by a label (config name or file path).
   */
  protected function indexConfigs(): array {
    $configs = [];
    $storage = $this->container->get('config.storage');
    foreach (['search_api.index.', 'ai_search.index.', 'ai_assistant_api.'] as $prefix) {
      foreach ($storage->listAll($prefix) as $name) {
        $configs["active:$name"] = (array) $storage->read($name);
      }
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(DRUPAL_ROOT . '/modules/custom', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $path = $file->getPathname();
      if (preg_match('#/config/(install|optional)/(search_api\.index\.|ai_search\.index\.|ai_assistant_api\.)[^/]+\.yml$#', $path)) {
        $configs[substr($path, strlen(DRUPAL_ROOT) + 1)] = (array) Yaml::decode((string) file_get_contents($path));
      }
    }
    return $configs;
  }

  /**
   * Index or assistant config that names a consensus type or module.
   *
   * @param array<string, array> $configs
   *   Config data keyed by label.
   * @param string[] $types
   *   Consensus entity type ids.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function indexViolations(array $configs, array $types): array {
    $found = [];
    foreach ($configs as $label => $data) {
      $datasources = array_keys($data['datasource_settings'] ?? []);
      foreach ($data['field_settings'] ?? [] as $field) {
        if (!empty($field['datasource_id'])) {
          $datasources[] = $field['datasource_id'];
        }
      }
      foreach ($types as $type) {
        if (in_array("entity:$type", $datasources, TRUE)) {
          $found[] = "$label: datasource entity:$type";
        }
      }
      $text = Yaml::encode($data);
      foreach ([...$types, ...self::MODULES] as $needle) {
        if (str_contains($text, $needle)) {
          $found[] = "$label: names $needle";
        }
      }
    }
    return array_values(array_unique($found));
  }

  /**
   * Service definitions that reach search, vectors or the assistant.
   *
   * @param array $yaml
   *   A decoded services file.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function serviceViolations(array $yaml): array {
    $found = [];
    foreach ($yaml['services'] ?? [] as $id => $service) {
      if (!is_array($service)) {
        continue;
      }
      if (preg_match(self::AI_SERVICE, $id) || preg_match(self::AI_NAMESPACE, '\\' . ltrim((string) ($service['class'] ?? ''), '\\') . '\\')) {
        $found[] = "$id: id or class";
      }
      foreach (['arguments', 'decorates', 'parent'] as $key) {
        foreach ((array) ($service[$key] ?? []) as $argument) {
          if (is_string($argument) && preg_match(self::AI_SERVICE, $argument)) {
            $found[] = "$id: $key $argument";
          }
        }
      }
      foreach ($service['tags'] ?? [] as $tag) {
        $name = (string) (is_array($tag) ? ($tag['name'] ?? '') : $tag);
        if ($name !== 'event_subscriber' && preg_match(self::AI_TAG, $name)) {
          $found[] = "$id: tag $name";
        }
      }
    }
    return $found;
  }

  /**
   * Subscribed event names that belong to search or the assistant.
   *
   * @param array<string, string[]> $subscriptions
   *   Event names keyed by service id.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function eventViolations(array $subscriptions): array {
    $found = [];
    foreach ($subscriptions as $id => $events) {
      foreach ($events as $event) {
        if (preg_match(self::AI_EVENT, $event)) {
          $found[] = "$id: subscribes to $event";
        }
      }
    }
    return $found;
  }

  /**
   * Shipped config files that add a comment type or search config.
   *
   * @param string $root
   *   A module directory.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function configViolations(string $root): array {
    $found = [];
    foreach (['config/install', 'config/optional'] as $dir) {
      foreach (glob("$root/$dir/*.yml") ?: [] as $path) {
        $name = basename($path);
        if (preg_match(self::CONFIG_FILE, $name)) {
          $found[] = "$dir/$name";
          continue;
        }
        if (str_starts_with($name, 'field.')) {
          $data = (array) Yaml::decode((string) file_get_contents($path));
          if (($data['type'] ?? $data['field_type'] ?? '') === 'comment') {
            $found[] = "$dir/$name: comment field";
          }
        }
      }
    }
    return $found;
  }

  /**
   * PHP classes that reach search or the assistant, or keep turns or caches.
   *
   * @param string $root
   *   A module directory.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function sourceViolations(string $root): array {
    $found = [];
    if (!is_dir("$root/src")) {
      return $found;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/src", \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($root) + 1);
      if (preg_match(self::CLASS_NAME, $file->getBasename('.php'))) {
        $found[] = "$relative: class name";
      }
      if (preg_match(self::AI_NAMESPACE, (string) file_get_contents($file->getPathname()))) {
        $found[] = "$relative: uses search or the assistant";
      }
    }
    return $found;
  }

  /**
   * Each check fires on a planted registration and passes a clean one.
   */
  public function testChecksCatchPlantedRegistrations(): void {
    $planted = [
      'planted.index' => [
        'datasource_settings' => ['entity:localnodes_consensus_notice' => []],
        'field_settings' => ['text' => ['datasource_id' => 'entity:localnodes_consensus_round']],
      ],
      'planted.assistant' => ['instructions' => 'Use localnodes_consensus records.'],
      'clean.index' => ['datasource_settings' => ['entity:node' => []], 'field_settings' => ['title' => ['datasource_id' => 'entity:node']]],
    ];
    $found = $this->indexViolations($planted, ['localnodes_consensus_notice', 'localnodes_consensus_round']);
    $this->assertContains('planted.index: datasource entity:localnodes_consensus_notice', $found);
    $this->assertContains('planted.index: datasource entity:localnodes_consensus_round', $found);
    $this->assertContains('planted.assistant: names localnodes_consensus', $found);
    $this->assertSame([], preg_grep('/^clean\.index/', $found));

    $found = $this->serviceViolations(['services' => [
      'localnodes_consensus.tool' => ['class' => 'Drupal\localnodes_consensus\Tool', 'arguments' => ['@social_ai_indexing.source_registry', '@?ai.provider']],
      'localnodes_consensus.retrieval' => ['class' => 'Drupal\localnodes_consensus\R', 'tags' => [['name' => 'ai_assistant.tool'], ['name' => 'event_subscriber']]],
      'localnodes_consensus.decorator' => ['class' => 'Drupal\social_ai_indexing\X', 'decorates' => 'search_api.query_helper'],
      'localnodes_consensus.clean' => ['class' => 'Drupal\localnodes_consensus\Clean', 'arguments' => ['@entity_type.manager', '@keyvalue.database'], 'tags' => [['name' => 'event_subscriber']]],
    ]]);
    $this->assertContains('localnodes_consensus.tool: arguments @social_ai_indexing.source_registry', $found);
    $this->assertContains('localnodes_consensus.tool: arguments @?ai.provider', $found);
    $this->assertContains('localnodes_consensus.retrieval: tag ai_assistant.tool', $found);
    $this->assertContains('localnodes_consensus.decorator: id or class', $found);
    $this->assertContains('localnodes_consensus.decorator: decorates search_api.query_helper', $found);
    $this->assertSame([], preg_grep('/^localnodes_consensus\.clean/', $found));

    $found = $this->eventViolations(['x' => ['ai_agents.pre_system_prompt', 'search_api.query_pre_execute', 'localnodes_proposal_feed.closed']]);
    $this->assertSame(['x: subscribes to ai_agents.pre_system_prompt', 'x: subscribes to search_api.query_pre_execute'], $found);

    $fixture = sys_get_temp_dir() . '/consensus_isolation_' . bin2hex(random_bytes(4));
    try {
      mkdir("$fixture/config/install", 0777, TRUE);
      mkdir("$fixture/config/optional", 0777, TRUE);
      mkdir("$fixture/src/Service", 0777, TRUE);
      file_put_contents("$fixture/config/install/comment.type.decision_comment.yml", "id: decision_comment\n");
      file_put_contents("$fixture/config/optional/search_api.index.decisions.yml", "id: decisions\n");
      file_put_contents("$fixture/config/install/field.storage.node.field_decision_comments.yml", "type: comment\n");
      file_put_contents("$fixture/config/install/fixture.settings.yml", "x: 1\n");
      file_put_contents("$fixture/src/Service/TurnCache.php", "<?php\nclass TurnCache {}\n");
      file_put_contents("$fixture/src/Service/Helper.php", "<?php\nuse Drupal\\ai\\OperationType\\Chat\\ChatInput;\n");
      file_put_contents("$fixture/src/Service/Clean.php", "<?php\nuse Drupal\\group\\Entity\\GroupInterface;\n");
      $this->assertSame([
        'config/install/comment.type.decision_comment.yml',
        'config/install/field.storage.node.field_decision_comments.yml: comment field',
        'config/optional/search_api.index.decisions.yml',
      ], $this->sortedConfigViolations($fixture));
      $found = $this->sourceViolations($fixture);
      sort($found);
      $this->assertSame(['src/Service/Helper.php: uses search or the assistant', 'src/Service/TurnCache.php: class name'], $found);
    }
    finally {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($fixture, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
      }
      rmdir($fixture);
    }
  }

  /**
   * Config violations, sorted.
   *
   * @return string[]
   *   Findings.
   */
  protected function sortedConfigViolations(string $root): array {
    $found = $this->configViolations($root);
    sort($found);
    return $found;
  }

  /**
   * No index, vector store or assistant config names a consensus record.
   */
  public function testNoIndexOrAssistantConfigNamesAConsensusRecord(): void {
    $types = $this->consensusTypes();
    // Every type is found by provider, the notice and the round included.
    foreach (['localnodes_consensus_round', 'localnodes_consensus_response', 'localnodes_consensus_notice', 'localnodes_meeting_report', 'localnodes_roster_entry', 'localnodes_roster_audit', 'localnodes_decision_rules', 'localnodes_template_application'] as $known) {
      $this->assertContains($known, $types);
    }
    $configs = $this->indexConfigs();
    // The library's own index and assistant config are read, so the check
    // is not passing on nothing.
    foreach (['search_api.index.social_posts.yml', 'ai_search.index.social_posts.yml', 'ai_assistant_api.ai_assistant.group_assistant.yml'] as $file) {
      $this->assertNotEmpty(preg_grep('#/config/install/' . preg_quote($file, '#') . '$#', array_keys($configs)), "$file is read");
    }
    $this->assertSame([], $this->indexViolations($configs, $types));
  }

  /**
   * No service of either module reaches search, vectors or the assistant.
   */
  public function testNoServiceReachesSearchOrTheAssistant(): void {
    $subscriptions = [];
    foreach (self::MODULES as $module) {
      $yaml = Yaml::decode((string) file_get_contents($this->moduleDir($module) . "/$module.services.yml"));
      $this->assertNotEmpty($yaml['services'] ?? []);
      $this->assertSame([], $this->serviceViolations($yaml), $module);
      foreach ($yaml['services'] as $id => $service) {
        if (!is_array($service)) {
          continue;
        }
        foreach ($service['tags'] ?? [] as $tag) {
          if (($tag['name'] ?? '') === 'event_subscriber') {
            $class = get_class($this->container->get($id));
            $subscriptions[$id] = array_map('strval', array_keys($class::getSubscribedEvents()));
          }
        }
      }
    }
    // The roster invalidator is the one subscriber; its events are read.
    $this->assertArrayHasKey('localnodes_consensus.round_roster_invalidator', $subscriptions);
    $this->assertNotEmpty($subscriptions['localnodes_consensus.round_roster_invalidator']);
    $this->assertSame([], $this->eventViolations($subscriptions));
  }

  /**
   * Neither module ships a comment type, comment field or search config,
   * nor a class that reaches the assistant or keeps turns or caches.
   */
  public function testNoCommentTypeSearchConfigOrRetrievalClass(): void {
    foreach (self::MODULES as $module) {
      $root = $this->moduleDir($module);
      $this->assertNotEmpty(glob("$root/config/install/*.yml"), "$module ships config");
      $this->assertSame([], $this->configViolations($root), $module);
      $this->assertSame([], $this->sourceViolations($root), $module);
    }
    $this->assertSame([], array_filter(array_keys($this->container->get('entity_type.bundle.info')->getBundleInfo('comment') ?? []), fn ($bundle) => str_contains((string) $bundle, 'decision') || str_contains((string) $bundle, 'consensus')));
  }

  /**
   * Group A's starter pages stay inside Group A.
   */
  public function testStarterPagesStayInTheirGroup(): void {
    $templates = $this->container->get('config.storage')->listAll('localnodes_group_setup.template.');
    sort($templates);
    $template = (string) $this->config(reset($templates))->get('id');
    $groups = [];
    foreach (['Willow', 'Juniper'] as $label) {
      $group = $this->createFlexibleGroup($label, 'community');
      $this->assertSame($this->groupType, $group->bundle(), 'A Group of the listed type');
      $manager = User::load($this->createPersona($label . '_organizer')->id());
      $group->addMember($manager, ['group_roles' => [$this->managerRole]]);
      $member = User::load($this->createPersona($label . '_member')->id());
      $group->addMember($member);
      $application = $this->container->get('localnodes_group_setup.application_manager')->confirm($group, $template, [], FALSE, $manager);
      $groups[$label] = [$member, $application->getGeneratedIds()];
    }
    [$a_member, $a_pages] = $groups['Willow'];
    [$b_member] = $groups['Juniper'];
    $outsider = User::load($this->createPersona('outsider')->id());
    $this->assertNotEmpty($a_pages);
    foreach ($a_pages as $key => $nid) {
      $node = Node::load($nid);
      $this->assertTrue($node->access('view', $a_member), "$key: Group A's member reads it");
      $this->assertFalse($node->access('view', $b_member), "$key: Group B's member does not");
      $this->assertFalse($node->access('view', $outsider), "$key: an outsider does not");
      $this->assertFalse($node->access('view', $this->anonymous()), "$key: a signed-out visitor does not");
    }
  }

}
