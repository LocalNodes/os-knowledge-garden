<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Entity\DecisionRules;
use Drupal\localnodes_consensus\Service\DecisionRulesManager;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesModuleExtensionList;
use Drupal\Tests\localnodes_import\Kernel\Support\AllProfilesSchemaStorage;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Live per-Group decision rules.
 *
 * The module goes in through the module installer, so its config/install
 * applies as on a fresh install. A Group with no rules version reads the
 * module defaults; set-up seeds version 1 once; every later version cites a
 * decision; versions are never changed or deleted.
 *
 * @group localnodes_consensus
 */
final class DecisionRulesTest extends GroupAccessKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * Whoever hands the rules over (a Group manager in real use).
   */
  protected UserInterface $actor;

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
    $this->actor = User::load($this->createPersona('organizer')->id());
  }

  /**
   * The rules service.
   */
  protected function rules(): DecisionRulesManager {
    return $this->container->get('localnodes_consensus.decision_rules');
  }

  /**
   * One consensus rule for a type.
   */
  protected static function rule(int $days, int $numerator = 2, int $denominator = 3): array {
    return [
      'method' => 'consensus',
      'quorum_numerator' => $numerator,
      'quorum_denominator' => $denominator,
      'threshold_numerator' => NULL,
      'threshold_denominator' => NULL,
      'response_days' => $days,
    ];
  }

  /**
   * The values Group set-up hands over for its first template.
   */
  protected static function setupValues(): array {
    return [
      'path' => 'simple',
      'everyday' => static::rule(7),
      'money' => static::rule(7),
      'rule_change' => static::rule(14),
    ];
  }

  /**
   * Number of stored rules versions, for every Group.
   */
  protected function versionCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('localnodes_decision_rules')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * A Group to hang rules off.
   */
  protected function group(string $label): GroupInterface {
    return $this->createFlexibleGroup($label);
  }

  /**
   * Defaults, one seeded version and its link, idempotent repeat, isolation.
   */
  public function testSetupSeedsVersionOneOnceAndOtherGroupsKeepDefaults(): void {
    $typed = $this->container->get('config.typed');
    $this->assertConfigSchema($typed, 'localnodes_consensus.settings', $this->config('localnodes_consensus.settings')->get());

    $defaults = [
      'path' => 'simple',
      'everyday' => static::rule(7),
      'money' => static::rule(7),
      'rule_change' => static::rule(14),
    ];
    $this->assertSame($defaults, $this->rules()->defaults());

    $first = $this->group('First');
    $second = $this->group('Second');
    $this->assertSame($defaults + ['version_id' => NULL], $this->rules()->currentRules($first));
    $this->assertSame(static::rule(14), $this->rules()->rulesFor($first, 'rule_change'));
    $this->assertSame(0, $this->versionCount());

    $before = \Drupal::time()->getRequestTime();
    $values = static::setupValues();
    $values['everyday']['response_days'] = 5;
    $values['money']['response_days'] = 10;
    $id = $this->rules()->seedFromSetup($first, $values, $this->actor, 41);
    $this->assertGreaterThanOrEqual(1, $id);
    $this->assertSame(1, $this->versionCount());

    $version = DecisionRules::load($id);
    $this->assertInstanceOf(DecisionRules::class, $version);
    $this->assertSame((int) $first->id(), $version->getGroupId());
    $this->assertSame(1, $version->getVersion());
    $this->assertSame(41, $version->getSetupRecordId());
    $this->assertNull($version->getDecisionRecordId());
    $this->assertSame((int) $this->actor->id(), (int) $version->getOwnerId());
    $this->assertGreaterThanOrEqual($before, $version->getChangedTime());
    $this->assertSame($values, $version->getRules());

    $this->assertSame($values + ['version_id' => $id], $this->rules()->currentRules($first));
    $this->assertSame($values['money'], $this->rules()->rulesFor($first, 'money'));

    // Resume: the same set-up record gets the same id and nothing new.
    $this->assertSame($id, $this->rules()->seedFromSetup($first, $values, $this->actor, 41));
    $this->assertSame(1, $this->versionCount());

    // Another Group is untouched.
    $this->assertSame($defaults + ['version_id' => NULL], $this->rules()->currentRules($second));
  }

  /**
   * Simple path shares one rule; advanced gives each type its own.
   */
  public function testSimpleAndAdvancedPathsAreSaved(): void {
    $simple = $this->group('Simple');
    $values = static::setupValues();
    $id = $this->rules()->seedFromSetup($simple, $values, $this->actor, 1);
    $this->assertSame($values + ['version_id' => $id], $this->rules()->currentRules($simple));
    $this->assertSame(7, $this->rules()->rulesFor($simple, 'everyday')['response_days']);
    $this->assertSame(14, $this->rules()->rulesFor($simple, 'rule_change')['response_days']);

    $advanced = $this->group('Advanced');
    $values = [
      'path' => 'advanced',
      'everyday' => static::rule(3, 1, 2),
      'money' => static::rule(10, 1, 1),
      'rule_change' => static::rule(21, 3, 4),
    ];
    $id = $this->rules()->seedFromSetup($advanced, $values, $this->actor, 2);
    $this->assertSame($values + ['version_id' => $id], $this->rules()->currentRules($advanced));
    // Quorum 1 of 1 means everyone.
    $this->assertSame(1, $this->rules()->rulesFor($advanced, 'money')['quorum_numerator']);
    $this->assertSame(1, $this->rules()->rulesFor($advanced, 'money')['quorum_denominator']);

    // A decided change can move a Group to the other path.
    $decided = $this->rules()->addDecidedVersion($advanced, static::setupValues(), 501, $this->actor);
    $this->assertSame(static::setupValues() + ['version_id' => (int) $decided->id()], $this->rules()->currentRules($advanced));
  }

  /**
   * Invalid rule sets, each with a reason.
   *
   * @return array<string, array>
   *   Rule sets keyed by case name.
   */
  protected static function invalidRuleSets(): array {
    $base = static::setupValues();
    $advanced = ['path' => 'advanced'] + $base;
    $all = static function (array $rules, string $key, $value): array {
      foreach (DecisionRules::TYPES as $type) {
        $rules[$type][$key] = $value;
      }
      return $rules;
    };
    $cases = [
      'quorum numerator 0' => $all($base, 'quorum_numerator', 0),
      'quorum numerator negative' => $all($base, 'quorum_numerator', -1),
      'quorum over the whole' => $all($base, 'quorum_numerator', 4),
      'quorum denominator 0' => $all($all($base, 'quorum_denominator', 0), 'quorum_numerator', 0),
      'quorum as text' => $all($base, 'quorum_numerator', '2'),
      'quorum as a fraction' => $all($base, 'quorum_numerator', 0.5),
      'quorum missing' => (function () use ($base) {
        foreach (DecisionRules::TYPES as $type) {
          unset($base[$type]['quorum_denominator']);
        }
        return $base;
      })(),
      'zero response days' => (function () use ($advanced) {
        $advanced['money']['response_days'] = 0;
        return $advanced;
      })(),
      'response days as text' => (function () use ($advanced) {
        $advanced['everyday']['response_days'] = '7';
        return $advanced;
      })(),
      'threshold under consensus' => $all($all($base, 'threshold_numerator', 1), 'threshold_denominator', 2),
      'half a threshold under consensus' => (function () use ($advanced) {
        $advanced['money']['threshold_numerator'] = 1;
        return $advanced;
      })(),
      'method other than consensus' => $all($base, 'method', 'majority'),
      'unknown type' => $base + ['budget' => static::rule(7)],
      'unknown rule key' => (function () use ($advanced) {
        $advanced['everyday']['quorum_people'] = 3;
        return $advanced;
      })(),
      'unknown path' => ['path' => 'custom'] + $base,
      'no path' => array_diff_key($base, ['path' => TRUE]),
      'simple path with different quorums' => (function () use ($base) {
        $base['money']['quorum_numerator'] = 1;
        return $base;
      })(),
      'simple path with different methods' => (function () use ($base) {
        $base['rule_change']['method'] = 'majority';
        return $base;
      })(),
      'simple path with different thresholds' => (function () use ($base) {
        $base['everyday']['threshold_numerator'] = 1;
        $base['everyday']['threshold_denominator'] = 2;
        return $base;
      })(),
      'a type that is not a rule' => (function () use ($advanced) {
        $advanced['money'] = 'consensus';
        return $advanced;
      })(),
    ];
    foreach (DecisionRules::TYPES as $type) {
      $cases["simple path missing $type"] = array_diff_key($base, [$type => TRUE]);
      $cases["advanced path missing $type"] = array_diff_key($advanced, [$type => TRUE]);
    }
    return $cases;
  }

  /**
   * Invalid values are refused on both write paths before anything is saved.
   */
  public function testInvalidRulesAreRefusedOnBothWritePaths(): void {
    $seeded = $this->group('Seeded');
    $this->rules()->seedFromSetup($seeded, static::setupValues(), $this->actor, 7);
    $live = $this->rules()->currentRules($seeded);
    $decision = 100;

    foreach (static::invalidRuleSets() as $case => $rules) {
      $count = $this->versionCount();
      try {
        $this->rules()->validate($rules);
        $this->fail("validate() accepted: $case");
      }
      catch (\InvalidArgumentException $e) {
        $this->assertNotSame('', $e->getMessage(), $case);
      }

      $fresh = $this->group("Fresh $case");
      try {
        $this->rules()->seedFromSetup($fresh, $rules, $this->actor, 1000 + $decision);
        $this->fail("seedFromSetup() saved: $case");
      }
      catch (\InvalidArgumentException) {
      }
      $this->assertSame($count, $this->versionCount(), "Nothing saved by seeding: $case");
      $this->assertNull($this->rules()->currentRules($fresh)['version_id'], $case);

      try {
        $this->rules()->addDecidedVersion($seeded, $rules, ++$decision, $this->actor);
        $this->fail("addDecidedVersion() saved: $case");
      }
      catch (\InvalidArgumentException) {
      }
      $this->assertSame($count, $this->versionCount(), "Nothing saved by a decision: $case");
      $this->assertSame($live, $this->rules()->currentRules($seeded), $case);
    }

    $this->rules()->validate(static::setupValues());
    $this->assertSame(1, $this->versionCount());
  }

  /**
   * Every later version cites its decision, once per Group and decision.
   */
  public function testDecidedVersionsCiteOneDecisionEach(): void {
    $group = $this->group('Decided');
    $other = $this->group('Other');
    $seed_id = $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 41);

    $changed = static::setupValues();
    $changed['rule_change']['response_days'] = 21;
    $before = \Drupal::time()->getRequestTime();
    $version = $this->rules()->addDecidedVersion($group, $changed, 9, $this->actor);
    $this->assertSame(2, $version->getVersion());
    $this->assertSame(9, $version->getDecisionRecordId());
    $this->assertNull($version->getSetupRecordId());
    $this->assertSame((int) $this->actor->id(), (int) $version->getOwnerId());
    $this->assertGreaterThanOrEqual($before, $version->getChangedTime());
    $this->assertSame($changed + ['version_id' => (int) $version->id()], $this->rules()->currentRules($group));
    $this->assertSame(2, $this->versionCount());

    // A second version for the same Group and decision is refused.
    $again = $changed;
    $again['everyday']['response_days'] = 3;
    $again['money']['response_days'] = 3;
    try {
      $this->rules()->addDecidedVersion($group, $again, 9, $this->actor);
      $this->fail('A second version for the same decision saved.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->assertSame(2, $this->versionCount());
    $this->assertSame($changed, $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->loadUnchanged($version->id())->getRules());
    $this->assertSame((int) $version->id(), $this->rules()->currentRules($group)['version_id']);

    // So is a direct entity save of the same pair.
    $this->assertSaveRefused([
      'gid' => $group->id(),
      'version' => 3,
      'path' => 'simple',
      'rules' => json_encode(array_diff_key($again, ['path' => TRUE])),
      'decision_record' => 9,
      'uid' => $this->actor->id(),
    ], 'duplicate decision');
    $this->assertSame($changed, $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules')->loadUnchanged($version->id())->getRules());

    // A decided version needs a decision record id.
    foreach ([0, -3] as $bad) {
      try {
        $this->rules()->addDecidedVersion($group, $again, $bad, $this->actor);
        $this->fail("Decision record id $bad accepted.");
      }
      catch (\InvalidArgumentException) {
      }
    }
    $this->assertSame(2, $this->versionCount());

    // The next decision saves version 3; another Group is unaffected.
    $third = $this->rules()->addDecidedVersion($group, $again, 10, $this->actor);
    $this->assertSame(3, $third->getVersion());
    $this->assertNull($this->rules()->currentRules($other)['version_id']);
    $this->assertSame($seed_id, $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 41), 'Resume still returns the seeded id');
    $this->assertSame(3, $this->versionCount());
  }

  /**
   * Set-up seeds only a Group with no other version, with a positive id.
   */
  public function testSetupNeverSeedsOverAnotherSource(): void {
    $group = $this->group('Seed once');
    foreach ([0, -1] as $bad) {
      try {
        $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, $bad);
        $this->fail("Set-up record id $bad accepted.");
      }
      catch (\InvalidArgumentException) {
      }
    }
    $this->assertSame(0, $this->versionCount());

    $id = $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 41);
    try {
      $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 42);
      $this->fail('A second set-up record seeded over the first.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->assertSame(1, $this->versionCount());
    $this->assertSame($id, $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 41));

    // A Group whose rules a decision already set is never seeded.
    $decided = $this->group('Decided first');
    $version = $this->rules()->addDecidedVersion($decided, static::setupValues(), 77, $this->actor);
    $this->assertSame(1, $version->getVersion());
    try {
      $this->rules()->seedFromSetup($decided, static::setupValues(), $this->actor, 43);
      $this->fail('Set-up seeded over a decided version.');
    }
    catch (\InvalidArgumentException) {
    }
    $this->assertSame(2, $this->versionCount());
    $this->assertSame((int) $version->id(), $this->rules()->currentRules($decided)['version_id']);

    // Nor through a direct save.
    $this->assertSaveRefused([
      'gid' => $decided->id(),
      'version' => 2,
      'path' => 'simple',
      'rules' => json_encode(array_diff_key(static::setupValues(), ['path' => TRUE])),
      'setup_record' => 43,
      'uid' => $this->actor->id(),
    ], 'seed over a decided version');
  }

  /**
   * Versions need a source, and are never changed or deleted.
   */
  public function testVersionsAreAppendOnly(): void {
    $group = $this->group('History');
    $id = $this->rules()->seedFromSetup($group, static::setupValues(), $this->actor, 5);
    $map = json_encode(array_diff_key(static::setupValues(), ['path' => TRUE]));
    $base = [
      'gid' => $group->id(),
      'version' => 2,
      'path' => 'simple',
      'rules' => $map,
      'uid' => $this->actor->id(),
    ];

    $this->assertSaveRefused($base, 'no source');
    $this->assertSaveRefused($base + ['setup_record' => 6, 'decision_record' => 6], 'two sources');
    $this->assertSaveRefused(['version' => 1] + $base + ['decision_record' => 6], 'version number taken');
    $invalid = static::setupValues();
    $invalid['money']['response_days'] = 0;
    $this->assertSaveRefused(['rules' => json_encode(array_diff_key($invalid, ['path' => TRUE]))] + $base + ['decision_record' => 6], 'invalid rules');
    $this->assertSame(1, $this->versionCount());

    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_decision_rules');
    // Fresh copies: a loaded entity is cached, so a change to one would show
    // in the next load before any save.
    $stored = $storage->loadUnchanged($id)->toArray();
    $changes = [
      'reason' => fn (DecisionRules $v) => $v->set('reason', 'Tidy up'),
      'rules' => fn (DecisionRules $v) => $v->set('rules', $map),
      'decision' => fn (DecisionRules $v) => $v->set('decision_record', 3),
      'nothing' => fn (DecisionRules $v) => $v,
    ];
    foreach ($changes as $case => $change) {
      $version = $storage->loadUnchanged($id);
      $change($version);
      try {
        $version->save();
        $this->fail("A saved version changed: $case");
      }
      catch (EntityStorageException) {
      }
      $this->assertSame($stored, $storage->loadUnchanged($id)->toArray(), $case);
    }

    try {
      $storage->loadUnchanged($id)->delete();
      $this->fail('A version was deleted.');
    }
    catch (EntityStorageException) {
    }
    $this->assertSame($stored, $storage->loadUnchanged($id)->toArray());
    $this->assertSame($id, $this->rules()->currentRules($group)['version_id']);
  }

  /**
   * A direct entity save of these values is refused and saves nothing.
   */
  protected function assertSaveRefused(array $values, string $case): void {
    $count = $this->versionCount();
    try {
      DecisionRules::create($values)->save();
      $this->fail("Direct save accepted: $case");
    }
    catch (EntityStorageException) {
    }
    $this->assertSame($count, $this->versionCount(), "Nothing saved: $case");
  }

}
