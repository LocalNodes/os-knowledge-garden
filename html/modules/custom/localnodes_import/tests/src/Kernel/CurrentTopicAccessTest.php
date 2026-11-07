<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Exception\RefreshConflictException;
use Drupal\localnodes_import\Exception\RefreshNotAllowedException;
use Drupal\node\NodeInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\FixtureWeb;
use Drupal\user\UserInterface;

/**
 * The importer follows a Topic's current access and current group.
 *
 * Phase 22 merge review, round 1. An import item remembers the group a Topic
 * was imported into, but a Topic can later move to another group, a secret
 * one included, and be renamed there. What the importer shows or does with
 * a Topic must follow where the Topic is now and who may see or change it,
 * never the import history:
 * - item 1: the run report and the search status show no Topic the viewer
 *   can't view, and the status counts only Topics still in the group;
 * - item 2: "Refresh from source" needs update access to the Topic as well
 *   as the group's import permission, and the Topic must still be in the
 *   group when it is saved;
 * - round 2: the check before the save sees what changed during the fetch
 *   (owner, import permission, a concurrent edit), and a duplicate must be
 *   a Topic the importer may view;
 * - item 4: a Topic that left the group is no longer "already here" when
 *   the same file or address is imported into the group again.
 *
 * The Topics go through the real import path (ImportBatch::importItem());
 * "moving" a Topic removes its relationship to G and adds one to secret H,
 * as a site manager moving it would.
 *
 * @group phase22
 * @group localnodes_import
 */
final class CurrentTopicAccessTest extends ImportKernelTestBase {

  /**
   * A fixture PDF with a text layer: imports as "Tool Share Rules".
   */
  private const FILE = 'pdf-01-tool-share-rules.pdf';

  /**
   * A second fixture PDF: imports as "Orchard Pruning Notes".
   */
  private const OTHER_FILE = 'pdf-02-orchard-pruning-notes.pdf';

  /**
   * A P2P fixture page (FixtureWeb): imports as "Bioregionalism".
   */
  private const ADDRESS = 'https://wiki.p2pfoundation.net/Bioregionalism';

  /**
   * What a concurrent edit writes into the Topic during the fetch.
   */
  private const CONCURRENT_EDIT = 'Moderator note: the meeting moved to Thursday.';

  /**
   * The refusal when the page changed during its refresh (merge review R2).
   */
  private const CHANGED_MESSAGE = 'This page changed while it was being refreshed. Try again.';

  /**
   * The title the moved Topic gets in the secret group.
   */
  private const SECRET_TITLE = 'Confidential Land Deal Notes';

  /**
   * The fake web behind the fetcher (no network).
   */
  private FixtureWeb $web;

  /**
   * The group the Topics are imported into (G).
   */
  private GroupInterface $group;

  /**
   * A secret group the Topic moves to (H).
   */
  private GroupInterface $secret;

  /**
   * The organizer of G who imports the Topics (A), not in H.
   */
  private UserInterface $importer;

  /**
   * Another organizer of G (B), not in H.
   */
  private UserInterface $colleague;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('module_handler')->loadInclude('localnodes_import', 'install');
    _localnodes_import_create_reference_term();
    $this->web = FixtureWeb::p2p();
    $this->container->set('localnodes_import.safe_fetcher', $this->web->fetcher());

