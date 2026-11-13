<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\Component\Serialization\Yaml;
use Drupal\localnodes_events_calendar\DemoContent\DemoDate;
use Drupal\localnodes_platform\Demo\DemoPlugins;
use Drupal\search_api\Entity\Server;
use Drupal\symfony_mailer_test\MailerTestServiceInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * A fresh demo install shows the calendar, a poll and welcomes.
 *
 * Loads each demo module the way docker/entrypoint.sh does, with the shared
 * plugin list that script reads, then checks what a visitor would see: events in
 * this month and the next, a quick poll with answers from group members, and
 * a welcome post at the top of every demo group's stream. Direct messages
 * are off, so no message thread is loaded. No mail reaches the fictional
 * demo addresses.
 *
 * Functional, because the demo content needs Open Social's profile.
 *
 * @group localnodes_demo
 * @group phase22_functional
 */
final class DemoFeaturesTest extends BrowserTestBase {

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    // The install's final cron run can reach search_api_solr_cron(), whose
    // request to Solr prints its response.
    ob_start();
    try {
      parent::setUp();
    }
    finally {
      ob_end_clean();
    }
    $solr = Server::load('social_solr');
    if ($solr !== NULL) {
      $backend_config = $solr->getBackendConfig();
      $backend_config['connector_config']['host'] = 'solr.invalid';
      $solr->setBackendConfig($backend_config)->save();
    }
    // As the entrypoint: the platform first, then the demo module. The
    // mailer's test module keeps every sent mail in state.
    $this->container->get('module_installer')->install(['localnodes_platform', 'symfony_mailer_test']);
    $this->rebuildAll();
  }

  /**
   * The demo modules whose content the entrypoint loads.
   */
  public static function demoModules(): array {
    return [
      'localnodes_demo' => ['localnodes_demo'],
      'boulder_demo' => ['boulder_demo'],
    ];
  }

  /**
   * A fresh install with the demo module has every feature to try.
   *
   * @dataProvider demoModules
   */
  public function testFreshDemoShowsTheFeatures(string $module): void {
    $this->container->get('module_installer')->install([$module]);
    $this->rebuildAll();
    $this->sentMails();

    $ids = $this->entrypointPluginIds($module);
    $this->assertContains(substr($ids[0], 0, strpos($ids[0], '_')) . '_quick_poll', $ids);
    $this->assertNotContains(substr($ids[0], 0, strpos($ids[0], '_')) . '_private_message', $ids, 'The entrypoint loads no message thread.');
    $this->load($ids);
    $this->runActivityQueues();

    $content = $this->moduleContent($module);
    $this->assertEventsFillTwoMonths();
    $this->assertPollAnswered($content);
    $this->assertWelcomeTopsEveryGroup($content);

    foreach ($this->sentMails() as $mail) {
      foreach ($mail->getTo() as $address) {
        $this->assertDoesNotMatchRegularExpression('/\.(example|test|invalid|localhost)$/', $address->getEmail(), 'No mail goes to a fictional demo address.');
      }
    }

    // A restart that loads again (the entrypoint's half-finished case) adds
    // nothing: every plugin skips the uuids that exist.
    $before = $this->counts();
    $this->load($ids);
    $this->assertSame($before, $this->counts());
  }

  /**
   * The plugin ids docker/entrypoint.sh loads for a demo module.
   *
   * The entrypoint asks drush localnodes-demo:plugins for the list and
   * passes it to social-demo:add, so the list is DemoPlugins::ids().
   */
  private function entrypointPluginIds(string $module): array {
    $script = file_get_contents(DRUPAL_ROOT . '/../docker/entrypoint.sh');
    $this->assertNotFalse($script);
    $this->assertStringContainsString('DEMO_PLUGINS="$($DRUSH localnodes-demo:plugins "$DEMO_MODULE")"', $script, 'The entrypoint reads the shared demo list.');
    $this->assertStringContainsString('$DRUSH social-demo:add $DEMO_PLUGINS', $script, 'The entrypoint loads the shared demo list.');
    $this->assertStringNotContainsString('${PLUGIN_PREFIX}_', $script, 'The entrypoint keeps no list of its own.');
    return DemoPlugins::ids($module);
  }

  /**
   * Runs plugins as drush social-demo:add does.
   */
  private function load(array $ids): void {
    // drush runs the load as one request that starts when the command does;
    // entities without their own date get that request's time.
    $this->container->get('request_stack')->push(Request::create('/'));
    $this->container->get('current_user')->setAccount(User::load(1));
    foreach ($this->container->get('plugin.manager.demo_content')->createInstances($ids) as $plugin) {
      $plugin->setProfile('');
      $plugin->createContent();
    }
    $this->container->get('current_user')->setAccount(User::getAnonymousUser());
    $this->container->get('request_stack')->pop();
  }

  /**
   * The module's poll, group and user YAML.
   */
  private function moduleContent(string $module): array {
    $dir = $this->container->get('extension.list.module')->getPath($module) . '/content/entity';
    $content = [];
    foreach (['quick-poll', 'group', 'user'] as $name) {
      $content[$name] = Yaml::decode(file_get_contents(DRUPAL_ROOT . "/$dir/$name.yml"));
    }
    return $content;
  }

  private function assertEventsFillTwoMonths(): void {
    $months = $this->container->get('database')
      ->query('SELECT SUBSTRING(d.field_event_date_value, 1, 7) AS month, COUNT(*) FROM {node__field_event_date} d GROUP BY month')
      ->fetchAllKeyed();
    foreach (['this', 'next'] as $which) {
      $month = substr(DemoDate::day("$which month day 1"), 0, 7);
      $this->assertGreaterThanOrEqual(3, $months[$month] ?? 0, "At least three events in $which month ($month).");
    }
  }

  private function assertPollAnswered(array $content): void {
    $repository = $this->container->get('entity.repository');
    $visitor = $repository->loadEntityByUuid('user', array_key_first($content['user']));
    foreach ($content['quick-poll'] as $uuid => $item) {
      $poll = $repository->loadEntityByUuid('poll', $uuid);
      $this->assertNotNull($poll, "The quick poll {$item['question']} exists.");
      $group = $repository->loadEntityByUuid('group', $item['group']);
      $this->assertEquals($group->id(), $poll->get('quick_poll_group')->target_id, 'The poll is in its group.');
      $votes = $this->container->get('database')
        ->query('SELECT uid, hostname FROM {poll_vote} WHERE pid = :pid', [':pid' => $poll->id()])
        ->fetchAllKeyed();
      $this->assertGreaterThanOrEqual(3, count($votes), 'The poll has answers.');
      $this->assertCount(count($item['answers']), $votes);
      foreach ($votes as $uid => $hostname) {
        $this->assertSame('', $hostname, 'A demo answer carries no address.');
        $this->assertNotFalse($group->getMember(User::load($uid)), 'Only group members answered.');
      }
      $this->assertArrayNotHasKey($visitor->id(), $votes, 'The first demo member can still answer.');
    }
  }

  /**
   * Each demo group's stream opens with its welcome post.
   */
  private function assertWelcomeTopsEveryGroup(array $content): void {
    $repository = $this->container->get('entity.repository');
    foreach (array_keys($content['group']) as $uuid) {
      $group = $repository->loadEntityByUuid('group', $uuid);
      $memberships = $group->getMembers();
      $this->assertNotEmpty($memberships, $group->label() . ' has members.');
      $member = $memberships[0]->getUser();
      $member->setPassword('demo-features-test')->save();
      $member->passRaw = 'demo-features-test';
      $this->drupalLogin($member);
      $this->drupalGet('/group/' . $group->id() . '/stream');
      $this->assertSession()->statusCodeEquals(200);
      $items = [];
      foreach ($this->getSession()->getPage()->findAll('css', '.block-views-blockactivity-stream-group-block-stream-group .stream-item') as $item) {
        // Skip the "Say something to the group" box. Posts carry a comment
        // form of their own, so look for the post field, not any form.
        if ($item->find('css', 'textarea[name="field_post[0][value]"]') === NULL) {
          $items[] = trim(preg_replace('/\s+/', ' ', $item->getText()));
        }
      }
      $this->assertNotEmpty($items, $group->label() . ' has a stream.');
      $top = implode("\n", array_map(fn($text) => substr($text, 0, 120), array_slice($items, 0, 3)));
      $this->assertStringContainsString('Welcome to', $items[0], $group->label() . "'s stream opens with its welcome post. Top items:\n" . $top);
      $this->assertStringContainsString($group->label(), $items[0]);
      $this->drupalLogout();
    }

    // Welcome posts hold nothing private: community visibility, so a
    // signed-in visitor who has not joined reads them.
    $storage = $this->container->get('entity_type.manager')->getStorage('post');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_post', 'Welcome to ', 'STARTS_WITH')->exists('field_recipient_group')->execute();
    $this->assertCount(count($content['group']), $ids, 'One welcome post per demo group.');
    $visitor = $this->drupalCreateUser();
    $visitor->addRole('verified');
    $visitor->save();
    foreach ($storage->loadMultiple($ids) as $post) {
      $this->assertSame('2', (string) $post->get('field_visibility')->value, 'Welcome post ' . $post->id() . ' is community visibility.');
      $this->assertTrue($post->access('view', $visitor), 'A signed-in non-member reads welcome post ' . $post->id() . '.');
    }
  }

  /**
   * Rows that a second load must not add to.
   */
  private function counts(): array {
    $database = $this->container->get('database');
    $counts = [];
    foreach (['node', 'post', 'comment', 'poll', 'poll_vote', 'group_relationship'] as $table) {
      $counts[$table] = (int) $database->query("SELECT COUNT(*) FROM {{$table}}")->fetchField();
    }
    return $counts;
  }

  /**
   * The mail sent so far, as captured by the mailer's test module; clears it.
   *
   * @return \Drupal\symfony_mailer\EmailInterface[]
   */
  private function sentMails(): array {
    $state = $this->container->get('state');
    $state->resetCache();
    $mails = $state->get(MailerTestServiceInterface::STATE_KEY, []);
    $state->delete(MailerTestServiceInterface::STATE_KEY);
    return $mails;
  }

  /**
   * Works through Open Social's activity queues, as cron would.
   */
  private function runActivityQueues(): void {
    $workers = $this->container->get('plugin.manager.queue_worker');
    $factory = $this->container->get('queue');
    $jobs = Queue::load('default');
    for ($round = 0; $round < 10; $round++) {
      $done = 0;
      foreach (array_keys($workers->getDefinitions()) as $name) {
        if (!str_starts_with($name, 'activity')) {
          continue;
        }
        $queue = $factory->get($name);
        $worker = $workers->createInstance($name);
        while ($item = $queue->claimItem()) {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $done++;
        }
      }
      if ($jobs !== NULL) {
        $done += $this->container->get('advancedqueue.processor')->processQueue($jobs);
      }
      if ($done === 0) {
        return;
      }
    }
    $this->fail('The activity queues did not empty.');
  }

}
