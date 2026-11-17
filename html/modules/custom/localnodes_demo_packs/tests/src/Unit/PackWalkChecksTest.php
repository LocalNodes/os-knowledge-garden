<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_demo_packs\Unit;

use Drupal\Tests\localnodes_demo_packs\PackChecks;
use Drupal\Tests\localnodes_demo_packs\PackWalkChecks;
use Drupal\Tests\localnodes_demo_packs\Traits\PackTestTrait;
use Drupal\Tests\UnitTestCase;

/**
 * The screen and count rules of the walk, each shown a case that breaks it.
 *
 * Enrolments count only when they are confirmed going; the start of an event
 * is the time the screen shows, worked out from the pack's anchor in the
 * witness's timezone; the export confirmation screen has its heading, its
 * acknowledgement, its password control and its request button. Every page
 * here is made up.
 *
 * @group localnodes_demo_packs
 */
final class PackWalkChecksTest extends UnitTestCase {

  use PackTestTrait;

  /**
   * The smoke pack's parsed files, as a fresh copy.
   */
  private static function files(): array {
    return self::packYaml('smoke');
  }

  private static function stated(): array {
    return self::expectation('smoke', 'expect.yml')['going'] ?? [];
  }

  private function enrolments(array $files, ?array $stated = NULL): string {
    return implode(' | ', PackWalkChecks::enrolmentProblems('smoke', $files, $stated ?? self::stated()));
  }

  /**
   * The enrolments as written match the stated counts.
   */
  public function testEnrolmentsAsWrittenMatch(): void {
    $this->assertSame('', $this->enrolments(self::files()));
  }

  /**
   * An enrolment that is not "going" does not count.
   */
  public function testANotGoingEnrolmentDoesNotCount(): void {
    $files = self::files();
    foreach ($files['event-enrollment.yml'] as $key => $row) {
      $files['event-enrollment.yml'][$key]['field_enrollment_status'] = 0;
    }
    $this->assertStringContainsString('not the stated count', $this->enrolments($files));
    // A missing status is not going either.
    foreach ($files['event-enrollment.yml'] as $key => $row) {
      unset($files['event-enrollment.yml'][$key]['field_enrollment_status']);
    }
    $this->assertStringContainsString('not the stated count', $this->enrolments($files));
  }

  /**
   * Counts of "going" are of confirmed going: the status 1 and nothing else.
   */
  public function testGoingCountsOnlyStatusOne(): void {
    $this->assertTrue(PackWalkChecks::isGoing(['field_enrollment_status' => 1]));
    $this->assertTrue(PackWalkChecks::isGoing(['field_enrollment_status' => '1']));
    $this->assertFalse(PackWalkChecks::isGoing(['field_enrollment_status' => 0]));
    $this->assertFalse(PackWalkChecks::isGoing(['field_enrollment_status' => '']));
    $this->assertFalse(PackWalkChecks::isGoing([]));
    $this->assertSame(1, PackWalkChecks::GOING_STATUS);
  }

  /**
   * A member outside the event's group, or an unknown event, is reported.
   */
  public function testEnrolmentOfAnOutsiderIsReported(): void {
    $files = self::files();
    foreach ($files['event-enrollment.yml'] as $key => $row) {
      $files['event-enrollment.yml'][$key]['uid'] = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d03';
    }
    $this->assertStringContainsString('not in the event', $this->enrolments($files));
    $files = self::files();
    foreach ($files['event-enrollment.yml'] as $key => $row) {
      $files['event-enrollment.yml'][$key]['field_event'] = 'no-such-event';
    }
    $this->assertStringContainsString('pack event', $this->enrolments($files));
    $this->assertStringContainsString('does not hold', $this->enrolments(self::files(), ['no-such-event' => 1] + self::stated()));
  }

  /**
   * The start as the screen shows it: the long date, the time, the timezone.
   */
  public function testShownStartFollowsTheWitnessTimezone(): void {
    $this->assertSame('21 November 2026 18:00', PackWalkChecks::shownStart('2026-11-21T18:00:00', 'UTC', 'j F Y', 'H:i'));
    $this->assertSame('21 November 2026 11:00', PackWalkChecks::shownStart('2026-11-21T18:00:00', 'America/Denver', 'j F Y', 'H:i'));
    $this->assertSame('22 November 2026 7:00 AM', PackWalkChecks::shownStart('2026-11-21T18:00:00', 'Pacific/Auckland', 'j F Y', 'g:i A'));
  }

