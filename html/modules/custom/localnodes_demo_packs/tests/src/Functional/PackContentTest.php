<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\Core\Entity\EntityInterface;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\symfony_mailer_test\MailerTestServiceInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;

/**
 * What a pack loads: the passages, every uuid, the counts and no mail.
 *
 * Also that nothing a pack leaves out of its source is loaded.
 *
 * Each method installs one site and loops over every pack: a pack loads, is
 * checked, then is removed.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackContentTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * The pack file each count in expect.yml is the number of entries of.
   *
   * The how-to pages count the shared file's entries (see packEntries()).
   */
  private const COUNT_FILES = [
    'user' => 'user.yml',
    'group' => 'group.yml',
    'topic' => 'topic.yml',
    'event' => 'event.yml',
    'event_enrollment' => 'event-enrollment.yml',
    'post' => 'post.yml',
    'comment' => 'comment.yml',
    'like' => 'like.yml',
    'poll' => 'quick-poll.yml',
    'howto' => 'howto.yml',
  ];

  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * Each passage a pack quotes is on the loaded entity word for word.
   */
  public function testPassagesLoadWordForWord(): void {
    foreach (self::packs() as $pack) {
      $this->loadPack($pack);
      $state = $this->container->get('state');
      $state->resetCache();
      $anchor = (int) $state->get('localnodes_demo_packs.anchor');
      $this->assertSame(self::packAnchor(), $anchor, "Pack $pack loaded with the test's fixed anchor.");
      $passages = self::expectation($pack, 'passages.yml');
      $this->assertNotEmpty($passages, "Pack $pack states passages.");
      foreach ($passages as $passage) {
        $id = (string) $passage['id'];
        $entity = $this->entityByUuid((string) $passage['uuid']);
        $this->assertNotNull($entity, "Pack $pack, passage $id: the entity {$passage['uuid']} loaded.");
        $loaded = $this->fieldText($entity, (string) $passage['field']);
        // The dates come from the independent oracle, not from PackTokens, so
        // a fault in the loader's resolver cannot also hide in this check.
        $expected = PackText::normalize(PackChecks::expectedDate((string) $passage['text'], self::packAnchor()));
        $this->assertStringContainsString($expected, $loaded, "Pack $pack, passage $id is on the loaded {$passage['field']}.");
      }
      $this->removePack($pack);
    }
  }

  /**
   * Every uuid in a pack's YAML loads as its plugin's entity type.
   */
  public function testEveryPackUuidLoads(): void {
    foreach (self::packs() as $pack) {
      $this->loadPack($pack);
      $types = $this->entityTypesByFile();
      $repository = $this->container->get('entity.repository');
      foreach (self::packEntries($pack) as [$file, $uuid]) {
        $this->assertArrayHasKey($file, $types, "Pack $pack: $file is read by a pack plugin.");
        $this->assertNotNull(
          $repository->loadEntityByUuid($types[$file], $uuid),
          "Pack $pack, $file: $uuid loaded as a {$types[$file]}."
        );
      }
      $this->removePack($pack);
    }
  }

  /**
   * The pack loads as many of each kind as its expectation file states.
   */
  public function testCountsMatchThePack(): void {
    foreach (self::packs() as $pack) {
      $this->loadPack($pack);
      $types = $this->entityTypesByFile();
      $repository = $this->container->get('entity.repository');
      // Each loaded entity counts once, by its uuid, so a uuid named twice
      // cannot stand in for an entity that did not load.
      $found = array_fill_keys(array_keys(self::COUNT_FILES), []);
      foreach (self::packEntries($pack) as [$file, $uuid]) {
        $key = array_search($file, self::COUNT_FILES, TRUE);
        if ($key !== FALSE && $repository->loadEntityByUuid($types[$file], $uuid) !== NULL) {
          $found[$key][$uuid] = TRUE;
        }
      }
      $loaded = array_map('count', $found);
      $counts = self::expectation($pack, 'expect.yml')['counts'] ?? [];
      $this->assertSame(array_keys($loaded), array_keys($counts), "Pack $pack states a count for exactly these kinds, in this order.");
      $this->assertSame(array_map('intval', $counts), $loaded, "Pack $pack loads the counts its expect.yml states.");
      $this->removePack($pack);
    }
  }

  /**
   * Loading a pack and running the queues and cron hands nothing to a mailer.
   */
  public function testNoMailAfterLoadAndCron(): void {
    foreach (self::packs() as $pack) {
      $this->sentMails();
      $this->loadPack($pack);
      $this->runActivityQueues();
      // Cron can reach Solr, whose response the connector prints.
      ob_start();
      try {
        $this->container->get('cron')->run();
      }
      finally {
        ob_end_clean();
      }
      $this->runActivityQueues();
      $mails = $this->sentMails();
      $this->assertSame([], array_map(static fn ($mail): string => $mail->getType() . '.' . $mail->getSubType(), $mails), "Pack $pack sent mail after the load and a cron run.");
      $this->removePack($pack);
    }
  }

  /**
   * Nothing the source leaves out is on a loaded entity or in the pack's YAML.
   */
  public function testLeftOutPhrasesAreAbsent(): void {
    foreach (self::packs() as $pack) {
      $this->loadPack($pack);
      $phrases = [];
      $rows = self::expectation($pack, 'expect.yml')['left_out'] ?? [];
      $this->assertNotEmpty($rows, "Pack $pack names what it leaves out of the source.");
      foreach ($rows as $row) {
        $phrases[(string) $row['id']] = mb_strtolower(PackText::normalize((string) $row['text']));
      }
      $texts = [];
      foreach (self::packEntries($pack) as [$file, $uuid]) {
        $entity = $this->entityByUuid($uuid);
        $this->assertNotNull($entity, "Pack $pack, $file: $uuid loaded.");
        $texts[$file . ' ' . $uuid] = $this->entityText($entity);
      }
      foreach ($phrases as $id => $phrase) {
        $this->assertNotSame('', $phrase, "Pack $pack, left-out row $id has text.");
        foreach ($texts as $where => $text) {
          $this->assertStringNotContainsString($phrase, $text, "Pack $pack, left-out row $id is on the loaded $where.");
        }
      }
      $this->removePack($pack);
    }
  }

  /**
   * Which kind of entity each pack file loads, from the plugin definitions.
   *
   * @return array<string, string>
   *   Entity type by file name.
   */
  private function entityTypesByFile(): array {
    $types = [];
    foreach ($this->container->get('plugin.manager.demo_content')->getDefinitions() as $id => $definition) {
      if (str_starts_with((string) $id, 'pack_')) {
        $types[basename((string) $definition['source'])] = (string) $definition['entity_type'];
      }
    }
    return $types;
  }

  /**
   * Every string a loaded entity holds in its fields, as a visitor reads it.
   */
  private function entityText(EntityInterface $entity): string {
    $parts = [];
    foreach ($entity->getFields() as $field) {
      foreach ($field as $item) {
        foreach ($item->getValue() as $value) {
          if (is_string($value) && trim($value) !== '') {
            $parts[] = implode(' ', PackText::blocks($value));
          }
        }
      }
    }
    return mb_strtolower(PackText::normalize(implode(' ', $parts)));
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
