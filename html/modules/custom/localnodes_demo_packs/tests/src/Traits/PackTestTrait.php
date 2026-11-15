<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Traits;

use Drupal\Core\Entity\EntityInterface;
use Drupal\localnodes_demo_packs\PackText;
use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Symfony\Component\Yaml\Yaml;

/**
 * What every pack check shares: the pack list, the expectation files, the load.
 *
 * The pack list is the directories under packs/ plus the smoke fixture, so a
 * pack cannot land without its expectation files under
 * tests/fixtures/expect/<pack>/. The readers are static and use no container,
 * so Unit tests and data providers can call them. The load helpers call
 * PackLoadTrait's runPlugins() and need a Functional test that uses both.
 */
trait PackTestTrait {

  /**
   * The day-token anchor every pack load uses: noon on 12 November 2026.
   */
  protected static function packAnchor(): int {
    return mktime(12, 0, 0, 11, 12, 2026);
  }

  /**
   * The module's directory.
   */
  protected static function moduleDir(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Every pack to check: the packs/ directories, sorted, then smoke.
   *
   * @return string[]
   */
  public static function packs(): array {
    $found = [];
    foreach (glob(self::moduleDir() . '/packs/*', GLOB_ONLYDIR) ?: [] as $directory) {
      $found[] = basename($directory);
    }
    sort($found);
    return array_values(array_unique(array_merge($found, ['smoke'])));
  }

  /**
   * Data provider: one case per pack.
   */
  public static function packProvider(): array {
    $cases = [];
    foreach (self::packs() as $pack) {
      $cases[$pack] = [$pack];
    }
    return $cases;
  }

  /**
   * The profile option a pack loads with, relative to the module.
   */
  protected static function packProfile(string $pack): string {
    return $pack === 'smoke' ? 'tests/fixtures/packs/smoke/' : "packs/$pack/";
  }

  /**
   * An expectation file of a pack: parsed YAML, or the raw text of source.md.
   *
   * @return array|string
   *   Empty array when the file is missing or empty (a test then fails on it).
   */
  protected static function expectation(string $pack, string $file): array|string {
    $path = self::moduleDir() . "/tests/fixtures/expect/$pack/$file";
    if (!is_file($path)) {
      return $file === 'source.md' ? '' : [];
    }
    if ($file === 'source.md') {
      return (string) file_get_contents($path);
    }
    $parsed = Yaml::parseFile($path);
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * A pack's walk: the witness and the steps of tests/fixtures/expect/<pack>/walk.yml.
   *
   * @return array
   *   Empty when the pack has no walk file.
   */
  protected static function walk(string $pack): array {
    $walk = self::expectation($pack, 'walk.yml');
    return is_array($walk) ? $walk : [];
  }

  /**
   * Every file of a pack's content/entity directory, parsed, by file name.
   *
   * @return array<string, array>
   */
  protected static function packYaml(string $pack): array {
    $files = [];
    $paths = glob(self::moduleDir() . '/' . self::packProfile($pack) . 'content/entity/*.yml') ?: [];
    sort($paths);
    foreach ($paths as $path) {
      $parsed = Yaml::parseFile($path);
      $files[basename($path)] = is_array($parsed) ? $parsed : [];
    }
    return $files;
  }

  /**
   * The how-to pages every pack site shows, which live once in shared/.
   */
  protected static function sharedHowto(): array {
    $path = self::moduleDir() . '/shared/content/entity/howto.yml';
    $parsed = is_file($path) ? Yaml::parseFile($path) : [];
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * The source's Part 1: from the "## Part 1" line up to the "### P11" line.
   *
   * Only P01 to P10 are how-to pages; P11 and P12 stay withheld. Reads the
   * byte copy of the source under tests/fixtures/expect/howto/ and makes no
   * container call.
   */
  protected static function howtoSource(): string {
    $region = [];
    $inside = FALSE;
    foreach (preg_split('/\R/', (string) self::expectation('howto', 'source.md')) ?: [] as $line) {
      if (!$inside) {
        $inside = str_starts_with($line, '## Part 1');
      }
      elseif (str_starts_with($line, '### P11')) {
        break;
      }
      if ($inside) {
        $region[] = $line;
      }
    }
    return implode("\n", $region);
  }

  /**
   * Entries of a pack file as [uuid, data] pairs; uuid is the key or field.
   *
   * @return array<int, array{0: string, 1: array}>
   */
  protected static function entries(array $file): array {
    return PackChecks::entries($file);
  }

  /**
   * Every entry a pack site loads, as [file name, uuid] pairs.
   *
   * The pack's own files, then the how-to pages that live once in shared/.
   *
   * @return array<int, array{0: string, 1: string}>
   */
  protected static function packEntries(string $pack): array {
    $pairs = [];
    foreach (self::packYaml($pack) as $file => $data) {
      foreach (self::entries($data) as [$uuid]) {
        $pairs[] = [$file, $uuid];
      }
    }
    foreach (self::entries(self::sharedHowto()) as [$uuid]) {
      $pairs[] = ['howto.yml', $uuid];
    }
    return $pairs;
  }

  /**
   * Every string in parsed data, at any depth, with its nearest named key.
   *
   * List items (poll choices, member lists) take the key of their list.
   *
   * @return array<int, array{key: string, value: string}>
   */
  protected static function stringsOf(array $data, string $key = ''): array {
    return PackChecks::stringsOf($data, $key);
  }

  /**
   * The text of a field as a visitor reads it, markup removed.
   */
  protected function fieldText(EntityInterface $entity, string $field): string {
    if (!$entity->hasField($field) && $field === 'description' && $entity->hasField('field_group_description')) {
      // social_demo loads a group's "description" into this field.
      $field = 'field_group_description';
    }
    $this->assertTrue($entity->hasField($field), "The loaded {$entity->getEntityTypeId()} has the field $field.");
    $parts = [];
    foreach ($entity->get($field) as $item) {
      $values = $item->getValue();
      if (isset($values['value']) && is_string($values['value'])) {
        $parts[] = $values['value'];
        continue;
      }
      foreach ($values as $value) {
        if (is_scalar($value)) {
          $parts[] = (string) $value;
        }
      }
    }
    return PackText::normalize(implode(' ', array_map(
      static fn (string $part): string => implode(' ', PackText::blocks($part)),
      $parts
    )));
  }

  /**
   * Loads whatever entity carries the uuid, among the types packs create.
   */
  protected function entityByUuid(string $uuid): ?EntityInterface {
    $repository = $this->container->get('entity.repository');
    $manager = $this->container->get('entity_type.manager');
    foreach (['node', 'group', 'post', 'comment', 'user', 'poll', 'event_enrollment', 'vote', 'taxonomy_term', 'file'] as $type) {
      if ($manager->hasDefinition($type) && ($entity = $repository->loadEntityByUuid($type, $uuid)) !== NULL) {
        return $entity;
      }
    }
    return NULL;
  }

  /**
   * Loads a pack as the entrypoint does, with the anchor fixed first.
   */
  protected function loadPack(string $pack): void {
    $this->container->get('state')->set('localnodes_demo_packs.anchor', self::packAnchor());
    $this->runPlugins(self::PLUGINS, 'createContent', self::packProfile($pack));
    $this->resetEntityCaches();
  }

  /**
   * Removes a pack in reverse load order, so the next pack starts clean.
   */
  protected function removePack(string $pack): void {
    // Work done since the load (queues, cron) leaves entities in memory that
    // a delete then re-saves from a relationship's cleanup.
    $this->resetEntityCaches();
    $this->runPlugins(array_reverse(self::PLUGINS), 'removeContent', self::packProfile($pack));
    $this->resetEntityCaches();
    $state = $this->container->get('state');
    $state->delete('localnodes.organizer_uid');
    $state->resetCache();
  }

}