  /**
   * A wrong or missing displayed time is reported.
   */
  public function testWrongOrMissingShownStartIsReported(): void {
    $expected = '21 November 2026 18:00';
    $this->assertSame([], PackWalkChecks::startProblems('Smoke meetup 21 November 2026 18:00 - 20:00 (UTC) Smoke hall', $expected));
    $this->assertNotSame([], PackWalkChecks::startProblems('Smoke meetup 21 November 2026 17:00 - 20:00 (UTC) Smoke hall', $expected));
    $this->assertNotSame([], PackWalkChecks::startProblems('Smoke meetup 22 November 2026 18:00 - 20:00 (UTC)', $expected));
    $this->assertNotSame([], PackWalkChecks::startProblems('Smoke meetup Smoke hall', $expected));
    $this->assertNotSame([], PackWalkChecks::startProblems('Smoke meetup 21 November 2026 18:00', ''));
    // The date alone, or the time alone, is not the start.
    $this->assertNotSame([], PackWalkChecks::startProblems('21 November 2026 and later 18:00', $expected));
    // A time that only ends a longer number is not it.
    $this->assertNotSame([], PackWalkChecks::startProblems('121 November 2026 18:00', $expected));
    $this->assertNotSame([], PackWalkChecks::startProblems('21 November 2026 18:005', $expected));
  }

  /**
   * The export confirmation screen as Open Social renders it, made up.
   */
  private static function confirmPage(array $leave_out = []): string {
    $parts = [
      'heading' => '<h2>Confirm it is you</h2><p>Use your current password to request this copy and each time you download it.</p>',
      'ack' => '<div class="form-item"><input type="checkbox" id="edit-acknowledged" name="acknowledged" required><label for="edit-acknowledged">I understand this copy includes private records from every group.</label></div>',
      'password' => '<div class="form-item"><label for="edit-password">Your current password</label><input type="password" id="edit-password" name="password" required></div>',
      'button' => '<div class="form-actions"><input type="submit" id="edit-submit" value="Confirm and request export" class="button"></div>',
    ];
    foreach ($leave_out as $name) {
      unset($parts[$name]);
    }
    return '<html><body><main><div class="ln-export-confirm"><form id="localnodes-site-export">' . implode('', $parts) . '</form></div></main></body></html>';
  }

  private function confirm(string $html): string {
    return implode(' | ', PackWalkChecks::exportConfirmProblems($html));
  }

  /**
   * The whole confirmation screen has no problems.
   */
  public function testTheConfirmationScreenPasses(): void {
    $this->assertSame('', $this->confirm(self::confirmPage()));
  }

  /**
   * A 200 page without the confirmation is reported, part by part.
   */
  public function testEachMissingPartIsReported(): void {
    $this->assertStringContainsString('heading', $this->confirm(self::confirmPage(['heading'])));
    $this->assertStringContainsString('acknowledgement', $this->confirm(self::confirmPage(['ack'])));
    $this->assertStringContainsString('password', $this->confirm(self::confirmPage(['password'])));
    $this->assertStringContainsString('request button', $this->confirm(self::confirmPage(['button'])));
    // A blank page, a refusal and another page of the site.
    $this->assertNotSame('', $this->confirm('<html><body><main></main></body></html>'));
    $this->assertNotSame('', $this->confirm('<html><body><main><div class="messages">This site has no owner on record, so exporting is off.</div></main></body></html>'));
    $this->assertNotSame('', $this->confirm('<html><body><main><h1>Export this site</h1><a href="/x">Export this site</a></main></body></html>'));
  }

  /**
   * Each part counts by its kind: not as a paragraph, a link or a text box.
   */
  public function testPartsCountByTheirKind(): void {
    $page = self::confirmPage();
    $heading_as_paragraph = str_replace('<h2>Confirm it is you</h2>', '<p>Confirm it is you</p>', $page);
    $this->assertStringContainsString('heading', $this->confirm($heading_as_paragraph));
    $button_as_link = str_replace('<input type="submit" id="edit-submit" value="Confirm and request export" class="button">', '<a href="/go">Confirm and request export</a>', $page);
    $this->assertStringContainsString('request button', $this->confirm($button_as_link));
    $text_box = str_replace('type="password"', 'type="text"', $page);
    $this->assertStringContainsString('password', $this->confirm($text_box));
    $disabled = str_replace('id="edit-submit"', 'id="edit-submit" disabled', $page);
    $this->assertStringContainsString('request button', $this->confirm($disabled));
    $unchecked_box = str_replace('type="checkbox"', 'type="hidden"', $page);
    $this->assertStringContainsString('acknowledgement', $this->confirm($unchecked_box));
  }