    $this->group = $this->createFlexibleGroup('Garden circle');
    $this->secret = $this->createSecretGroup('Land trust board');
    $this->importer = $this->createVerifiedUser('garden_importer');
    $this->colleague = $this->createVerifiedUser('garden_colleague');
    $this->addManager($this->group, $this->importer);
    $this->addManager($this->group, $this->colleague);
    $this->setCurrentUser($this->importer);
  }

  /**
   * G's report shows a Topic moved to secret H by its file name only.
   */
  public function testReportHidesMovedTopic(): void {
    [$run, $item] = $this->importFile(self::FILE);
    $topic = $this->topicOf($item);
    $nid = (int) $topic->id();

    // Positive control: before the move, the other organizer sees the title.
    $this->setCurrentUser($this->colleague);
    $html = $this->renderReport($run);
    $this->assertStringContainsString('Tool Share Rules', $html, 'Before the move the title shows');
    $this->assertStringContainsString('href="/node/' . $nid . '"', $html, 'Before the move the title links the Topic');

    $this->moveToSecret($topic, self::SECRET_TITLE);
    $topic = $this->reloadNode($nid);
    $this->assertFalse($topic->access('view', $this->colleague), 'Precondition: the other organizer of G cannot view the moved Topic');

    $build = $this->reportBuild($run);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString(self::SECRET_TITLE, $html, 'The new title stays hidden');
    $this->assertStringNotContainsString('/node/' . $nid, $html, 'No link to the moved Topic');
    $this->assertStringContainsString(self::FILE, $html, 'The row shows the file name as imported');
    $this->assertContains('node:' . $nid, $build['#cache']['tags'] ?? [], 'The report is invalidated when the Topic changes');

    // A refresh run's summary names its page: it must not name this one.
    $refresh = $this->createRun(ImportRun::KIND_WEB);
    ImportItem::create([
      'run' => $refresh->id(),
      'gid' => $this->group->id(),
      'source_name' => 'https://example.org/land-deal',
      'outcome' => ImportItem::OUTCOME_REFRESHED,
      'nid' => $nid,
    ])->save();
    $html = $this->renderReport($refresh);
    $this->assertStringNotContainsString(self::SECRET_TITLE, $html, 'The refresh summary does not name the moved Topic');
    $this->assertStringNotContainsString('/node/' . $nid, $html, 'No link from the refresh report');
    $this->assertStringContainsString('https://example.org/land-deal', $html, 'The refresh report names the address instead');
  }

  /**
   * The search status neither counts nor lists a Topic moved out of G.
   */
  public function testStatusLeavesOutMovedTopic(): void {
    [$run, $moved_item] = $this->importFile(self::FILE);
    $this->importFile(self::OTHER_FILE);
    $moved = $this->topicOf($moved_item);
    $nid = (int) $moved->id();
    $gid = (int) $this->group->id();

    $this->setCurrentUser($this->colleague);
    $this->assertSame(2, $this->status()->forGroup($gid)['total'], 'Positive control: both Topics count before the move');
    $this->assertSame(1, $this->status()->forRun((int) $run->id())['total']);

    $this->moveToSecret($moved, self::SECRET_TITLE);

    $status = $this->status()->forGroup($gid);
    $this->assertSame(1, $status['total'], 'The moved Topic no longer counts for G');
    $this->assertSame(1, $status['waiting']);
    $this->assertSame(0, $this->status()->forRun((int) $run->id())['total'], 'Nor for its import run');
    $titles = array_map(static fn (array $row): string => (string) $row['title'], $this->status()->waitingList($gid));
    $this->assertSame(['Orchard Pruning Notes'], $titles, 'Only the Topic still in G is listed');
    $urls = array_column($this->status()->waitingList($gid), 'url');
    $this->assertNotContains('/node/' . $nid, $urls, 'No link to the moved Topic');

    // Someone who can still view the moved Topic (an organizer of G who is
    // also in H) doesn't see it counted for G either: it isn't in G now.
    $both = $this->createVerifiedUser('in_both_groups');
    $this->addManager($this->group, $both);
    $this->addMember($this->secret, $both);
    $this->assertTrue($this->reloadNode($nid)->access('view', $both), 'Precondition: a member of H can view the moved Topic');
    $this->assertSame(1, $this->status()->forGroup($gid, $both)['total'], 'Only Topics currently in G count');
    $this->assertSame(['Orchard Pruning Notes'], array_map(static fn (array $row): string => (string) $row['title'], $this->status()->waitingList($gid, 1, $both)));
  }

  /**
   * An organizer of G who can't edit A's Topic can't refresh it.
   *
   * B has the group's import permission (bulk-create) but no update access
   * to a Topic A imported: the route is a 403 and the service refuses.
   */
  public function testRefreshNeedsUpdateAccess(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $topic = $this->topicOf($item);
    $this->assertTrue($this->group->hasPermission('import content into group', $this->colleague), 'Precondition: B may import into G');
    $this->assertTrue($topic->access('view', $this->colleague), 'Precondition: B can view the Topic');
    $this->assertFalse($topic->access('update', $this->colleague), "Precondition: B can't edit A's Topic");
    $this->assertTrue($topic->access('update', $this->importer), 'Precondition: A can edit it');

    $access = $this->container->get('access_manager');
    $params = ['group' => $this->group->id(), 'topic' => $topic->id()];
    $this->assertTrue($access->checkNamedRoute('localnodes_import.refresh', $params, $this->importer), 'Positive control: A can open the refresh');
    $this->assertFalse($access->checkNamedRoute('localnodes_import.refresh', $params, $this->colleague), 'B gets 403 on the refresh route');

    $this->setCurrentUser($this->colleague);
    $this->assertRefreshRefused($topic, 'B');
  }

  /**
   * A Topic that left the group is never refreshed from it.
   *
   * A is in both groups, so A may still edit the Topic in H; only its
   * relationship to G is gone.
   */
  public function testRefreshRefusesMovedTopic(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $topic = $this->topicOf($item);
    $this->addManager($this->secret, $this->importer);
    $this->moveToSecret($topic, self::SECRET_TITLE);
    $topic = $this->reloadNode((int) $topic->id());
    $this->assertTrue($topic->access('update', $this->importer), 'Precondition: A can still edit the moved Topic');

    $access = $this->container->get('access_manager');
    $params = ['group' => $this->group->id(), 'topic' => $topic->id()];
    $this->assertFalse($access->checkNamedRoute('localnodes_import.refresh', $params, $this->importer), "The refresh route for G is a 403: the Topic isn't in G");
    $this->assertRefreshRefused($topic, 'A');
  }

  /**
   * The same file imported again after its Topic left G makes a new Topic.
   */
  public function testMovedFileIsNotDuplicate(): void {
    [, $first] = $this->importFile(self::FILE);
    $moved = $this->topicOf($first);
    $this->moveToSecret($moved, self::SECRET_TITLE);

    // The second upload of the same bytes, stored under its own name.
    [, $again] = $this->importFile(self::FILE, 'pdf-01-tool-share-rules_0.pdf');
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $again->get('outcome')->value, 'Not "Already here": ' . $again->get('outcome')->value . ' / ' . $again->get('reason')->value);
    $topic = $this->topicOf($again);
    $this->assertNotSame((int) $moved->id(), (int) $topic->id(), 'G gets a new Topic');
    $this->assertSame([(int) $topic->id()], $this->groupTopicIds($this->group), 'The new Topic is in G');

    $file = File::load((int) $again->get('fid')->value);
    $this->assertNotNull($file, 'The new upload is kept');
    $this->assertFileExists($file->getFileUri(), 'Its bytes are kept');
    $this->assertTrue($file->isPermanent(), 'It is attached for good');
    $this->assertSame((int) $file->id(), (int) $topic->get('field_files')->target_id, 'It is the new Topic\'s file');
    $this->assertSame(self::SECRET_TITLE, $this->reloadNode((int) $moved->id())->label(), 'The moved Topic is untouched');
  }

  /**
   * The same address imported again after its Topic left G makes a new one.
   */
  public function testMovedPageIsNotDuplicate(): void {
    [, $first] = $this->importWeb(self::ADDRESS);
    $moved = $this->topicOf($first);
    $this->moveToSecret($moved, self::SECRET_TITLE);

    [, $again] = $this->importWeb(self::ADDRESS);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $again->get('outcome')->value, 'Not "Already here": ' . $again->get('outcome')->value . ' / ' . $again->get('reason')->value);
    $topic = $this->topicOf($again);
    $this->assertNotSame((int) $moved->id(), (int) $topic->id(), 'G gets a new Topic');
    $this->assertSame([(int) $topic->id()], $this->groupTopicIds($this->group), 'The new Topic is in G');
    $this->assertSame('Bioregionalism', $topic->label());
  }

  /**
   * The Topic gets a new owner during the fetch: A may no longer edit it.
   */
  public function testRefreshRefusedWhenOwnerChangesMidFetch(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $nid = (int) $this->topicOf($item)->id();
    [$run, $thrown] = $this->refreshWithChangeMidFetch($nid, function () use ($nid): void {
      $this->reloadNode($nid)->setOwnerId((int) $this->colleague->id())->save();
    });
    $this->assertInstanceOf(RefreshNotAllowedException::class, $thrown, 'Refused: A may no longer edit the page');
    $this->assertSame((int) $this->colleague->id(), (int) $this->reloadNode($nid)->getOwnerId(), 'The new owner is kept');
    $this->assertRowRefused($run, 'not_allowed');
  }

  /**
   * Another person saves a new revision during the fetch: their edit stays.
   */
  public function testRefreshKeepsConcurrentEdit(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $nid = (int) $this->topicOf($item)->id();
    [$run, $thrown] = $this->refreshWithChangeMidFetch($nid, function () use ($nid): void {
      $node = $this->reloadNode($nid);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) $this->colleague->id());
      $node->get('body')->value = '<p>' . self::CONCURRENT_EDIT . '</p>';
      $node->save();
    });
    $this->assertInstanceOf(RefreshConflictException::class, $thrown, 'Refused: the page changed');
    $this->assertSame(self::CHANGED_MESSAGE, $thrown?->getMessage());
    $this->assertStringContainsString(self::CONCURRENT_EDIT, (string) $this->reloadNode($nid)->get('body')->value, 'The concurrent edit is kept');
    $this->assertRowRefused($run, 'changed');
    $this->assertStringContainsString(self::CHANGED_MESSAGE, html_entity_decode($this->renderReport($run), ENT_QUOTES), 'The report says why');
  }

  /**
   * The import permission is taken away during the fetch: refused.
   */
  public function testRefreshRefusedWhenImportPermissionGoesMidFetch(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $nid = (int) $this->topicOf($item)->id();
    [$run, $thrown] = $this->refreshWithChangeMidFetch($nid, function (): void {
      GroupRole::load('flexible_group-group_manager')->revokePermission('import content into group')->save();
    });
    $this->assertFalse($this->group->hasPermission('import content into group', $this->importer), 'Precondition: A lost the import permission');
    $this->assertInstanceOf(RefreshNotAllowedException::class, $thrown, 'Refused: A may no longer import into G');
    $this->assertRowRefused($run, 'not_allowed');
  }

  /**
   * Another process removes A from G during the fetch: refused.
   *
   * Merge review R3 item 1. As in RQ13StaleAccessTest, the other process is
   * simulated in the database: the membership rows go, and only the
   * persistent caches that its delete invalidates are dropped. Group's
   * membership and permission memory caches here stay warm from the check
   * before the fetch, as they would in a long batch request.
   */
  public function testRefreshRefusedWhenMemberRemovedElsewhere(): void {
    [, $item] = $this->importWeb(self::ADDRESS);
    $nid = (int) $this->topicOf($item)->id();
    $relationship_id = (int) $this->group->getMember($this->importer)->getGroupRelationship()->id();
    [$run, $thrown] = $this->refreshWithChangeMidFetch($nid, function () use ($relationship_id): void {
      $database = $this->container->get('database');
      $mapping = $this->relationshipTableMapping();
      foreach (array_filter($mapping->getDedicatedTableNames(), [$database->schema(), 'tableExists']) as $table) {
        $database->delete($table)->condition('entity_id', $relationship_id)->execute();
      }
      foreach ([$mapping->getDataTable(), $mapping->getBaseTable()] as $table) {
        $database->delete($table)->condition('id', $relationship_id)->execute();
      }
      $this->dropSharedCachesOf($relationship_id);
    });
    $this->assertInstanceOf(RefreshNotAllowedException::class, $thrown, 'Refused: A is no longer in G');
    $this->assertRowRefused($run, 'not_allowed');
  }

  /**
   * Another process takes away the role that lets A edit: refused.
   *
   * A stays an organizer of G (import permission), but only an individual
   * editor role lets A update their own Topics. That role is removed from
   * A's membership in the database during the fetch (merge review R3
   * item 1).
   */
  public function testRefreshRefusedWhenEditorRoleRemovedElsewhere(): void {
    $permission = 'update own group_node:topic entity';
    foreach (['flexible_group-group_manager', 'flexible_group-member'] as $role_id) {
      GroupRole::load($role_id)->revokePermission($permission)->save();
    }
    GroupRole::create([
      'id' => 'flexible_group-ln_editor',
      'label' => 'Editor',
      'group_type' => 'flexible_group',
      'scope' => 'individual',
      'permissions' => [$permission],
    ])->save();
    $membership = $this->group->getMember($this->importer);
    $membership->addRole('flexible_group-ln_editor');

    [, $item] = $this->importWeb(self::ADDRESS);
    $nid = (int) $this->topicOf($item)->id();
    $this->assertTrue($this->reloadNode($nid)->access('update', $this->importer), 'Precondition: the editor role lets A edit');
    $relationship_id = (int) $membership->getGroupRelationship()->id();
    [$run, $thrown] = $this->refreshWithChangeMidFetch($nid, function () use ($relationship_id): void {
      $this->container->get('database')->delete($this->relationshipTableMapping()->getFieldTableName('group_roles'))
        ->condition('entity_id', $relationship_id)
        ->condition('group_roles_target_id', 'flexible_group-ln_editor')
        ->execute();
      $this->dropSharedCachesOf($relationship_id);
    });
    $this->assertInstanceOf(RefreshNotAllowedException::class, $thrown, 'Refused: A may no longer edit the page');
    $this->assertRowRefused($run, 'not_allowed');
    $this->assertTrue((bool) $this->group->getMember($this->importer), 'A is still in G');
  }

  /**
   * The group relationship table mapping.
   */
  private function relationshipTableMapping(): DefaultTableMapping {
    $mapping = $this->container->get('entity_type.manager')->getStorage('group_content')->getTableMapping();
    $this->assertInstanceOf(DefaultTableMapping::class, $mapping);
    return $mapping;
  }

  /**
   * Drops what another process's change to a membership invalidates.
   *
   * Only the shared (persistent) caches: the relationship's entity entry,
   * calculated permissions and Group's membership cache. Every memory cache
   * in this process stays warm.
   */
  private function dropSharedCachesOf(int $relationship_id): void {
    $this->container->get('cache.entity')->delete('values:group_content:' . $relationship_id);
    $this->container->get('cache.flexible_permissions')->deleteAll();
    $this->container->get('cache.group_memberships')->deleteAll();
  }

  /**
   * A file whose Topic A can't view is not "already here" for A.
   *
   * B's Topic from the same bytes is in G but unpublished, so A can't see
   * it: telling A it exists would reveal it.
   */
  public function testHiddenTopicIsNotDuplicateOfFile(): void {
    $this->setCurrentUser($this->colleague);
    [, $theirs] = $this->importFile(self::FILE, NULL, $this->colleague);
    $hidden = $this->hide($this->topicOf($theirs));

    $this->setCurrentUser($this->importer);
    [$run, $mine] = $this->importFile(self::FILE, 'pdf-01-tool-share-rules_0.pdf');
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $mine->get('outcome')->value, 'Not "Already here": ' . $mine->get('outcome')->value);
    $topic = $this->topicOf($mine);
    $this->assertNotSame((int) $hidden->id(), (int) $topic->id(), 'A gets a Topic of their own');
    $file = File::load((int) $mine->get('fid')->value);
    $this->assertNotNull($file, 'The upload is kept');
    $this->assertFileExists($file->getFileUri());
    $this->assertSame((int) $file->id(), (int) $topic->get('field_files')->target_id);
    $html = $this->renderReport($run);
    $this->assertStringNotContainsString('Already here', $html);
    $this->assertStringNotContainsString('already in the group', $html);
  }

  /**
   * An address whose Topic A can't view is not "already here" for A.
   */
  public function testHiddenTopicIsNotDuplicateOfPage(): void {
    $this->setCurrentUser($this->colleague);
    [, $theirs] = $this->importWeb(self::ADDRESS, $this->colleague);
    $hidden = $this->hide($this->topicOf($theirs));

    $this->setCurrentUser($this->importer);
    [$run, $mine] = $this->importWeb(self::ADDRESS);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $mine->get('outcome')->value, 'Not "Already here": ' . $mine->get('outcome')->value);
    $this->assertNotSame((int) $hidden->id(), (int) $this->topicOf($mine)->id(), 'A gets a Topic of their own');
    $html = $this->renderReport($run);
    $this->assertStringNotContainsString('Already here', $html);
    $this->assertStringNotContainsString('already in the group', $html);
  }

  /**
   * Unpublishes B's Topic and checks A can't view it but it is still in G.
   */
  private function hide(NodeInterface $topic): NodeInterface {
    $topic->setUnpublished()->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $topic = $this->reloadNode((int) $topic->id());
    $this->assertFalse($topic->access('view', $this->importer), "Precondition: A can't view B's unpublished Topic");
    $this->assertNotSame([], $this->group->getRelationshipsByEntity($topic), 'Precondition: it is still in G');
    return $topic;
  }

  /**
   * Refreshes a Topic as A, making a change while its page is fetched.
   *
   * @return array{0: \Drupal\localnodes_import\Entity\ImportRun, 1: \Throwable|null}
   *   The refresh run and what the service threw.
   */
  private function refreshWithChangeMidFetch(int $nid, \Closure $change): array {
    $changed = FALSE;
    $after = NULL;
    $this->web->onRequest = function (string $method, string $url) use (&$changed, &$after, $change, $nid): void {
      if ($changed || !str_contains($url, 'action=parse')) {
        return;
      }
      $changed = TRUE;
      $change();
      $after = (int) $this->reloadNode($nid)->getRevisionId();
    };
    $run = $this->createRun(ImportRun::KIND_WEB);
    $thrown = NULL;
    try {
      $this->container->get('localnodes_import.refresh_from_source')->refresh($this->reloadNode($nid), $run);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->web->onRequest = NULL;
    $this->assertTrue($changed, 'The change happened while the page was fetched');
    $this->assertSame($after, (int) $this->reloadNode($nid)->getRevisionId(), 'The refresh saved nothing over the change');
    return [$run, $thrown];
  }

  /**
   * The refresh run's one row is failed with the reason.
   */
  private function assertRowRefused(ImportRun $run, string $reason): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $rows = $storage->loadByProperties(['run' => $run->id()]);
    $this->assertCount(1, $rows);
    $row = reset($rows);
    $this->assertSame([ImportItem::OUTCOME_FAILED, $reason], [$row->get('outcome')->value, $row->get('reason')->value]);
  }

  /**
   * Calls the refresh service directly and checks it refused, saving nothing.
   */
  private function assertRefreshRefused(NodeInterface $topic, string $who): void {
    $revision = (int) $topic->getRevisionId();
    $requests = count($this->web->requests);
    $run = $this->createRun(ImportRun::KIND_WEB);
    $thrown = NULL;
    try {
      $this->container->get('localnodes_import.refresh_from_source')->refresh($topic, $run);
    }
    catch (\Throwable $e) {
      $thrown = $e;
    }
    $this->assertSame($revision, (int) $this->reloadNode((int) $topic->id())->getRevisionId(), "$who: the Topic is never saved");
    $this->assertInstanceOf(RefreshNotAllowedException::class, $thrown, "$who: the service refuses with its own exception");
    $this->assertCount($requests, $this->web->requests, "$who: nothing is fetched");
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $rows = $storage->loadByProperties(['run' => $run->id()]);
    $this->assertCount(1, $rows, "$who: the refresh run keeps its one row");
    $row = reset($rows);
    $result = [$row->get('outcome')->value, $row->get('reason')->value];
    $this->assertSame([ImportItem::OUTCOME_FAILED, 'not_allowed'], $result, "$who: recorded as refused");
  }

  /**
   * Moves a Topic from G to the secret group H, then renames it there.
   *
   * The rename is the last save, so the Topic's access is worked out again
   * with its new group.
   */
  private function moveToSecret(NodeInterface $topic, string $new_title): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('group_content');
    foreach ($storage->loadByEntity($topic) as $relationship) {
      if ((int) $relationship->getGroupId() === (int) $this->group->id()) {
        $relationship->delete();
      }
    }
    $this->secret->addRelationship($topic, 'group_node:topic');
    $topic = $this->reloadNode((int) $topic->id());
    $topic->setTitle($new_title)->save();
    // The next page load is a new request: no access answer from before the
    // move is remembered.
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->assertSame([], $this->group->getRelationshipsByEntity($topic), 'Precondition: the Topic left G');
  }

  /**
   * Imports one fixture file into G and returns its run and item.
   *
   * @param string $name
   *   The fixture file name.
   * @param string|null $stored_name
   *   The name the upload is stored under (a second upload of the same
   *   file gets its own name, as core gives it).
   * @param \Drupal\user\UserInterface|null $owner
   *   Who imports; A when NULL.
   *
   * @return array{0: \Drupal\localnodes_import\Entity\ImportRun, 1: \Drupal\localnodes_import\Entity\ImportItem}
   *   The finished run and its one item.
   */
  private function importFile(string $name, ?string $stored_name = NULL, ?UserInterface $owner = NULL): array {
    $stored_name ??= $name;
    $run = $this->createRun(ImportRun::KIND_FILES, $owner);
    $folder = 'private://localnodes_import/' . $this->group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $this->assertTrue(copy(dirname(__DIR__, 2) . '/fixtures/import/files/' . $name, $file_system->realpath($folder) . '/' . $stored_name));
    $file = File::create([
      'uri' => "$folder/$stored_name",
      'filename' => $name,
      'uid' => ($owner ?? $this->importer)->id(),
      'status' => 0,
    ]);
    $file->save();
    $item = ImportItem::create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'source_name' => $name,
      'fid' => $file->id(),
      'outcome' => ImportItem::OUTCOME_QUEUED,
    ]);
    $item->save();
    return $this->runItems($run, [(int) $item->id()]);
  }

  /**
   * Imports one web address into G and returns its run and item.
   *
   * @param string $address
   *   The address.
   * @param \Drupal\user\UserInterface|null $owner
   *   Who imports; A when NULL.
   *
   * @return array{0: \Drupal\localnodes_import\Entity\ImportRun, 1: \Drupal\localnodes_import\Entity\ImportItem}
   *   The finished run and its one item.
   */
  private function importWeb(string $address, ?UserInterface $owner = NULL): array {
    $run = $this->createRun(ImportRun::KIND_WEB, $owner);
    $item = ImportItem::create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'source_name' => $address,
      'outcome' => ImportItem::OUTCOME_QUEUED,
    ]);
    $item->save();
    return $this->runItems($run, [(int) $item->id()]);
  }

  /**
   * Runs a run's items through the batch operation and closes the run.
   *
   * @return array{0: \Drupal\localnodes_import\Entity\ImportRun, 1: \Drupal\localnodes_import\Entity\ImportItem}
   *   The finished run and its first item.
   */
  private function runItems(ImportRun $run, array $item_ids): array {
    $context = [];
    foreach ($item_ids as $id) {
      ImportBatch::importItem($id, $context);
    }
    ImportBatch::finished(TRUE, $context['results'] ?? ['run_id' => $run->id()], [], '0');
    $entity_type_manager = $this->container->get('entity_type.manager');
    $entity_type_manager->getStorage('localnodes_import_item')->resetCache();
    $entity_type_manager->getStorage('localnodes_import_run')->resetCache();
    return [
      $entity_type_manager->getStorage('localnodes_import_run')->load($run->id()),
      $entity_type_manager->getStorage('localnodes_import_item')->load(reset($item_ids)),
    ];
  }

  /**
   * Creates an in-progress run in G, for G's members only; A's by default.
   */
  private function createRun(string $kind, ?UserInterface $owner = NULL): ImportRun {
    $owner ??= $this->importer;
    $run = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->create([
      'gid' => $this->group->id(),
      'uid' => $owner->id(),
      'kind' => $kind,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'group',
      'rights_confirmed_by' => $owner->id(),
      'rights_confirmed_at' => 1000,
    ]);
    $run->save();
    return $run;
  }

  /**
   * A fresh copy of the Topic an item points at.
   */
  private function topicOf(ImportItem $item): NodeInterface {
    $nid = $item->get('nid')->target_id;
    $this->assertNotNull($nid, 'The item links a Topic: ' . $item->get('outcome')->value . ' / ' . $item->get('reason')->value);
    return $this->reloadNode((int) $nid);
  }

  /**
   * The nids of a group's Topics, sorted.
   *
   * @return int[]
   *   Node ids.
   */
  private function groupTopicIds(GroupInterface $group): array {
    $ids = array_map(static fn ($relationship): int => (int) $relationship->getEntityId(), $group->getRelationships('group_node:topic'));
    sort($ids);
    return $ids;
  }

  /**
   * A fresh copy of a node.
   */
  private function reloadNode(int $nid): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$nid]);
    return $storage->load($nid);
  }

  /**
   * The search status service.
   */
  private function status(): object {
    return $this->container->get('localnodes_import.search_status');
  }

  /**
   * The report build for a run, through the real controller.
   */
  private function reportBuild(ImportRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $storage->resetCache([$run->id()]);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    return $controller->view($this->group, $storage->load($run->id()));
  }

  /**
   * The rendered report of a run.
   */
  private function renderReport(ImportRun $run): string {
    $build = $this->reportBuild($run);
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
