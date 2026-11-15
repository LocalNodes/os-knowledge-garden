<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackFiction;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A pack holds only made-up people and places, and mail goes nowhere real.
 *
 * Every account is a person record of the source file's People section, no text
 * names a real place or a mail provider, and every address is on a reserved
 * domain, so a stray notification cannot reach a real inbox.
 *
 * Every name a pack or the how-to pages state is in a reviewed inventory
 * (tests/fixtures/expect/<pack>/invented.yml, and the how-to pages' own), so a
 * real person, company, city or street cannot slip in as a new name.
 *
 * @group localnodes_demo_packs
 */
final class PackFictionalTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * Words no pack text may carry: real places and real mail providers.
   */
  private const REAL_WORDS = [
    'Boulder', 'Portland', 'Cascadia', 'Colorado', 'Denver', 'Oregon',
    'Seattle', 'gmail', 'yahoo', 'hotmail', 'outlook', 'protonmail', 'icloud',
  ];

  /**
   * Every account is a person record of the source's People section.
   *
   * The name is a listed username, and the first and last name together are
   * that record's display name, whole and not as a word inside other text.
   *
   * @dataProvider packProvider
   */
  public function testUserNamesComeFromTheSource(string $pack): void {
    $users = self::packYaml($pack)['user.yml'] ?? [];
    $this->assertNotSame([], self::entries($users), "Pack $pack has users to check.");
    $this->assertSame([], PackChecks::nameProblems((string) self::expectation($pack, 'source.md'), $users), "Pack $pack's account names.");
  }

  /**
   * No pack text names a real place or a mail provider.
   *
   * @dataProvider packProvider
   */
  public function testNoRealPlaceOrProvider(string $pack): void {
    $files = array_merge(
      glob(self::moduleDir() . '/' . self::packProfile($pack) . 'content/entity/*.yml') ?: [],
      glob(self::moduleDir() . "/tests/fixtures/expect/$pack/*") ?: []
    );
    $this->assertNotEmpty($files, "Pack $pack has files to scan.");
    foreach ($files as $path) {
      $found = PackFiction::realWordProblems((string) file_get_contents($path), self::REAL_WORDS);
      $this->assertSame([], $found, "Pack $pack, " . basename($path) . ' names a real place or provider.');
    }
  }

  /**
   * Every address anywhere in a pack is on a reserved domain.
   *
   * Reads the pack's YAML and its expectation files, the source and the
   * assistant rows among them, as written (entities decoded) and, for a YAML
   * file, as parsed, so an address written with a YAML escape is read too.
   * Every user has one.
   *
   * @dataProvider packProvider
   */
  public function testEveryAddressIsReserved(string $pack): void {
    foreach (self::entries(self::packYaml($pack)['user.yml'] ?? []) as [$uuid, $data]) {
      $this->assertNotSame('', trim((string) ($data['mail'] ?? '')), "Pack $pack, user $uuid has a mail address.");
    }
    $files = array_merge(
      glob(self::moduleDir() . '/' . self::packProfile($pack) . 'content/entity/*.yml') ?: [],
      glob(self::moduleDir() . "/tests/fixtures/expect/$pack/*") ?: []
    );
    $this->assertNotEmpty($files, "Pack $pack has files to scan.");
    $addresses = 0;
    foreach ($files as $path) {
      $text = (string) file_get_contents($path);
      $result = str_ends_with($path, '.yml') ? PackChecks::yamlAddressProblems($text) : PackChecks::addressProblems($text);
      $addresses += $result['count'];
      $this->assertSame([], $result['problems'], "Pack $pack, " . basename($path));
    }
    $this->assertGreaterThan(0, $addresses, "Pack $pack has addresses to check.");
  }

  /**
   * The inventories: one for each pack and one for the shared how-to pages.
   */
  public static function scopeProvider(): array {
    $cases = ['howto' => ['howto']];
    foreach (self::packs() as $pack) {
      $cases[$pack] = [$pack];
    }
    return $cases;
  }

  /**
   * The files a scope states its names in.
   *
   * A pack's scope is its content YAML and its expectation files; the how-to
   * scope is the shared how-to YAML and its expectation files. The inventory
   * is not scanned, and neither is a file that is not text.
   *
   * @return string[]
   */
  private static function scopePaths(string $scope): array {
    $module = self::moduleDir();
    $paths = $scope === 'howto'
      ? array_merge([$module . '/shared/content/entity/howto.yml'], glob($module . '/tests/fixtures/expect/howto/*') ?: [])
      : array_merge(glob($module . '/' . self::packProfile($scope) . 'content/entity/*.yml') ?: [], glob($module . "/tests/fixtures/expect/$scope/*") ?: []);
    $paths = array_filter($paths, static fn (string $path): bool => is_file($path) && basename($path) !== 'invented.yml');
    sort($paths);
    return array_values($paths);
  }

  /**
   * A scope's reviewed inventory, parsed.
   */
  private static function inventory(string $scope): array {
    $path = self::moduleDir() . "/tests/fixtures/expect/$scope/invented.yml";
    $parsed = is_file($path) ? Yaml::parseFile($path) : [];
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * The plain strings of each file of a scope, by file name.
   *
   * @return array<string, string[]>
   */
  private static function scopeTexts(string $scope): array {
    $texts = [];
    foreach (self::scopePaths($scope) as $path) {
      $texts[basename($path)] = PackFiction::fileTexts($path);
    }
    return $texts;
  }

  /**
   * The segments of each file of a scope, by file name.
   *
   * @return array<string, array<int, array{0: string, 1: string[]}>>
   */
  private static function scopeSegments(string $scope): array {
    $segments = [];
    foreach (self::scopePaths($scope) as $path) {
      $segments[basename($path)] = PackFiction::fileSegments($path);
    }
    return $segments;
  }

  /**
   * The files of a scope with a run that no inventory covers.
   *
   * A pack site also shows the how-to pages, so a pack scan reads both scopes
   * and accepts the names of both inventories.
   *
   * @return string[]
   *   One message for each such file, naming the runs.
   */
  private static function scanProblems(string $scope): array {
    $scopes = $scope === 'howto' ? ['howto'] : [$scope, 'howto'];
    $inventory = [];
    $people = [];
    $files = [];
    foreach ($scopes as $name) {
      $inventory = array_merge($inventory, PackFiction::names(self::inventory($name)));
      $people = array_merge($people, PackFiction::peopleNames(self::inventory($name)));
      foreach (self::scopeSegments($name) as $file => $segments) {
        $files[$name . '/' . $file] = $segments;
      }
    }
    $ordinary = array_merge(PackFiction::ORDINARY, PackFiction::labelWords(self::expectation('howto', 'labels.yml')));
    foreach ($scopes as $name) {
      $ordinary = array_merge($ordinary, PackFiction::ordinaryWords(self::inventory($name)));
    }
    $problems = [];
    foreach (PackFiction::scan($files, $inventory, $people, $ordinary) as $file => $found) {
      $problems[] = "Scope $scope, $file: not in an inventory: " . implode('; ', $found);
    }
    return $problems;
  }

  /**
   * Each inventory file lists the four groups, whole names, none twice.
   *
   * @dataProvider scopeProvider
   */
  public function testInventoryIsWellFormed(string $scope): void {
    $path = self::moduleDir() . "/tests/fixtures/expect/$scope/invented.yml";
    $this->assertFileExists($path, "Scope $scope has its invented.yml.");
    $this->assertSame([], PackFiction::inventoryProblems(self::inventory($scope)), "Scope $scope's inventory.");
  }

  /**
   * Every proper-noun run of a pack's text is made up and listed.
   *
   * A run is checked against the pack's inventory and the how-to pages', so a
   * name that is in neither fails; the ordinary words (months, weekdays, the
   * screen labels, the product) are cut out first.
   *
   * @dataProvider packProvider
   */
  public function testPackNamesAreInventoried(string $pack): void {
    $this->assertNotSame([], self::scopePaths($pack), "Pack $pack has files to scan.");
    $this->assertSame([], self::scanProblems($pack));
  }

  /**
   * Every proper-noun run of the how-to pages and their expectations is listed.
   */
  public function testHowtoNamesAreInventoried(): void {
    $this->assertNotSame([], self::scopePaths('howto'), 'The how-to pages have files to scan.');
    $this->assertSame([], self::scanProblems('howto'));
  }

  /**
   * Every person, group, place and address field is a whole inventory entry.
   *
   * @dataProvider packProvider
   */
  public function testStructuredNamesAreInventoried(string $pack): void {
    $inventory = array_merge(PackFiction::names(self::inventory($pack)), PackFiction::names(self::inventory('howto')));
    $names = PackFiction::structuredNames(array_merge(self::packYaml($pack), ['howto.yml' => self::sharedHowto()]));
    $this->assertNotSame([], $names, "Pack $pack states names in its fields.");
    $this->assertSame([], array_values(array_diff($names, $inventory)), "Pack $pack's name fields not in an inventory.");
  }

  /**
   * Every username and mail address field names only made-up people.
   *
   * The name and mail keys of the pack's YAML and of the expectation files are
   * read as a mail address's local part is: each word must be a word of an
   * inventory person (or a role such as info).
   *
   * @dataProvider scopeProvider
   */
  public function testAccountFieldsAreInventoried(string $scope): void {
    $people = PackFiction::peopleNames(self::inventory($scope));
    if ($scope !== 'howto') {
      $people = array_merge($people, PackFiction::peopleNames(self::inventory('howto')));
    }
    $checked = 0;
    $problems = [];
    foreach (self::scopePaths($scope) as $path) {
      if (!str_ends_with($path, '.yml')) {
        continue;
      }
      $data = Yaml::parseFile($path);
      $data = is_array($data) ? $data : [];
      $checked += count(PackFiction::accountFields($data));
      $found = PackFiction::accountProblems($data, $people);
      if ($found !== []) {
        $problems[] = "Scope $scope, " . basename($path) . ': ' . implode('; ', $found);
      }
    }
    if ($scope !== 'howto') {
      $this->assertGreaterThan(0, $checked, "Scope $scope states usernames and addresses to check.");
    }
    $this->assertSame([], $problems);
  }

  /**
   * An inventory entry is written somewhere in its scope's text.
   *
   * @dataProvider scopeProvider
   */
  public function testInventoryHasNoStaleEntries(string $scope): void {
    $strings = [];
    foreach (self::scopeTexts($scope) as $list) {
      $strings = array_merge($strings, $list);
    }
    $this->assertSame([], PackFiction::staleEntries(PackFiction::names(self::inventory($scope)), $strings), "Scope $scope lists a name its text never states.");
    $this->assertSame([], PackFiction::staleEntries(PackFiction::ordinaryWords(self::inventory($scope)), $strings), "Scope $scope lists an ordinary word its text never states.");
  }

}
