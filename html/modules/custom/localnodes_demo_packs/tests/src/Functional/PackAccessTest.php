<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_demo_packs\Traits\PackLoadTrait;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\user\UserInterface;

/**
 * Who sees what on a loaded pack: the table in each pack's expect.yml.
 *
 * A page a visitor must not see stays hidden from them and from a member
 * outside its group, and a member inside the group sees it. The checks read
 * pages as a visitor or a signed-in member would.
 *
 * @group localnodes_demo_packs
 * @group phase22_functional
 */
final class PackAccessTest extends BrowserTestBase {

  use PackLoadTrait;
  use PackTestTrait;

  protected $profile = 'social';

  protected $defaultTheme = 'socialblue';

  protected $strictConfigSchema = FALSE;

  /**
   * The persona that stands for a visitor who is not signed in.
   */
  private const ANONYMOUS = 'anonymous';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpPackSite();
  }

  /**
   * Every row of the visibility table holds, as a visitor or a member.
   */
  public function testVisitorTable(): void {
    foreach (self::packs() as $pack) {
      $rows = self::expectation($pack, 'expect.yml')['visibility'] ?? [];
      $this->assertNotEmpty($rows, "Pack $pack states who sees what.");
      $this->loadPack($pack);
      foreach ($rows as $row) {
        $id = (string) ($row['id'] ?? '?');
        $entity = $this->entityByUuid((string) $row['uuid']);
        $this->assertNotNull($entity, "Pack $pack, row $id: {$row['uuid']} loaded.");
        $title = $entity->getEntityTypeId() === 'post'
          ? self::firstSentence((string) $entity->get('field_post')->value)
          : (string) $entity->label();
        $this->assertNotSame('', $title, "Pack $pack, row $id: the page has a title, or a post its first sentence, to look for.");
        $this->asPersona((string) $row['persona'], function () use ($pack, $id, $entity, $title, $row): void {
          $this->drupalGet($entity->toUrl());
          $status = $this->getSession()->getStatusCode();
          $text = $this->getSession()->getPage()->getText();
          if (!empty($row['sees'])) {
            $this->assertSame(200, $status, "Pack $pack, row $id: {$row['persona']} gets the page.");
            $this->assertStringContainsString($title, $text, "Pack $pack, row $id: {$row['persona']} sees the title.");
          }
          else {
            $this->assertStringNotContainsString($title, $text, "Pack $pack, row $id: {$row['persona']} must not see the title.");
            $path = (string) parse_url($this->getSession()->getCurrentUrl(), PHP_URL_PATH);
            $this->assertTrue(
              in_array($status, [403, 404], TRUE) || str_ends_with($path, '/user/login'),
              "Pack $pack, row $id: {$row['persona']} is refused (status $status at $path)."
            );
          }
        });
      }
      $this->removePack($pack);
    }
  }

  /**
   * A post has no title; its first sentence, as plain text, stands for one.
   *
   * @param string $html
   *   The post's text as stored.
   */
  private static function firstSentence(string $html): string {
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES));
    $end = strpos($text, '. ');
    return $end === FALSE ? $text : substr($text, 0, $end + 1);
  }

  /**
   * A group's topic list hides what a member outside it must not see.
   */
  public function testListings(): void {
    foreach (self::packs() as $pack) {
      $rows = self::expectation($pack, 'expect.yml')['listings'] ?? [];
      $this->assertNotEmpty($rows, "Pack $pack states what its group lists show.");
      $this->loadPack($pack);
      foreach ($rows as $row) {
        $group = $this->entityByUuid((string) $row['group']);
        $this->assertNotNull($group, "Pack $pack: group {$row['group']} loaded.");
        $path = '/group/' . $group->id() . '/topics';
        $hidden = array_map('strval', $row['hidden_titles'] ?? []);
        $this->assertNotEmpty($hidden, "Pack $pack: the {$row['persona']} listing names a title to hide.");
        $control = $this->controlMember($pack, (string) $row['group']);

        // The control: a member of the group sees each title, so a hidden
        // title is hidden by access and not by a listing that is empty.
        $this->asPersona($control, function () use ($pack, $path, $group, $hidden): void {
          $this->drupalGet($path);
          $this->assertSession()->statusCodeEquals(200);
          $text = $this->getSession()->getPage()->getText();
          $this->assertStringContainsString((string) $group->label(), $text, "Pack $pack: the group's own list names the group.");
          foreach ($hidden as $title) {
            $this->assertStringContainsString($title, $text, "Pack $pack: a member of the group sees '$title' in its list.");
          }
        });

        $this->asPersona((string) $row['persona'], function () use ($pack, $path, $group, $hidden, $row): void {
          $this->drupalGet($path);
          $this->assertSession()->statusCodeEquals(200);
          $text = $this->getSession()->getPage()->getText();
          $this->assertStringContainsString((string) $group->label(), $text, "Pack $pack: the list is the group's own page.");
          foreach ($hidden as $title) {
            $this->assertStringNotContainsString($title, $text, "Pack $pack: {$row['persona']} sees '$title' in the group's list.");
          }
        });
      }
      $this->removePack($pack);
    }
  }

  /**
   * The first member the pack's YAML lists for a group.
   */
  private function controlMember(string $pack, string $group_uuid): string {
    foreach (self::entries(self::packYaml($pack)['group.yml'] ?? []) as [$uuid, $data]) {
      if ($uuid === $group_uuid) {
        $members = array_values($data['members'] ?? []);
        $this->assertNotEmpty($members, "Pack $pack: group $group_uuid lists members.");
        return (string) $members[0];
      }
    }
    $this->fail("Pack $pack: group $group_uuid is in group.yml.");
  }

  /**
   * Runs a callback as a visitor or as one of the pack's users.
   *
   * A member gets a password made up inside the test and signs in through
   * the form. The password is never written anywhere and no request stays in
   * the browser output files, which record each request's response headers.
   */
  private function asPersona(string $persona, callable $callback): void {
    $output_enabled = $this->htmlOutputEnabled;
    // A visitor's first response sets a session cookie, and the browser output
    // files record response headers, so no request here writes one.
    $this->htmlOutputEnabled = FALSE;
    try {
      $this->asPersonaWithoutOutput($persona, $callback);
    }
    finally {
      $this->htmlOutputEnabled = $output_enabled;
    }
  }

  /**
   * Runs the callback as the persona; the caller keeps browser output off.
   */
  private function asPersonaWithoutOutput(string $persona, callable $callback): void {
    if ($persona === self::ANONYMOUS) {
      $this->drupalResetSession();
      $callback();
      return;
    }
    $user = $this->container->get('entity.repository')->loadEntityByUuid('user', $persona);
    $this->assertInstanceOf(UserInterface::class, $user, "Persona $persona is a pack user.");
    $password = bin2hex(random_bytes(12));
    $user->setPassword($password)->save();
    $user->passRaw = $password;
    $this->drupalResetSession();
    $this->drupalLogin($user);
    $callback();
    $this->drupalLogout();
  }

}
