<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\activity_creator\Entity\Activity;
use Drupal\file\Entity\File;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\localnodes_import\Batch\ImportBatch;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\message\Entity\Message;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\user\UserInterface;
use Drupal\views\Entity\View;
use Psr\Log\LoggerInterface;

/**
 * A bulk import posts one summary, not one stream item per Topic (D-24).
 *
 * Without suppression each imported Topic fires Open Social's activity
 * chain (RESEARCH Pitfall 7): the node goes into the activity_logger_message
 * queue (later a create_topic_group message with notifications and email),
 * and its group relationship creates a create_topic_gc message for the
 * group stream. 21 files would mean 21 stream items and 21 notifications per
 * member.
 *
 * The activity stack is real here: activity_creator, activity_logger and
 * activity_basics hooks run on every save, and social_activity's
 * create_topic_gc template is installed, so a Topic a member adds by hand
 * produces its normal activity (the positive control).
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportActivityTest extends ImportKernelTestBase {

  use IndexFixtureTrait;

  /**
   * Fixture folder (22-04).
   */
  private const FIXTURES = __DIR__ . '/../../fixtures/import';

  /**
   * Three searchable fixture files (manifest: imported).
   */
  private const FILES = [
    'pdf-01-tool-share-rules.pdf',
    'pdf-02-orchard-pruning-notes.pdf',
    'pdf-03-rainwater-barrels.pdf',
  ];

  /**
   * The queue activity_basics puts a new node into (CreateActivityAction).
   */
  private const NODE_QUEUE = 'activity_logger_message';

  /**
   * The queue notification emails go through (activity_send_email).
   */
  private const EMAIL_QUEUE = 'activity_send_email_worker';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'dynamic_entity_reference',
    'message',
    'activity_creator',
    'activity_logger',
    'activity_basics',
    // template_preprocess_activity() loads the actor's profile.
    'profile',
    // Prints the activity's date link, as socialbase does (follow-up).
    'localnodes_import_activity_date_test',
  ];

  /**
   * Open Social's group Topics list (social_group config/optional).
   */
  private const TOPICS_ROUTE = 'view.group_topics.page_group_topics';

  /**
   * The run report route (22-17).
   */
  private const REPORT_ROUTE = 'localnodes_import.run_report';

  /**
   * The summary message template (D-24).
   */
  private const SUMMARY_TEMPLATE = 'localnodes_import_summary';

  /**
   * Records every log record: [level, message, context].
   */
  private LoggerInterface $spy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->spy = new class() implements LoggerInterface {
      use RfcLoggerTrait;

      /**
       * Every record: [level, message, context].
       */
      public array $records = [];

      /**
       * {@inheritdoc}
       */
      public function log($level, string|\Stringable $message, array $context = []): void {
        $this->records[] = [$level, (string) $message, $context];
      }

    };
    $this->container->get('logger.factory')->addLogger($this->spy);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUpBeforeImporter(): void {
    $this->installEntitySchema('message');
    $this->installEntitySchema('activity');
    $this->installEntitySchema('profile');
    $this->installSchema('activity_creator', ['activity_notification_status']);
    $this->installConfig(['message', 'activity_creator', 'activity_logger']);
    // The stream item Open Social posts for a Topic added to a group.
    $this->installShippedConfig('social_activity', [
      'message.template.create_topic_gc',
      'field.field.message.create_topic_gc.field_message_context',
      'field.field.message.create_topic_gc.field_message_destination',
      'field.field.message.create_topic_gc.field_message_related_object',
    ]);
  }

  /**
   * Three imported files create no per-Topic activity, message or email.
   */
  public function testImportCreatesNoPerTopicActivity(): void {
    $server = $this->createTestSearchServer();
    $this->createShippedIndex('social_posts', $server);
    $this->createShippedIndex('social_comments', $server);

    [$group, $organizer] = $this->groupWithOrganizer('Seed group', 'seed_organizer');
    $email_before = $this->queueSize(self::EMAIL_QUEUE);

    [$run] = $this->importFiles($group, $organizer, self::FILES);

    $nids = $this->importedNids($run);
    $this->assertCount(3, $nids, 'Three Topics imported');
    $relationship_ids = $this->relationshipIds($group, $nids);
    $this->assertCount(3, $relationship_ids, 'Each Topic is in the group');

    $queued = $this->queuedNodeIds(self::NODE_QUEUE);
    $this->assertSame([], array_values(array_intersect($nids, $queued)), 'No imported Topic is queued for per-Topic activity');
    $this->assertSame([], $this->messagesAbout('node', $nids), 'No message is about an imported Topic');
    // The run's one summary (Task 2) is anchored on a relationship; no
    // per-Topic message (create_topic_gc) is.
    $this->assertSame([], $this->messagesAbout('group_content', $relationship_ids, self::SUMMARY_TEMPLATE), 'No per-Topic message is about an imported Topic\'s group relationship');
    $this->assertSame($email_before, $this->queueSize(self::EMAIL_QUEUE), 'No notification email is queued');

    // Indexing is unaffected: each Topic is tracked for social_posts.
    foreach ($nids as $nid) {
      $node = Node::load($nid);
      $status = $this->container->get('database')->select('search_api_item', 's')
        ->fields('s', ['status'])
        ->condition('index_id', 'social_posts')
        ->condition('item_id', 'entity:node/' . $nid . ':' . $node->language()->getId())
        ->execute()
        ->fetchField();
      $this->assertSame('1', (string) $status, "Topic $nid is tracked for social_posts");
    }
  }

  /**
   * A Topic a member adds by hand still produces its normal activity.
   *
   * The positive control: suppression is import-only, and the activity
   * stack in this test does fire.
   */
  public function testHandMadeTopicKeepsItsActivity(): void {
    [$group, $organizer] = $this->groupWithOrganizer('Pond group', 'pond_organizer');
    $this->importFiles($group, $organizer, [self::FILES[0]]);

    $member = $this->createVerifiedUser('pond_member');
    $this->addMember($group, $member);
    $this->setCurrentUser($member);
    $node = Node::create([
      'type' => 'topic',
      'title' => 'Pond cleanup Saturday',
      'body' => ['value' => 'Bring boots.', 'format' => 'plain_text'],
      'field_content_visibility' => 'group',
      'uid' => $member->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $relationship = $group->addRelationship($node, 'group_node:topic');

    $this->assertContains((int) $node->id(), $this->queuedNodeIds(self::NODE_QUEUE), 'The hand-made Topic is queued for activity');
    $this->assertCount(1, $this->messagesAbout('group_content', [(int) $relationship->id()]), 'Its group relationship gets the create_topic_gc stream message');
  }

  /**
   * A run of three files posts exactly one summary to the group's stream.
   *
   * The related object is the group relationship of the run's first
   * imported Topic, so group_activity_context gives the group as the
   * recipient, as for Open Social's own create_topic_gc item. The item goes
   * to the group stream only: no notifications, no email.
   */
  public function testOneSummaryPerRun(): void {
    [$group, $organizer] = $this->groupWithOrganizer('Orchard group', 'orchard_organizer');
    [$run] = $this->importFiles($group, $organizer, self::FILES);

    $messages = $this->summaries();
    $this->assertCount(1, $messages, 'One summary message for the run');
    $message = reset($messages);
    $this->assertSame((int) $organizer->id(), (int) $message->getOwnerId(), 'Posted as the person who ran the import');
    $this->assertSame(3, (int) $message->get('field_localnodes_import_count')->value);
    $this->assertTrue($message->hasField('field_localnodes_import_run'), 'The summary has a run field (follow-up, decision A)');
    $this->assertSame((int) $run->id(), (int) $message->get('field_localnodes_import_run')->target_id, 'The summary names its run');
    $this->assertSame('group_activity_context', $message->get('field_message_context')->value);
    $this->assertSame(['stream_group'], array_column($message->get('field_message_destination')->getValue(), 'value'), 'Group stream only');

    $related = $message->get('field_message_related_object')->getValue()[0];
    $this->assertSame('group_content', $related['target_type']);
    $first_nid = $this->importedNids($run)[0];
    $this->assertSame($this->relationshipIds($group, [$first_nid]), [(int) $related['target_id']], 'Anchored on the first imported Topic\'s relationship');

    $text = (string) $this->container->get('activity_creator.activity_factory')->getMessageText($message)[0];
    $this->assertStringContainsString('3 documents added to this group', $text);
    foreach (['Tool Share Rules', 'pdf-01'] as $title) {
      $this->assertStringNotContainsString($title, $text, 'The summary names no document');
    }

    // Created directly (no cron): one activity, for the group, in its stream.
    $activities = Activity::loadMultiple();
    $this->assertCount(1, $activities, 'One activity for the whole run');
    $activity = reset($activities);
    $this->assertSame((int) $message->id(), (int) $activity->get('field_activity_message')->target_id);
    $this->assertSame([(int) $group->id()], array_map('intval', array_column($activity->get('field_activity_recipient_group')->getValue(), 'target_id')), 'The group is the recipient');
    $this->assertSame(['stream_group'], array_column($activity->get('field_activity_destinations')->getValue(), 'value'));
    $this->assertSame([], array_column($activity->get('field_activity_recipient_user')->getValue(), 'target_id'), 'No member is notified');
    $this->assertStringContainsString('3 documents added to this group', (string) $activity->get('field_activity_output_text')->value);

    // Finalising the finished run again posts nothing more.
    $this->container->get('localnodes_import.run_finalizer')->finalize($run);
    $this->assertCount(1, $this->summaries(), 'A second finalise posts no second summary');
    $this->assertCount(1, Activity::loadMultiple());
  }

  /**
   * Nothing imported, nothing posted; one Topic reads in the singular.
   */
  public function testNoSummaryWithoutImportedTopics(): void {
    [$group, $organizer] = $this->groupWithOrganizer('Meadow group', 'meadow_organizer');
    $this->importFiles($group, $organizer, [self::FILES[0]]);
    $messages = $this->summaries();
    $this->assertCount(1, $messages);
    $text = (string) $this->container->get('activity_creator.activity_factory')->getMessageText(reset($messages))[0];
    $this->assertStringContainsString('1 document added to this group', $text);

    // A duplicate of that file, a locked PDF and a spreadsheet: no Topic.
    [$run] = $this->importFiles($group, $organizer, [self::FILES[0], 'locked-budget.pdf', 'budget.xlsx']);
    $this->assertSame([], $this->importedNids($run), 'The second run created no Topic');
    $this->assertCount(1, $this->summaries(), 'A run with nothing imported posts no summary');
  }

  /**
   * One import_summary record per run: group, counts, N of M; no titles.
   */
  public function testImportSummaryLogLine(): void {
    [$group, $organizer] = $this->groupWithOrganizer('Kitchen group', 'kitchen_organizer');
    [$run] = $this->importFiles($group, $organizer, [
      ...self::FILES,
      'scanned-notice.pdf',
      self::FILES[0],
      'locked-budget.pdf',
      'budget.xlsx',
    ]);
    $this->container->get('localnodes_import.run_finalizer')->finalize($run);

    $records = array_values(array_filter($this->spy->records, static fn (array $record): bool => str_starts_with($record[1], 'import_summary')));
    $this->assertCount(1, $records, 'One import_summary record for the run, even after a second finalise');
    [, $message, $context] = $records[0];
    $this->assertSame('localnodes_import', $context['channel'] ?? NULL);
    $this->assertSame((int) $group->id(), (int) $context['@group']);
    $this->assertSame((int) $run->id(), (int) $context['@run']);
    $this->assertSame(3, (int) $context['@imported']);
    $this->assertSame(1, (int) $context['@imported_not_searchable']);
    $this->assertSame(1, (int) $context['@duplicate']);
    $this->assertSame(1, (int) $context['@failed']);
    $this->assertSame(1, (int) $context['@refused']);
    $this->assertSame(3, (int) $context['@searchable'], 'N searchable');
    $this->assertSame(4, (int) $context['@topics'], 'of M Topics');
    $this->assertSame(1, (int) $context['@summary'], 'The summary was posted');

    $logged = $message . ' ' . json_encode(array_map('strval', array_diff_key($context, ['backtrace' => 0])));
    foreach ([...self::FILES, 'scanned-notice', 'Tool Share Rules', 'Rainwater'] as $title) {
      $this->assertStringNotContainsString($title, $logged, 'No title or file name in the record');
    }
  }

  /**
   * A group manager's summary date leads to the run's report (decision A).
   *
   * A plain member of the same group gets the group's Topics list.
   */
  public function testSummaryDateLinkByRole(): void {
    $this->installTopicsView();
    [$group, $organizer] = $this->groupWithOrganizer('Willow group', 'willow_organizer');
    [$run] = $this->importFiles($group, $organizer, [self::FILES[0]]);
    $member = $this->createVerifiedUser('willow_member');
    $this->addMember($group, $member);
    $activity = $this->summaryActivity();

    $this->assertSame($this->reportUrl($run), $this->dateHref($activity, $organizer), 'The manager\'s date leads to the run report');
    $this->assertSame($this->topicsUrl($group), $this->dateHref($activity, $member), 'The member\'s date leads to the Topics list');
    $this->assertStringEndsWith('/group/' . $group->id() . '/topics', $this->topicsUrl($group));
  }

  /**
   * The render cache never serves one viewer's date link to another.
   *
   * Rendered with the render cache on, in both orders, each viewer twice
   * (the second render is the cache hit for that viewer).
   */
  public function testSummaryDateLinkIsCachedPerViewer(): void {
    $this->installTopicsView();
    [$group, $organizer] = $this->groupWithOrganizer('Hazel group', 'hazel_organizer');
    [$run] = $this->importFiles($group, $organizer, [self::FILES[0]]);
    $member = $this->createVerifiedUser('hazel_member');
    $this->addMember($group, $member);
    $activity = $this->summaryActivity();
    $report = $this->reportUrl($run);
    $topics = $this->topicsUrl($group);
    $render_cache = $this->container->get('cache.render');

    $render_cache->deleteAll();
    $this->assertSame($report, $this->dateHref($activity, $organizer), 'Manager first');
    $this->assertSame($topics, $this->dateHref($activity, $member), 'Then the member gets the Topics list, not the cached report link');
    $this->assertSame($report, $this->dateHref($activity, $organizer), 'Manager again (cached)');
    $this->assertSame($topics, $this->dateHref($activity, $member), 'Member again (cached)');

    $render_cache->deleteAll();
    $this->assertSame($topics, $this->dateHref($activity, $member), 'Member first');
    $this->assertSame($report, $this->dateHref($activity, $organizer), 'Then the manager gets the report, not the cached Topics link');
    $this->assertSame($topics, $this->dateHref($activity, $member), 'Member again (cached)');
    $this->assertSame($report, $this->dateHref($activity, $organizer), 'Manager again (cached)');
  }

  /**
   * A deleted run sends everyone, the manager too, to the Topics list.
   */
  public function testSummaryDateLinkWithoutRun(): void {
    $this->installTopicsView();
    [$group, $organizer] = $this->groupWithOrganizer('Alder group', 'alder_organizer');
    [$run] = $this->importFiles($group, $organizer, [self::FILES[0]]);
    $activity = $this->summaryActivity();
    $this->assertSame($this->reportUrl($run), $this->dateHref($activity, $organizer), 'The report link while the run exists (render cached)');

    $run->delete();
    $this->assertSame($this->topicsUrl($group), $this->dateHref($activity, $organizer), 'The Topics list once the run is gone');

    // An empty run field (a summary posted before the field existed).
    $message = Message::load($activity->get('field_activity_message')->target_id);
    if ($message->hasField('field_localnodes_import_run')) {
      $message->set('field_localnodes_import_run', NULL)->save();
    }
    $this->container->get('cache.render')->deleteAll();
    $this->assertSame($this->topicsUrl($group), $this->dateHref($activity, $organizer), 'The Topics list when the field is empty');
  }

  /**
   * Other stream items keep Open Social's date link (to their Topic).
   */
  public function testOtherActivityDateLinkUntouched(): void {
    $this->installTopicsView();
    [$group] = $this->groupWithOrganizer('Rowan group', 'rowan_organizer');
    $member = $this->createVerifiedUser('rowan_member');
    $this->addMember($group, $member);
    $this->setCurrentUser($member);
    $node = Node::create([
      'type' => 'topic',
      'title' => 'Seed swap Sunday',
      'body' => ['value' => 'Bring seeds.', 'format' => 'plain_text'],
      'field_content_visibility' => 'group',
      'uid' => $member->id(),
      'status' => NodeInterface::PUBLISHED,
    ]);
    $node->save();
    $relationship = $group->addRelationship($node, 'group_node:topic');
    $message_ids = $this->messagesAbout('group_content', [(int) $relationship->id()]);
    $this->assertCount(1, $message_ids, 'The create_topic_gc message');
    $message = Message::load(reset($message_ids));
    $this->assertSame('create_topic_gc', $message->getTemplate()->id());

    // The activity Open Social's queue worker would create for it.
    $activity = Activity::create([
      'user_id' => $member->id(),
      'created' => \Drupal::time()->getRequestTime() - 7200,
      'field_activity_message' => $message->id(),
      'field_activity_entity' => ['target_type' => 'group_content', 'target_id' => $relationship->id()],
      'field_activity_recipient_group' => $group->id(),
      'field_activity_destinations' => [['value' => 'stream_group']],
      'field_activity_output_text' => ['value' => '<p>Topic added</p>', 'format' => 'basic_html'],
    ]);
    $activity->save();

    $node_url = $node->toUrl()->toString();
    $this->assertSame($node_url, $this->dateHref($activity, $member), 'A member\'s date still leads to the Topic');
    $this->assertSame($node_url, $this->dateHref($activity, $this->createVerifiedUser('rowan_outsider')), 'So does anyone else\'s');
  }

  /**
   * Installs Open Social's group Topics view, so its route exists.
   */
  private function installTopicsView(): void {
    $this->installShippedConfig('social_group', ['core.entity_view_mode.group_content.teaser']);
    $path = $this->container->get('extension.list.module')->getPath('social_group') . '/config/optional';
    $data = (new FileStorage($path))->read('views.view.group_topics');
    $this->assertIsArray($data, 'social_group ships views.view.group_topics');
    if (View::load('group_topics') === NULL) {
      $this->container->get('entity_type.manager')->getStorage('view')->createFromStorageRecord($data)->save();
    }
    $this->container->get('router.builder')->rebuild();
    $this->assertNotEmpty($this->container->get('router.route_provider')->getRoutesByNames([self::TOPICS_ROUTE]), 'The Topics list route exists');
  }

  /**
   * The run's one summary activity, dated two hours ago.
   *
   * Two hours, so "@time ago" stays the same (and render cacheable) for the
   * whole test.
   */
  private function summaryActivity(): Activity {
    $activities = Activity::loadMultiple();
    $this->assertCount(1, $activities, 'One summary activity');
    $activity = reset($activities);
    $activity->set('created', \Drupal::time()->getRequestTime() - 7200)->save();
    return $activity;
  }

  /**
   * Renders the activity as $account and returns where its date leads.
   *
   * A fresh build each time, rendered with the render cache on.
   */
  private function dateHref(Activity $activity, AccountInterface $account): ?string {
    $this->setCurrentUser($account);
    // As on a new request: no entity (the message, its run) is reused from
    // memory, so a deleted run is really gone.
    $this->container->get('entity.memory_cache')->deleteAll();
    $storage = $this->container->get('entity_type.manager')->getStorage('activity');
    $build = $this->container->get('entity_type.manager')->getViewBuilder('activity')->view($storage->load($activity->id()), 'default');
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertSame(1, preg_match('~<div class="localnodes-import-test-date"><a href="([^"]*)"[^>]*>([^<]*)</a>~', $html, $match), 'The date is a link: ' . $html);
    $this->assertStringContainsString('ago', $match[2], 'The link text stays "@time ago"');
    return html_entity_decode($match[1]);
  }

  /**
   * The run report's URL.
   */
  private function reportUrl(ImportRun $run): string {
    return Url::fromRoute(self::REPORT_ROUTE, ['group' => $run->getGroupId(), 'localnodes_import_run' => $run->id()])->toString();
  }

  /**
   * The group's Topics list URL.
   */
  private function topicsUrl(GroupInterface $group): string {
    return Url::fromRoute(self::TOPICS_ROUTE, ['group' => $group->id()])->toString();
  }

  /**
   * Summary messages posted so far.
   *
   * @return \Drupal\message\Entity\Message[]
   *   The messages.
   */
  private function summaries(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('message');
    $storage->resetCache();
    return Message::loadMultiple($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('template', self::SUMMARY_TEMPLATE)
      ->execute());
  }

  /**
   * A group and its organizer (a group manager), as the current user.
   *
   * @return array
   *   [the group, the organizer].
   */
  private function groupWithOrganizer(string $label, string $name): array {
    $group = $this->createFlexibleGroup($label);
    $organizer = $this->createVerifiedUser($name);
    $this->addManager($group, $organizer);
    $this->setCurrentUser($organizer);
    return [$group, $organizer];
  }

  /**
   * Queues one item per fixture file, runs the batch, finishes it.
   *
   * @return array
   *   [the run, the item ids in order].
   */
  private function importFiles(GroupInterface $group, UserInterface $owner, array $file_names): array {
    $entity_type_manager = $this->container->get('entity_type.manager');
    $run = $entity_type_manager->getStorage('localnodes_import_run')->create([
      'gid' => $group->id(),
      'uid' => $owner->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      'visibility' => 'group',
    ]);
    $run->save();

    $folder = 'private://localnodes_import/' . $group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $ids = [];
    foreach (array_values($file_names) as $weight => $name) {
      // A stored name of its own per run and row, so a duplicate's deleted
      // temporary is never another row's file.
      $stored = 'r' . $run->id() . '-' . $weight . '-' . $name;
      $this->assertTrue(copy(self::FIXTURES . '/files/' . $name, $file_system->realpath($folder) . '/' . $stored));
      $file = File::create(['uri' => $folder . '/' . $stored, 'filename' => $stored, 'uid' => $owner->id(), 'status' => 0]);
      $file->save();
      $item = $entity_type_manager->getStorage('localnodes_import_item')->create([
        'run' => $run->id(),
        'gid' => $group->id(),
        'weight' => $weight,
        'source_name' => $name,
        'fid' => $file->id(),
        'outcome' => ImportItem::OUTCOME_QUEUED,
      ]);
      $item->save();
      $ids[] = (int) $item->id();
    }

    $context = [];
    foreach ($ids as $id) {
      ImportBatch::importItem($id, $context);
    }
    ImportBatch::finished(TRUE, $context['results'] ?? ['run_id' => $run->id()], [], '1 sec');
    $entity_type_manager->getStorage('localnodes_import_run')->resetCache([$run->id()]);
    return [$entity_type_manager->getStorage('localnodes_import_run')->load($run->id()), $ids];
  }

  /**
   * Node ids of the run's items that created a Topic.
   *
   * @return int[]
   *   The nids.
   */
  private function importedNids(ImportRun $run): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item');
    $storage->resetCache();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('run', $run->id())
      ->condition('outcome', [ImportItem::OUTCOME_IMPORTED, ImportItem::OUTCOME_NOT_SEARCHABLE], 'IN')
      ->execute();
    $nids = [];
    foreach ($storage->loadMultiple($ids) as $item) {
      $nids[] = (int) $item->get('nid')->target_id;
    }
    sort($nids);
    return $nids;
  }

  /**
   * Ids of the group relationships that hold these nodes in the group.
   *
   * @return int[]
   *   The relationship ids.
   */
  private function relationshipIds(GroupInterface $group, array $nids): array {
    $ids = [];
    foreach ($nids as $nid) {
      foreach (GroupRelationship::loadByEntity(Node::load($nid)) as $relationship) {
        if ((int) $relationship->getGroupId() === (int) $group->id()) {
          $ids[] = (int) $relationship->id();
        }
      }
    }
    return $ids;
  }

  /**
   * Entity ids in a database queue's items (data['entity_id']).
   *
   * @return int[]
   *   The ids.
   */
  private function queuedNodeIds(string $queue): array {
    $database = $this->container->get('database');
    if (!$database->schema()->tableExists('queue')) {
      return [];
    }
    $ids = [];
    foreach ($database->select('queue', 'q')->fields('q', ['data'])->condition('name', $queue)->execute()->fetchCol() as $data) {
      $data = unserialize($data, ['allowed_classes' => FALSE]);
      if (isset($data['entity_id'])) {
        $ids[] = (int) $data['entity_id'];
      }
    }
    return $ids;
  }

  /**
   * Number of items in a queue.
   */
  private function queueSize(string $queue): int {
    return (int) $this->container->get('queue')->get($queue)->numberOfItems();
  }

  /**
   * Ids of messages whose related object is one of these entities.
   *
   * Messages of $except_template, when given, are left out.
   *
   * @return int[]
   *   The message ids.
   */
  private function messagesAbout(string $entity_type_id, array $ids, ?string $except_template = NULL): array {
    if ($ids === []) {
      return [];
    }
    $query = $this->container->get('entity_type.manager')->getStorage('message')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_message_related_object.target_type', $entity_type_id)
      ->condition('field_message_related_object.target_id', $ids, 'IN');
    if ($except_template !== NULL) {
      $query->condition('template', $except_template, '<>');
    }
    $result = $query->execute();
    return array_values(array_map('intval', $result));
  }

}