  /**
   * The acknowledgement is a checkbox itself: a text box with its label is not.
   */
  public function testAcknowledgementAsATextBoxIsReported(): void {
    $page = str_replace('type="checkbox"', 'type="text"', self::confirmPage());
    $this->assertStringContainsString('acknowledgement', $this->confirm($page));
  }

  /**
   * A heading and a button outside the confirmation's region do not count.
   */
  public function testHeadingAndButtonOutsideTheRegionDoNotCount(): void {
    $page = self::confirmPage();
    $heading = '<h2>Confirm it is you</h2>';
    $button = '<input type="submit" id="edit-submit" value="Confirm and request export" class="button">';
    $moved = str_replace([$heading, $button], '', $page);
    $moved = str_replace('<main>', '<main>' . $heading . $button, $moved);
    $message = $this->confirm($moved);
    $this->assertStringContainsString('heading', $message);
    $this->assertStringContainsString('request button', $message);
    $this->assertStringNotContainsString('password', $message);
  }

  /**
   * The parts count only inside the confirmation's own region.
   */
  public function testPartsOutsideTheConfirmationDoNotCount(): void {
    $page = str_replace('class="ln-export-confirm"', 'class="other"', self::confirmPage());
    $this->assertNotSame('', $this->confirm($page));
  }

  /**
   * A like or an enrolment on a record in no group must be by the pack's people.
   *
   * The same record in a group keeps the group rule. The outsider here is a
   * made-up uuid that no pack file holds.
   */
  public function testNoGroupRecordsTakeOnlyThePacksPeople(): void {
    $outsider = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4dff';
    $post = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d40';
    $event = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d60';
    $likes = self::expectation('smoke', 'expect.yml')['likes'] ?? [];
    $files = self::files();
    $this->assertSame([], PackChecks::likeProblems('smoke', $files, $likes));

    // In no group, the pack's own people may still like and enrol.
    unset($files['post.yml'][$post]['group'], $files['event.yml'][$event]['group']);
    $this->assertSame([], PackChecks::likeProblems('smoke', $files, $likes));
    $this->assertSame('', $this->enrolments($files));

    // Someone who is not one of the pack's people may not.
    foreach ($files['like.yml'] as $key => $row) {
      $files['like.yml'][$key]['uid'] = $outsider;
    }
    foreach ($files['event-enrollment.yml'] as $key => $row) {
      $files['event-enrollment.yml'][$key]['uid'] = $outsider;
    }
    $this->assertStringContainsString("the liker is not one of the pack's people", implode(' | ', PackChecks::likeProblems('smoke', $files, $likes)));
    $this->assertStringContainsString("the member is not one of the pack's people", $this->enrolments($files));

    // Back in a group, the outsider fails the group rule instead.
    $grouped = self::files();
    foreach ($grouped['like.yml'] as $key => $row) {
      $grouped['like.yml'][$key]['uid'] = $outsider;
    }
    foreach ($grouped['event-enrollment.yml'] as $key => $row) {
      $grouped['event-enrollment.yml'][$key]['uid'] = $outsider;
    }
    $this->assertStringContainsString("the liker is not a member of the post's group", implode(' | ', PackChecks::likeProblems('smoke', $grouped, $likes)));
    $this->assertStringContainsString("the member is not in the event's group", $this->enrolments($grouped));
  }

  /**
   * A like counts against the stated number, and never by the post's author.
   */
  public function testLikeCountsAndAuthors(): void {
    $post = '5b0c1f3e-0d6a-4c51-9a5e-1f0a2b3c4d40';
    $files = self::files();
    $this->assertStringContainsString('not the stated count', implode(' | ', PackChecks::likeProblems('smoke', $files, [$post => 2])));
    foreach ($files['like.yml'] as $key => $row) {
      $files['like.yml'][$key]['uid'] = $files['post.yml'][$post]['uid'];
    }
    $likes = self::expectation('smoke', 'expect.yml')['likes'] ?? [];
    $this->assertStringContainsString('likes their own post', implode(' | ', PackChecks::likeProblems('smoke', $files, $likes)));
  }

}
