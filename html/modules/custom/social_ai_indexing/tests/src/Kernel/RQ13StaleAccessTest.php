<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Session\AccountEvents;
use Drupal\Core\Session\AccountSetEvent;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\search_api\Entity\Index;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * RQ-13 in one process: a stale access decision never reaches an answer.
 *
 * The 22-27 live run (2026-11-07) leaked G-COM to a non-member after it
 * went members-only: Drupal caches entity access per entity, operation and
 * user for the life of the process, and saving the entity doesn't clear
 * it. The runner asked once before the change (ALLOWED, cached) and again
 * after it in the same process, so the query-time check reused the stale
 * ALLOWED. A web request starts empty; a long-lived process (a worker, a
 * drush job) doesn't.
 *
 * Unlike StaleIndexTest, this test never resets a cache itself. It warms
 * the caches by asking first, changes the site in the same process, leaves
 * the index stale and asks again. Two changes:
 * - the content: G-COM turns members-only (exactly RQ-13);
 * - the person: P-MEM leaves G, and G's members-only Topic must go.
 * Both doors are checked: the assistant's search tool (what reaches the
 * prompt, the registry and so the citations) and the overview's retrieval
 * (its post-filter feeds the overview prompt).
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class RQ13StaleAccessTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   *
   * See RagToolTrait for why social_email_broadcast is needed.
   */
  protected static $modules = ['social_ai_indexing_test', 'social_email_broadcast'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $server = $this->createStubSearchServer();
    $this->createShippedIndex('social_posts', $server);
    $this->createShippedIndex('social_comments', $server);
    $this->setUpLeakFixtures();
    foreach (['social_posts', 'social_comments'] as $index_id) {
      $this->indexAllItems(Index::load($index_id));
    }
    $this->mirrorCommentPayloads();
    $this->resetSourceRegistry();
  }

  /**
   * Stub backend modes x doors.
   *
   * Each door is asked on its own, before and after the change, so one
   * door's cache reset can't hide a missing reset in the other.
   *
   * @return array
   *   [mode, door].
   */
  public static function modes(): array {
    $cases = [];
    foreach (['vector filter fails open' => 'ignore', 'vector filter works (on stale payloads)' => 'honour'] as $label => $mode) {
      foreach (['tool', 'overview'] as $door) {
        $cases["$label, $door"] = [$mode, $door];
      }
    }
    return $cases;
  }

  /**
   * G-COM turns members-only after P-NON was allowed it in this process.
   *
   * @dataProvider modes
   */
  public function testContentTurnedMembersOnly(string $mode, string $door): void {
    $this->setStubMode($mode);
    $non = $this->personas['P-NON'];
    $g_com = $this->fixtures['G-COM'];
    $canaries = ['Fictional G community notice', 'community notice for group G'];

    // Before: P-NON may see G-COM, and both doors say so (cache warmed).
    $before = $this->ask($non, $door);
    $this->assertContains('node:' . $g_com->id(), $before['keys'], "[$mode, $door] Before the change P-NON gets G-COM.");

    // The change, saved, with the index left as it was.
    $snapshot = $this->stubStore()->getAll();
    $g_com->set('field_content_visibility', 'group')->save();
    $this->stubStore()->deleteAll();
    $this->stubStore()->setMultiple($snapshot);

    $after = $this->ask($non, $door);
    $this->assertNothingFrom($after, 'node:' . $g_com->id(), $canaries, "[$mode, $door] P-NON after G-COM went members-only");

    // Positive control: a member of G still gets it (the tool is scoped to
    // G; the overview is site-wide, where nobody gets members-only pages).
    if ($door === 'tool') {
      $member = $this->ask($this->personas['P-MEM'], $door);
      $this->assertStringContainsString('Fictional G community notice', $member['text'], "[$mode] P-MEM still gets G-COM.");
    }
  }

  /**
   * P-MEM leaves G after being allowed its members-only Topic in this process.
   *
   * @dataProvider modes
   */
  public function testMemberRemoved(string $mode, string $door): void {
    $this->setStubMode($mode);
    $mem = $this->personas['P-MEM'];
    $g_mo = $this->fixtures['G-MO'];
    $canaries = [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, self::ZQ7_COMMENT];

    $before = $this->ask($mem, $door);
    if ($door === 'tool') {
      $this->assertStringContainsString(self::ZQ7_BODY, $before['text'], "[$mode] Before leaving, P-MEM gets G-MO from the tool.");
    }
    elseif ($mode === 'ignore') {
      // The overview's query never asks for members-only pages (honour
      // mode returns none); with the vector filter open, its post-filter is
      // all that stands in the way, and it lets a member through.
      $this->assertContains('node:' . $g_mo->id(), $before['keys'], "[$mode] Before leaving, P-MEM's overview post-filter lets G-MO through.");
    }

    $this->groups['G']->removeMember(\Drupal\user\Entity\User::load($mem->id()));
    $this->assertFalse((bool) $this->groups['G']->getMember($mem), 'P-MEM is no longer a member of G.');

    $after = $this->ask($mem, $door);
    $this->assertNothingFrom($after, 'node:' . $g_mo->id(), $canaries, "[$mode, $door] P-MEM after leaving G");
    $this->assertNotContains('comment:' . $this->fixtures['G-MO-COMMENT']->id(), $after['keys'], "[$mode, $door] The members-only reply is gone too.");
  }

  /**
   * G-COM turns members-only in ANOTHER process; this one stays warm.
   *
   * Codex review of 36636b8 (P1): a long-lived process keeps the entity it
   * loaded earlier in its memory cache, so a change saved elsewhere leaves
   * load() returning the old object, and a fresh access check on it still
   * allows. The other process is simulated: the field is written in the
   * database and only the persistent entity cache entry is dropped (what
   * that process's save invalidates). Every memory cache here stays warm.
   *
   * @dataProvider modes
   */
  public function testContentChangedByAnotherProcess(string $mode, string $door): void {
    $this->setStubMode($mode);
    $non = $this->personas['P-NON'];
    $g_com = $this->fixtures['G-COM'];
    $before = $this->ask($non, $door);
    $this->assertContains('node:' . $g_com->id(), $before['keys'], "[$mode, $door] Before the change P-NON gets G-COM.");

    $database = $this->container->get('database');
    foreach (['node__field_content_visibility', 'node_revision__field_content_visibility'] as $table) {
      $database->update($table)
        ->fields(['field_content_visibility_value' => 'group'])
        ->condition('entity_id', $g_com->id())
        ->execute();
    }
    $this->container->get('cache.entity')->delete('values:node:' . $g_com->id());

    $after = $this->ask($non, $door);
    $this->assertNothingFrom($after, 'node:' . $g_com->id(), ['Fictional G community notice', 'community notice for group G'], "[$mode, $door] P-NON after another process made G-COM members-only");
  }

  /**
   * P-MEM is removed from G in ANOTHER process; this one stays warm.
   *
   * The membership rows go from the database, and the persistent caches
   * that process's delete invalidates are dropped (the relationship's
   * entity cache entry and the calculated group permissions). Group's
   * memberships and permissions memory caches here stay warm.
   *
   * @dataProvider modes
   */
  public function testMemberRemovedByAnotherProcess(string $mode, string $door): void {
    $this->setStubMode($mode);
    $mem = $this->personas['P-MEM'];
    $g_mo = $this->fixtures['G-MO'];
    $before = $this->ask($mem, $door);
    if ($door === 'tool') {
      $this->assertStringContainsString(self::ZQ7_BODY, $before['text'], "[$mode] Before leaving, P-MEM gets G-MO from the tool.");
    }
    elseif ($mode === 'ignore') {
      $this->assertContains('node:' . $g_mo->id(), $before['keys'], "[$mode] Before leaving, P-MEM's overview post-filter lets G-MO through.");
    }

    $relationship_id = (int) $this->groups['G']->getMember($mem)->getGroupRelationship()->id();
    $database = $this->container->get('database');
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $this->container->get('entity_type.manager')->getStorage('group_content')->getTableMapping();
    foreach (array_filter($mapping->getDedicatedTableNames(), [$database->schema(), 'tableExists']) as $table) {
      $database->delete($table)->condition('entity_id', $relationship_id)->execute();
    }
    foreach ([$mapping->getDataTable(), $mapping->getBaseTable()] as $table) {
      $database->delete($table)->condition('id', $relationship_id)->execute();
    }
    $this->container->get('cache.entity')->delete('values:group_content:' . $relationship_id);
    $this->container->get('cache.flexible_permissions')->deleteAll();
    $this->container->get('cache.group_memberships')->deleteAll();

    $after = $this->ask($mem, $door);
    $canaries = [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, self::ZQ7_COMMENT];
    $this->assertNothingFrom($after, 'node:' . $g_mo->id(), $canaries, "[$mode, $door] P-MEM after another process removed them from G");
  }

  /**
   * P-MEM loses the only group role that grants view, in ANOTHER process.
   *
   * Codex review of f5cb94d (P1): flexible_permissions returns calculated
   * permissions from its memory cache before the persistent one, and
   * GroupRoleStorage memoises each user's role ids, so a long-lived process
   * keeps a revoked role's grant. Here the member role can't view Topics;
   * an individual role can, and P-MEM holds it. The role is removed in the
   * database and the persistent caches that save invalidates are dropped;
   * the membership stays and every memory cache here stays warm.
   *
   * @dataProvider modes
   */
  public function testGroupRoleRevokedByAnotherProcess(string $mode, string $door): void {
    $permission = 'view group_node:topic entity';
    GroupRole::load('flexible_group-member')->revokePermission($permission)->save();
    GroupRole::create([
      'id' => 'flexible_group-rq13_viewer',
      'label' => 'RQ-13 viewer',
      'group_type' => 'flexible_group',
      'scope' => 'individual',
      'permissions' => [$permission],
    ])->save();
    $mem = $this->personas['P-MEM'];
    $membership = $this->groups['G']->getMember($mem);
    $membership->addRole('flexible_group-rq13_viewer');
    $g_mo = $this->fixtures['G-MO'];

    $this->setStubMode($mode);
    $before = $this->ask($mem, $door);
    if ($door === 'tool') {
      $this->assertStringContainsString(self::ZQ7_BODY, $before['text'], "[$mode] With the viewer role, P-MEM gets G-MO from the tool.");
    }
    elseif ($mode === 'ignore') {
      $this->assertContains('node:' . $g_mo->id(), $before['keys'], "[$mode] With the viewer role, P-MEM's overview post-filter lets G-MO through.");
    }

    $relationship_id = (int) $membership->getGroupRelationship()->id();
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $this->container->get('entity_type.manager')->getStorage('group_content')->getTableMapping();
    $this->container->get('database')->delete($mapping->getFieldTableName('group_roles'))
      ->condition('entity_id', $relationship_id)
      ->condition('group_roles_target_id', 'flexible_group-rq13_viewer')
      ->execute();
    // What that process's save invalidates in the shared caches: the
    // relationship's entity entry, calculated permissions, and Group's
    // persistent membership cache (tagged with the relationship).
    $this->container->get('cache.entity')->delete('values:group_content:' . $relationship_id);
    $this->container->get('cache.flexible_permissions')->deleteAll();
    $this->container->get('cache.group_memberships')->deleteAll();

    $after = $this->ask($mem, $door);
    $this->assertNothingFrom($after, 'node:' . $g_mo->id(), [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, self::ZQ7_COMMENT], "[$mode, $door] P-MEM after another process revoked the viewer role");
    $this->assertTrue((bool) $this->groups['G']->getMember($mem), 'P-MEM is still a member of G.');
  }

  /**
   * The viewer role loses its permission in ANOTHER process; P-MEM keeps it.
   *
   * Codex review of 05468b5 (P1 1): group_role config entities stay in the
   * config factory's and the entity storage's memory, so a permission
   * removed from a role elsewhere kept granting view. The other process
   * writes the role's config through config storage (the database and the
   * shared config cache) and invalidates the calculated permissions.
   *
   * @dataProvider modes
   */
  public function testGroupRolePermissionRemovedByAnotherProcess(string $mode, string $door): void {
    $mem = $this->personas['P-MEM'];
    $this->giveViewerRole($mem);
    $g_mo = $this->fixtures['G-MO'];
    $this->setStubMode($mode);
    $this->assertMemberGetsGmo($this->ask($mem, $door), $mode, $door);

    $name = 'group.role.flexible_group-rq13_viewer';
    $storage = $this->container->get('config.storage');
    $data = $storage->read($name);
    $data['permissions'] = [];
    $storage->write($name, $data);
    $this->container->get('cache.flexible_permissions')->deleteAll();

    $after = $this->ask($mem, $door);
    $this->assertNothingFrom($after, 'node:' . $g_mo->id(), [self::ZQ7_TITLE, self::ZQ7_BODY, self::ZQ7_FACT, self::ZQ7_COMMENT], "[$mode, $door] P-MEM after another process took view from the viewer role");
  }

  /**
   * 'verified' loses community view in ANOTHER process.
   *
   * Codex review of 05468b5 (P1 2): core's access policies keep calculated
   * global permissions in cache.access_policy_memory, and role config stays
   * in memory, so a global grant revoked elsewhere survived. The other
   * process writes the role's config and invalidates the access policy
   * cache.
   *
   * @dataProvider modes
   */
  public function testGlobalPermissionRemovedByAnotherProcess(string $mode, string $door): void {
    $non = $this->personas['P-NON'];
    $g_com = $this->fixtures['G-COM'];
    $this->setStubMode($mode);
    $this->assertContains('node:' . $g_com->id(), $this->ask($non, $door)['keys'], "[$mode, $door] Before, P-NON gets G-COM.");

    $storage = $this->container->get('config.storage');
    $data = $storage->read('user.role.verified');
    $data['permissions'] = array_values(array_diff($data['permissions'], ['view node.topic.field_content_visibility:community content']));
    $storage->write('user.role.verified', $data);
    $this->container->get('cache.access_policy')->deleteAll();

    $after = $this->ask($non, $door);
    $this->assertNothingFrom($after, 'node:' . $g_com->id(), ['Fictional G community notice', 'community notice for group G'], "[$mode, $door] P-NON after another process took community view from 'verified'");
  }

  /**
   * P-NON loses the 'verified' role in ANOTHER process.
   *
   * Codex review of 05468b5 (P1 3): the asker's account object was never
   * reloaded, so global roles removed elsewhere stayed on it. The other
   * process deletes the role row and drops the user's persistent entity
   * cache entry.
   *
   * @dataProvider modes
   */
  public function testGlobalRoleRemovedByAnotherProcess(string $mode, string $door): void {
    $non = \Drupal\user\Entity\User::load($this->personas['P-NON']->id());
    $this->personas['P-NON'] = $non;
    $g_com = $this->fixtures['G-COM'];
    $this->setStubMode($mode);
    $this->assertContains('node:' . $g_com->id(), $this->ask($non, $door)['keys'], "[$mode, $door] Before, P-NON gets G-COM.");

    $this->container->get('database')->delete('user__roles')
      ->condition('entity_id', $non->id())
      ->condition('roles_target_id', 'verified')
      ->execute();
    $this->container->get('cache.entity')->delete('values:user:' . $non->id());

    // The same account object the long-lived process was handed earlier.
    $after = $this->ask($non, $door);
    $this->assertNothingFrom($after, 'node:' . $g_com->id(), ['Fictional G community notice', 'community notice for group G'], "[$mode, $door] P-NON after another process removed 'verified'");
  }

  /**
   * Makes the individual viewer role the only group grant of Topic view.
   */
  private function giveViewerRole(AccountInterface $account): void {
    $permission = 'view group_node:topic entity';
    GroupRole::load('flexible_group-member')->revokePermission($permission)->save();
    GroupRole::create([
      'id' => 'flexible_group-rq13_viewer',
      'label' => 'RQ-13 viewer',
      'group_type' => 'flexible_group',
      'scope' => 'individual',
      'permissions' => [$permission],
    ])->save();
    $this->groups['G']->getMember($account)->addRole('flexible_group-rq13_viewer');
  }

  /**
   * Asserts P-MEM got G-MO before the change, where the door can show it.
   */
  private function assertMemberGetsGmo(array $before, string $mode, string $door): void {
    if ($door === 'tool') {
      $this->assertStringContainsString(self::ZQ7_BODY, $before['text'], "[$mode] Before, P-MEM gets G-MO from the tool.");
    }
    elseif ($mode === 'ignore') {
      $this->assertContains('node:' . $this->fixtures['G-MO']->id(), $before['keys'], "[$mode] Before, P-MEM's overview post-filter lets G-MO through.");
    }
  }

  /**
   * The tool swaps the current user only for its own execute().
   */
  public function testCurrentUserRestoredAfterExecute(): void {
    $this->setStubMode('ignore');
    $this->setCurrentUser($this->personas['P-NON']);
    $before = $this->container->get('current_user')->getAccount();
    $this->container->get('ai_assistant_api.runner')->setContext(['group_id' => (int) $this->groups['G']->id()]);
    $tool = $this->container->get('plugin.manager.ai.function_calls')->createInstance('ai_search:rag_search');
    $tool->setContextValue('index', 'social_posts');
    $tool->setContextValue('search_string', 'fictional');
    $tool->setContextValue('amount', 10);
    $tool->setContextValue('min_score', 0.0);
    $tool->execute();
    $this->assertSame($before, $this->container->get('current_user')->getAccount(), 'current_user holds the same account object after execute().');
  }

  /**
   * The overview's post-filter swaps the current user only for its call.
   */
  public function testCurrentUserRestoredAfterOverviewRetrieve(): void {
    $this->setStubMode('ignore');
    $this->setCurrentUser($this->personas['P-NON']);
    $before = $this->container->get('current_user')->getAccount();
    $this->container->get('social_ai_indexing.ai_overview')->retrieve('fictional', $this->container->get('current_user'));
    $this->assertSame($before, $this->container->get('current_user')->getAccount(), 'current_user holds the same account object after the post-filter.');
  }

  /**
   * P-NON is blocked in ANOTHER process: anonymous access only after that.
   *
   * Codex round 4 (P1-2): the fresh reload accepted a blocked account.
   *
   * @dataProvider modes
   */
  public function testBlockedByAnotherProcess(string $mode, string $door): void {
    $non = $this->personas['P-NON'];
    $g_com = $this->fixtures['G-COM'];
    $this->setStubMode($mode);
    $this->assertContains('node:' . $g_com->id(), $this->ask($non, $door)['keys'], "[$mode, $door] Before, P-NON gets G-COM.");

    $this->container->get('database')->update('users_field_data')
      ->fields(['status' => 0])
      ->condition('uid', $non->id())
      ->execute();
    $this->container->get('cache.entity')->delete('values:user:' . $non->id());

    $after = $this->ask($non, $door);
    $this->assertNothingFrom($after, 'node:' . $g_com->id(), ['Fictional G community notice', 'community notice for group G'], "[$mode, $door] P-NON after another process blocked them");
  }

  /**
   * A throwing account.set subscriber can't leave the swapped account in.
   *
   * Codex round 4 (P2-4): setAccount() stores the account, then dispatches
   * account.set; the swap must sit inside the try whose finally restores.
   *
   * @dataProvider doors
   */
  public function testCurrentUserRestoredWhenAccountSetThrows(string $door): void {
    $this->setStubMode('ignore');
    $this->setCurrentUser($this->personas['P-NON']);
    $proxy = $this->container->get('current_user');
    $before = $proxy->getAccount();
    $armed = TRUE;
    // Only the swap to the freshly loaded asker (same id, another object)
    // throws: search processors switch accounts through core's
    // AccountSwitcher too, which isn't the code under test.
    $this->container->get('event_dispatcher')->addListener(AccountEvents::SET_USER, static function (AccountSetEvent $event) use (&$armed, $before): void {
      $account = $event->getAccount();
      if ($armed && $account !== $before && (int) $account->id() === (int) $before->id()) {
        $armed = FALSE;
        throw new \RuntimeException('fictional subscriber failure');
      }
    });
    try {
      if ($door === 'tool') {
        $this->container->get('ai_assistant_api.runner')->setContext(['group_id' => (int) $this->groups['G']->id()]);
        $tool = $this->container->get('plugin.manager.ai.function_calls')->createInstance('ai_search:rag_search');
        $tool->setContextValue('index', 'social_posts');
        $tool->setContextValue('search_string', 'fictional');
        $tool->execute();
      }
      else {
        $this->container->get('social_ai_indexing.ai_overview')->retrieve('fictional', $proxy);
      }
    }
    catch (\RuntimeException) {
      // Expected: the subscriber threw.
    }
    $this->assertFalse($armed, "[$door] The subscriber ran and threw.");
    $this->assertSame($before, $proxy->getAccount(), "[$door] current_user holds the original account after the throw.");
  }

  /**
   * The two doors.
   */
  public static function doors(): array {
    return ['tool' => ['tool'], 'overview' => ['overview']];
  }

  /**
   * The reset is request-local: no persistent cache tag is invalidated.
   *
   * getStorage()->resetCache() with no ids would invalidate 'node_values'
   * and the like site-wide, emptying the entity cache on every answer.
   */
  public function testResetLeavesPersistentCachesAlone(): void {
    $tags = array_map(static fn (string $type): string => $type . '_values', ['node', 'comment', 'group', 'group_content']);
    $checksum = $this->container->get('cache_tags.invalidator.checksum');
    $before = $checksum->getCurrentChecksum($tags);
    $persistent = ['cache.default', 'cache.entity', 'cache.config', 'cache.group_memberships', 'cache.flexible_permissions', 'cache.access_policy'];
    foreach ($persistent as $bin) {
      $this->container->get($bin)->set('rq13_guard', 'kept');
    }
    \Drupal\social_ai_indexing\Access\RetrievalAccessCache::fresh($this->container->get('entity_type.manager'), $this->personas['P-MEM']);
    $checksum->reset();
    $this->assertSame($before, $checksum->getCurrentChecksum($tags), 'No *_values tag was invalidated by the reset.');
    foreach ($persistent as $bin) {
      $this->assertNotFalse($this->container->get($bin)->get('rq13_guard'), "The persistent bin $bin keeps its entries.");
    }
  }

  /**
   * Asks one door as $account.
   *
   * @return array{text: string, keys: list<string>}
   *   The tool: its output (what goes into the prompt) and the registry
   *   keys (what can be cited), scoped to G. The overview: the text and
   *   entity keys of its retrieval, which is all its prompt is built from.
   */
  private function ask(AccountInterface $account, string $door): array {
    if ($door === 'overview') {
      $this->setCurrentUser($account);
      $text = '';
      $keys = [];
      foreach ($this->container->get('social_ai_indexing.ai_overview')->retrieve('fictional', $account) as $result) {
        $keys[] = $result['entity']->getEntityTypeId() . ':' . $result['entity']->id();
        $text .= "\n" . $result['entity']->label() . "\n" . ($result['entity']->hasField('body') ? $result['entity']->get('body')->value : '') . ($result['entity']->hasField('field_comment_body') ? $result['entity']->get('field_comment_body')->value : '');
      }
      return ['text' => $text, 'keys' => $keys];
    }
    $this->resetSourceRegistry();
    $group_g = (int) $this->groups['G']->id();
    $text = '';
    foreach (['social_posts', 'social_comments'] as $index_id) {
      $text .= "\n" . $this->runRagTool($index_id, $account, $group_g)->getReadableOutput();
    }
    $keys = array_map(static fn ($s): string => $s->key(), $this->container->get('social_ai_indexing.source_registry')->all());
    return ['text' => $text, 'keys' => $keys];
  }

  /**
   * Asserts an entity and its text reached neither door.
   */
  private function assertNothingFrom(array $asked, string $key, array $canaries, string $label): void {
    foreach ($canaries as $canary) {
      $this->assertStringNotContainsString($canary, $asked['text'], "STALE ACCESS LEAK: $label got '$canary'.");
    }
    $this->assertNotContains($key, $asked['keys'], "STALE ACCESS LEAK: $label: $key got through.");
  }

}
