<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\localnodes_platform\Drush\Commands\OrganizerCommands;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The organizer's confirmed page types, created by drush localnodes:topic-types.
 *
 * Phase 23 plan 09 (D-20, ONB-06, ROADMAP SC11, site half). The provisioning
 * workflow puts the confirmed list in LOCALNODES_TOPIC_TYPES as base64url JSON
 * (23-08). The command decodes it, checks every label again with the sign-up
 * form's rule, and creates the missing topic_types terms. Terms are content, so
 * they are made by a command and never shipped as config.
 *
 * The four Open Social defaults and "Reference" (Phase 22) are created in
 * setUp() the way social_topic_install() and localnodes_import_install() create
 * them on a real site (Kernel tests never run those hooks).
 *
 * @group localnodes_platform
 * @group phase23
 */
final class TopicTypesCommandTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * localnodes_platform is enabled for its class autoloading only. Its own
   * dependencies (the AI stack) stay off, and a Kernel test does not check
   * module dependencies (see LocalNodesEmailTest).
   */
  protected static $modules = ['localnodes_platform'];

  /**
   * Open Social's four default page types and Phase 22's "Reference".
   *
   * @var string[]
   */
  private const DEFAULTS = ['Blog', 'Content', 'Dialog', 'News', 'Reference'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The icon field the real vocabulary carries, from Open Social's own YAML.
    $this->installShippedConfig('social_topic', [
      'field.storage.taxonomy_term.field_topic_type_icon',
      'field.field.taxonomy_term.topic_types.field_topic_type_icon',
    ]);
    foreach (self::DEFAULTS as $name) {
      Term::create(['vid' => 'topic_types', 'name' => $name, 'field_topic_type_icon' => 'icon-blog'])->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('LOCALNODES_TOPIC_TYPES');
    parent::tearDown();
  }

  /**
   * Sets LOCALNODES_TOPIC_TYPES the way the workflow does.
   *
   * @param string[] $labels
   *   The names to put in the list.
   */
  private function setList(array $labels): void {
    putenv('LOCALNODES_TOPIC_TYPES=' . $this->encode($labels));
  }

  /**
   * Encodes a value as base64url JSON, unpadded (23-08's format).
   */
  private function encode(mixed $value): string {
    return rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
  }

  /**
   * Runs the command once.
   *
   * @param \Drupal\localnodes_platform\Drush\Commands\OrganizerCommands|null $command
   *   A command object to run, or NULL for the real one.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything the command printed.
   */
  private function runCommand(?OrganizerCommands $command = NULL): array {
    $output = new BufferedOutput();
    $command ??= new OrganizerCommands();
    $command->setOutput($output);
    $code = $command->topicTypes();
    return [$code, trim($output->fetch())];
  }

  /**
   * Every topic_types term name, in term id order.
   *
   * @return string[]
   *   The names.
   */
  private function termNames(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $names = [];
    foreach ($storage->loadByProperties(['vid' => 'topic_types']) as $term) {
      $names[(int) $term->id()] = $term->label();
    }
    ksort($names);
    return array_values($names);
  }

  /**
   * The confirmed names become terms, once each, with the default icon.
   */
  public function testCreatesTheConfirmedTypesOnce(): void {
    $this->setList(['Meeting notes', 'How-to guides', 'Tom & Jerry/Co']);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('Added 3 page types.', $printed);
    $names = $this->termNames();
    $this->assertSame(
      array_merge(self::DEFAULTS, ['Meeting notes', 'How-to guides', 'Tom & Jerry/Co']),
      $names,
      'The five existing types stay and the new ones follow in the organizer\'s order.'
    );

    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $weights = [];
    foreach (['Meeting notes', 'How-to guides', 'Tom & Jerry/Co'] as $name) {
      $terms = $storage->loadByProperties(['vid' => 'topic_types', 'name' => $name]);
      $this->assertCount(1, $terms, "$name exists once.");
      $term = reset($terms);
      $this->assertSame('icon-topic', $term->get('field_topic_type_icon')->value, "$name has the default icon.");
      $weights[] = (int) $term->getWeight();
    }
    $sorted = $weights;
    sort($sorted);
    $this->assertSame($sorted, $weights, 'Weights follow the list order.');
    $this->assertSame(3, count(array_unique($weights)), 'Each new type has its own weight.');
  }

  /**
   * The five existing types are untouched: same terms, same icons.
   */
  public function testDefaultsAndReferenceStayIntact(): void {
    $before = [];
    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    foreach ($storage->loadByProperties(['vid' => 'topic_types']) as $term) {
      $before[$term->label()] = [(int) $term->id(), $term->get('field_topic_type_icon')->value];
    }
    $this->setList(['Meeting notes']);
    $this->runCommand();

    foreach (self::DEFAULTS as $name) {
      $terms = $storage->loadByProperties(['vid' => 'topic_types', 'name' => $name]);
      $this->assertCount(1, $terms, "$name exists once.");
      $term = reset($terms);
      $this->assertSame($before[$name], [(int) $term->id(), $term->get('field_topic_type_icon')->value], "$name is unchanged.");
    }
  }

  /**
   * A second run creates nothing.
   */
  public function testSecondRunCreatesNothing(): void {
    $this->setList(['Meeting notes', 'How-to guides']);
    $this->runCommand();
    $after_first = $this->termNames();

    [$code, $printed] = $this->runCommand();
    $this->assertSame(0, $code);
    $this->assertSame('Added 0 page types.', $printed);
    $this->assertSame($after_first, $this->termNames());
  }

  /**
   * A name that matches an existing type ignoring case is not created.
   */
  public function testCaseOnlyDifferencesAreNotCreated(): void {
    $this->setList(['news', 'BLOG', '  reference  ', 'Meeting notes']);
    [, $printed] = $this->runCommand();

    $this->assertSame('Added 1 page types.', $printed);
    $this->assertSame(array_merge(self::DEFAULTS, ['Meeting notes']), $this->termNames());
  }

  /**
   * Repeats inside the list, ignoring case, create one term.
   */
  public function testDuplicatesInTheListCreateOneTerm(): void {
    $this->setList(['Maps', 'maps', 'MAPS ', 'Photos']);
    [, $printed] = $this->runCommand();

    $this->assertSame('Added 2 page types.', $printed);
    $this->assertSame(array_merge(self::DEFAULTS, ['Maps', 'Photos']), $this->termNames());
  }

  /**
   * An empty or missing variable changes nothing and says so.
   *
   * @dataProvider emptyValueProvider
   */
  public function testEmptyOrAbsentChangesNothing(?string $value): void {
    $value === NULL ? putenv('LOCALNODES_TOPIC_TYPES') : putenv('LOCALNODES_TOPIC_TYPES=' . $value);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame('No page types to add.', $printed);
    $this->assertSame(self::DEFAULTS, $this->termNames());
  }

  /**
   * Absent, empty and blank.
   */
  public static function emptyValueProvider(): array {
    return [
      'absent' => [NULL],
      'empty' => [''],
      'blank' => ['   '],
    ];
  }

  /**
   * Input that is not a list of names stops the command and creates nothing.
   *
   * The printed line is fixed: it never repeats the value.
   *
   * @dataProvider invalidInputProvider
   */
  public function testInvalidInputExitsNonZeroWithoutCreating(string $value): void {
    putenv('LOCALNODES_TOPIC_TYPES=' . $value);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('LOCALNODES_TOPIC_TYPES is not a list of names.', $printed);
    $this->assertFalse(str_contains($printed, $value), 'The value is never echoed.');
    $this->assertSame(self::DEFAULTS, $this->termNames());
  }

  /**
   * Values that are not base64url JSON arrays of strings.
   */
  public static function invalidInputProvider(): array {
    $encode = static fn ($value) => rtrim(strtr(base64_encode(is_string($value) ? $value : json_encode($value)), '+/', '-_'), '=');
    return [
      'not base64' => ['%%%not-base64%%%'],
      'base64 of text, not JSON' => [$encode('plain words, no JSON')],
      'a JSON object' => [$encode(['a' => 'Maps'])],
      'a JSON string' => [$encode('Maps')],
      'a list of numbers' => [$encode([1, 2, 3])],
      'a list with a nested list' => [$encode(['Maps', ['Photos']])],
      'a list with null' => [$encode(['Maps', NULL])],
      'plain JSON, not encoded' => ['["Maps"]'],
    ];
  }

  /**
   * A name that fails the sign-up rule is skipped; the rest are created.
   */
  public function testLabelsOutsideTheAllowListAreSkipped(): void {
    $this->setList([
      '<b>Bold</b>',
      'A',
      str_repeat('x', 41),
      'http://example.com',
      "Line\nbreak",
      'Meeting notes',
      'Q&A',
      'Neighbors\' tips',
    ]);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(0, $code);
    $this->assertSame("Added 3 page types.\nSkipped 5 names that are not allowed.", $printed);
    $this->assertSame(array_merge(self::DEFAULTS, ['Meeting notes', 'Q&A', "Neighbors' tips"]), $this->termNames());
  }

  /**
   * Letters of any alphabet are allowed, like the sign-up form.
   */
  public function testNonLatinLettersAreAllowed(): void {
    $this->setList(['Réunions', 'Заметки', '会議メモ']);
    [, $printed] = $this->runCommand();

    $this->assertSame('Added 3 page types.', $printed);
    $this->assertSame(array_merge(self::DEFAULTS, ['Réunions', 'Заметки', '会議メモ']), $this->termNames());
  }

  /**
   * At most twelve names are used, the first twelve.
   */
  public function testListIsCappedAtTwelve(): void {
    $labels = array_map(static fn (int $n) => "Type number $n", range(1, 15));
    $this->setList($labels);
    [, $printed] = $this->runCommand();

    $this->assertSame('Added 12 page types.', $printed);
    $this->assertSame(array_merge(self::DEFAULTS, array_slice($labels, 0, 12)), $this->termNames());
  }

  /**
   * A name saved by someone else after the list was read is not made twice.
   *
   * The command checks the vocabulary by name right before each save. The
   * test subclass runs another writer between the plan and that check.
   */
  public function testNameSavedMeanwhileIsNotCreatedAgain(): void {
    $this->setList(['Alpha', 'Beta']);
    $command = new class() extends OrganizerCommands {

      /**
       * {@inheritdoc}
       */
      protected function beforeTopicTypeSave(string $label): void {
        if ($label === 'Beta') {
          Term::create(['vid' => 'topic_types', 'name' => 'beta'])->save();
        }
      }

    };
    [$code, $printed] = $this->runCommand($command);

    $this->assertSame(0, $code);
    $this->assertSame('Added 1 page types.', $printed);
    $names = $this->termNames();
    $this->assertSame(1, count(array_filter($names, static fn ($n) => strtolower($n) === 'beta')), 'Beta exists once.');
    $this->assertContains('Alpha', $names);
  }

  /**
   * Without the topic_types vocabulary the command stops with a fixed line.
   */
  public function testMissingVocabularyExitsNonZero(): void {
    $this->container->get('entity_type.manager')->getStorage('taxonomy_vocabulary')->load('topic_types')->delete();
    $this->setList(['Meeting notes']);
    [$code, $printed] = $this->runCommand();

    $this->assertSame(1, $code);
    $this->assertSame('The topic_types vocabulary does not exist.', $printed);
  }

}
