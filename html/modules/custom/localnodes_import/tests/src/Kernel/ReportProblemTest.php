<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\symfony_mailer_test\MailerTestTrait;
use Drupal\user\UserInterface;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Report a problem with this page" and the takedown (ONB-05, D-25).
 *
 * Every case runs through the real forms, the real routes' access checks and
 * the real mail manager. symfony_mailer_test forces the null transport and
 * keeps each message, so no mail leaves the test. The reporter is anonymous
 * everywhere: a rights holder is usually not a member.
 *
 * Copy comes from the approved 23-UI-SPEC.md, sections 10 and 11 and the
 * "Report notification" mail table.
 *
 * @group phase23
 * @group localnodes_import
 */
final class ReportProblemTest extends ImportKernelTestBase {

  use MailerTestTrait;

  private const REPORT_FORM = 'Drupal\localnodes_import\Form\ReportProblemForm';

  private const TAKEDOWN_FORM = 'Drupal\localnodes_import\Form\TakedownConfirmForm';

  private const COMMUNITY = 'Repair Collective';

  private const HOST = 'host.reports@mail.localnodes.xyz';

  private const REPORTER = 'rights.holder@mail.localnodes.xyz';

  private const PAGE_TITLE = 'Watershed commons primer';

  private const THANKS_BOTH = "Thanks. We've sent your report to this site's organizers and to the host.";

  private const THANKS_ORGANIZERS = "Thanks. We've sent your report to this site's organizers. If it's urgent, email LocalNodes at hello@localnodes.xyz.";

  private const THANKS_HOST = "Thanks. We've sent your report to the people who host this site.";

  private const THANKS_PRIVATE = "Thanks. We've sent your report to this site's organizers. The host is told a report was made, without its details.";

  private const NOT_SENT ="We couldn't send your report. Your text is still below. Try again in a few minutes, or email LocalNodes at hello@localnodes.xyz.";

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dblog', 'symfony_mailer', 'symfony_mailer_test'];

  /**
   * {@inheritdoc}
   *
   * The stub mail theme has no schema for the bootstrap settings the real
   * themes carry (same reason as the 22.1-07 mail tests).
   */
  protected $strictConfigSchema = FALSE;

  private GroupInterface $group;

  private NodeInterface $topic;

  private UserInterface $steward;

  private UserInterface $organizer;

  private UserInterface $member;

  /**
   * {@inheritdoc}
   *
   * The module installer logs while it installs the importer, so the
   * watchdog table must exist before that.
   */
  protected function setUpBeforeImporter(): void {
    $this->installSchema('dblog', ['watchdog']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'filter', 'symfony_mailer']);
    $this->container->get('theme_installer')->install(['localnodes_stock_email_test']);
    $this->config('system.theme')->set('default', 'localnodes_stock_email_test')->save();
    $this->config('system.site')->set('name', self::COMMUNITY)->set('mail', 'noreply@mail.localnodes.xyz')->save();
    $this->setSetting('localnodes_host_report_email', self::HOST);

    $this->steward = $this->createAccount('site_steward', 'steward@mail.localnodes.xyz', ['sitemanager', 'verified']);
    $this->group = $this->createFlexibleGroup('Repair circle');
    $this->organizer = $this->createAccount('circle_organizer', 'organizer@mail.localnodes.xyz', ['verified']);
    $this->addManager($this->group, $this->organizer);
    $this->member = $this->createAccount('plain_member', 'member@mail.localnodes.xyz', ['verified']);
    $this->addMember($this->group, $this->member);

    $this->topic = $this->importedTopic($this->group, self::PAGE_TITLE);
    $this->setCurrentUser($this->anonymous());
  }

  /**
   * The whole path: link, anonymous report, mail to all three, takedown.
   */
  public function testReportReachesStewardOrganizerAndHostThenTakedownUnpublishes(): void {
    // The Source box of an imported page carries the link.
    $html = $this->renderFull($this->topic);
    $this->assertStringContainsString('href="/node/' . $this->topic->id() . '/report-problem"', $html);
    $this->assertStringContainsString('Report a problem with this page', $html);

    // An anonymous visitor reports it.
    [, $state] = $this->submitReport($this->topic, [
      'reason' => 'owner',
      'details' => 'I wrote this and nobody asked me.',
      'contact_email' => self::REPORTER,
    ]);
    $this->assertSame([], array_map('strval', $state->getErrors()));

    // One mail per person: the steward, the group's organizers, the host.
    // The account that created the group is its manager too (Group gives the
    // creator the manager role), so it is a recipient.
    $mails = $this->sentMails();
    $this->assertEqualsCanonicalizing(
      ['steward@mail.localnodes.xyz', 'organizer@mail.localnodes.xyz', $this->siteOwner->getEmail(), self::HOST],
      array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $mails),
      'The steward, the group organizers and the host each get the report',
    );
    $this->assertNotContains('member@mail.localnodes.xyz', array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $mails));

    $takedown = '/node/' . $this->topic->id() . '/takedown';
    foreach ($mails as $mail) {
      $this->assertSame('A page was reported on ' . self::COMMUNITY, $mail->getSubject());
      $body = $this->plain((string) $mail->getHtmlBody());
      $this->assertStringContainsString('Someone reported ' . self::PAGE_TITLE, $body);
      $this->assertStringContainsString("I own this and didn't agree to it being shared", $body);
      $this->assertStringContainsString('I wrote this and nobody asked me.', $body);
      $this->assertStringContainsString(self::REPORTER, $body, 'The contact address is inside the mail');
      $this->assertStringContainsString('Review the page', $body);
      $this->assertStringContainsString('Unpublish this page', $body);
      // The two links, as absolute addresses in the markup.
      $html = (string) $mail->getHtmlBody();
      $this->assertMatchesRegularExpression('#href="https?://[^"/]+' . preg_quote($takedown, '#') . '"#', $html, 'The link to the takedown page');
      $this->assertMatchesRegularExpression('#href="https?://[^"/]+/node/' . $this->topic->id() . '"#', $html, 'The link to the page');
    }

    // The thank-you comes as a status message and a redirect to its own page.
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains("Thanks. We've sent your report to this site's organizers and to the host.", $messages);
    $state->setProgrammed(FALSE);
    $this->assertSame('localnodes_import.report_sent', $state->getRedirect()->getRouteName());

    // Takedown: the group's organizer may; a plain member and a stranger may not.
    $this->assertTrue($this->routeAccess('localnodes_import.takedown', $this->topic, $this->organizer), 'The group organizer may take the page down');
    $this->assertTrue($this->routeAccess('localnodes_import.takedown', $this->topic, $this->steward), 'A site manager may');
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $this->topic, $this->member), 'A plain member gets 403');
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $this->topic, $this->anonymous()), 'Anonymous gets 403');
    $this->assertTrue($this->routeAccess('localnodes_import.report_problem', $this->topic, $this->anonymous()), 'Anyone may report');

    $this->setCurrentUser($this->organizer);
    $this->assertTrue($this->topic->access('view', $this->anonymous()), 'Visible before the takedown');
    [, $state] = $this->submitTakedown($this->topic);
    $this->assertSame([], array_map('strval', $state->getErrors()));

    $node = $this->reloadNode((int) $this->topic->id());
    $this->assertFalse($node->isPublished(), 'The page is unpublished');
    $this->assertNotNull($node, 'Nothing was deleted');
    $this->assertFalse($node->access('view', $this->anonymous()), 'An anonymous visitor cannot see the page now');
    $this->assertFalse($node->access('view', $this->member), 'Nor a member');
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains(self::PAGE_TITLE . ' is unpublished. To publish it again, edit the page and turn Published back on.', $messages);
  }

  public function testHousePrivacyChangeExpiresPublicTakedownAccess(): void {
    require_once DRUPAL_ROOT . '/modules/custom/localnodes_platform/localnodes_platform.install';
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $check = $this->container->get('localnodes_import.takedown_access');
    $allowed = $check->check($this->topic, $this->steward);
    $this->assertTrue($allowed->isAllowed());
    $this->assertContains('group:' . $this->group->id(), $allowed->getCacheTags());
    $cache = $this->container->get('cache.default');
    $cache->set('house-report-takedown', TRUE, \Drupal\Core\Cache\Cache::PERMANENT, $allowed->getCacheTags());
    $this->assertNotFalse($cache->get('house-report-takedown'));
    $this->group->set('field_flexible_group_visibility', 'members')->save();
    $this->assertFalse($cache->get('house-report-takedown'), 'The actual group save invalidates allowed route access.');
    // A subsequent HTTP request has fresh field-reference objects as well as
    // access results. The cached relationship above still holds its earlier
    // referenced group object within this test request.
    $this->container->get('entity_type.manager')->getStorage('group_content')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('group')->resetCache([$this->group->id()]);
    $this->container->get('entity_type.manager')->getAccessControlHandler('group')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->topic = $this->reloadNode((int) $this->topic->id());
    $audience = $this->container->get('localnodes_import.report_audience');
    $groups = $audience->groupsOf($this->topic);
    $this->assertSame('members', $groups[(int) $this->group->id()]->get('field_flexible_group_visibility')->value);
    $this->assertFalse($audience->isSteward($this->topic, $this->steward));
    $this->assertFalse($check->check($this->topic, $this->steward)->isAllowed());
    $this->assertTrue($check->check($this->topic, $this->organizer)->isAllowed());
  }

  /**
   * A private-page report stays with its house managers and the host.
   *
   * @dataProvider privateHouseSiteRoles
   */
  public function testPrivateReportExcludesOutsideSiteRoles(string $role_id, string $visibility): void {
    // The profile's feature install hooks provide these real site-wide grants;
    // the kernel fixture imports its role config without running those hooks.
    foreach (['sitemanager', 'contentmanager'] as $id) {
      $role = Role::load($id);
      $role->grantPermission('bypass node access')->grantPermission('manage all groups')->save();
    }
    $outside = $role_id === 'sitemanager' ? $this->steward : $this->createAccount('outside_content_manager', 'content.manager@example.test', ['contentmanager', 'verified']);
    $this->group->set('field_flexible_group_visibility', $visibility)->save();
    $this->topic->set('field_content_visibility', 'group')->save();
    require_once DRUPAL_ROOT . '/modules/custom/localnodes_platform/localnodes_platform.install';
    // The legacy-main baseline has no narrowing helper; the real access
    // assertions below must then expose its shipped manager bypasses.
    if (function_exists('_localnodes_platform_protect_private_groups')) {
      _localnodes_platform_protect_private_groups();
    }
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->topic = $this->reloadNode((int) $this->topic->id());

    $this->assertFalse($this->topic->access('view', $outside), 'A site role is not private-house access.');
    $this->assertFalse($this->routeAccess('localnodes_import.report_problem', $this->topic, $outside));
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $this->topic, $outside));
    $this->assertFalse($this->container->get('localnodes_import.report_audience')->isSteward($this->topic, $outside));
    $this->assertTrue($this->topic->access('view', $this->member));
    $this->assertTrue($this->routeAccess('localnodes_import.report_problem', $this->topic, $this->member));
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $this->topic, $this->member));
    $this->assertTrue($this->routeAccess('localnodes_import.takedown', $this->topic, $this->organizer));

    // A site manager may become an ordinary house member without becoming a
    // house moderator or receiving private report details.
    $this->addMember($this->group, $this->steward);
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->topic = $this->reloadNode((int) $this->topic->id());
    $this->assertTrue($this->topic->access('view', $this->steward));
    $this->assertTrue($this->routeAccess('localnodes_import.report_problem', $this->topic, $this->steward));
    $this->assertFalse($this->container->get('localnodes_import.report_audience')->isSteward($this->topic, $this->steward));
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $this->topic, $this->steward));

    $this->setCurrentUser($this->member);
    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => 'Private house report details.']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $addresses = array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $this->sentMails());
    $this->assertEqualsCanonicalizing(['organizer@mail.localnodes.xyz', $this->siteOwner->getEmail(), self::HOST], $addresses);
    $this->assertNotContains($outside->getEmail(), $addresses);
    $this->assertNotContains($this->steward->getEmail(), $addresses);
    $this->setCurrentUser($this->organizer);
    [, $state] = $this->submitTakedown($this->topic);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertFalse($this->reloadNode((int) $this->topic->id())->isPublished(), 'The house manager retains takedown.');
  }

  public static function privateHouseSiteRoles(): array {
    return [
      'site manager, closed' => ['sitemanager', 'community'],
      'site manager, secret' => ['sitemanager', 'members'],
      'content manager, closed' => ['contentmanager', 'community'],
      'content manager, secret' => ['contentmanager', 'members'],
    ];
  }

  /**
   * The takedown confirmation's words, in the approved order.
   */
  public function testTakedownConfirmationCopy(): void {
    $this->setCurrentUser($this->organizer);
    $form = $this->buildTakedown($this->topic);
    $this->assertSame('Unpublish ' . self::PAGE_TITLE . '?', (string) $form['#title']);
    $this->assertSame("This hides the page from everyone except people who can edit it. Nothing is deleted. You can publish it again from the page's edit form.", (string) $form['description']['#markup']);
    $this->assertSame('Unpublish this page', (string) $form['actions']['submit']['#value']);
    $this->assertSame('danger', $form['actions']['submit']['#button_type']);
    $this->assertSame('Keep page published', (string) $form['actions']['cancel']['#title']);
    $this->assertSame('autofocus', $form['actions']['cancel']['#attributes']['autofocus'] ?? NULL, 'The safe choice gets initial focus');
    $this->assertSame('/node/' . $this->topic->id(), $form['actions']['cancel']['#url']->toString(), 'Keeping it goes back to the page');
  }

  /**
   * The report form's copy: title, legend, four reasons, optional fields.
   */
  public function testReportFormCopy(): void {
    $form = $this->buildReport($this->topic);
    $this->assertSame('radios', $form['reason']['#type']);
    $this->assertSame("What's the problem?", (string) $form['reason']['#title']);
    $this->assertSame([
      "I own this and didn't agree to it being shared",
      "It shares someone's personal information",
      "It's wrong or harmful",
      'Something else',
    ], array_values(array_map('strval', $form['reason']['#options'])));
    $this->assertEmpty($form['reason']['#default_value'] ?? NULL, 'No radio is pre-selected');
    $this->assertSame('Details (optional)', (string) $form['details']['#title']);
    $this->assertSame("Say what's wrong and, if you can, where on the page. Up to 2,000 characters.", (string) $form['details']['#description']);
    $this->assertSame('Your email (optional)', (string) $form['contact_email']['#title']);
    $this->assertSame("Add it if you'd like a reply. The organizers and the host will see it.", (string) $form['contact_email']['#description']);
    $this->assertSame('Send report', (string) $form['actions']['submit']['#value']);
    $this->assertStringContainsString("Tell us what's wrong with " . self::PAGE_TITLE . '.', (string) $form['intro']['#markup']);
  }

  /**
   * Choosing no reason, too much text or a bad address sends nothing.
   */
  public function testValidationMessagesAndNothingSent(): void {
    [, $state] = $this->submitReport($this->topic, ['details' => 'x']);
    $this->assertSame(['reason' => 'Choose what the problem is.'], array_map('strval', $state->getErrors()));

    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => str_repeat('a', 2001)]);
    $this->assertSame(['details' => 'Shorten the details to 2,000 characters or fewer.'], array_map('strval', $state->getErrors()));

    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'contact_email' => 'not an address']);
    $this->assertSame(['contact_email' => "This isn't an email address. Check it has an @ and a domain, like name@example.org."], array_map('strval', $state->getErrors()));

    // Exactly 2,000 characters is fine.
    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => str_repeat('a', 2000)]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertCount(4, $this->sentMails(), 'Only the valid report went out (four people)');
  }

  /**
   * A reason alone is a complete report: no details, no address.
   */
  public function testReasonOnlyReportIsAccepted(): void {
    [, $state] = $this->submitReport($this->topic, ['reason' => 'harmful']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $mails = $this->sentMails();
    $this->assertCount(4, $mails);
    $body = $this->plain((string) $mails[0]->getHtmlBody());
    $this->assertStringContainsString("Reason: It's wrong or harmful", $body);
    $this->assertStringContainsString('Details: none', $body);
    $this->assertStringNotContainsString('Their email', $body, 'No address was given, so none is shown');
  }

  /**
   * Two reports about the same page are two separate reports.
   */
  public function testDuplicateReportsAreDeliveredSeparately(): void {
    $this->submitReport($this->topic, ['reason' => 'owner', 'details' => 'First report text.']);
    $first = $this->sentMails();
    $this->submitReport($this->topic, ['reason' => 'other', 'details' => 'Second report text.']);
    $second = $this->sentMails();
    $this->assertCount(4, $first);
    $this->assertCount(4, $second);
    foreach ($first as $mail) {
      $this->assertStringContainsString('First report text.', (string) $mail->getHtmlBody());
      $this->assertStringNotContainsString('Second report text.', (string) $mail->getHtmlBody());
    }
    foreach ($second as $mail) {
      $this->assertStringContainsString('Second report text.', (string) $mail->getHtmlBody());
    }
    $this->assertCount(2, $this->watchdog('A problem was reported'), 'One log record per report');
  }

  /**
   * More than 5 reports an hour from one connection are refused, unsent.
   */
  public function testFloodLimitStopsTheSixthReport(): void {
    for ($i = 1; $i <= 5; $i++) {
      [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => "Report $i"]);
      $this->assertSame([], array_map('strval', $state->getErrors()), "Report $i is accepted");
      $this->assertCount(4, $this->sentMails());
    }
    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => 'Report 6']);
    $this->assertSame(['flood' => 'Too many reports from this connection. Wait a while, then try again.'], array_map('strval', $state->getErrors()));
    $this->assertSame([], $this->sentMails(), 'Nothing is sent over the limit');
    $this->assertCount(5, $this->watchdog('A problem was reported'), 'Nor logged');

    // The form itself says so, above the fields, before anyone types.
    $form = $this->buildReport($this->topic);
    $this->assertSame('alert', $form['flood_alert']['#attributes']['role'] ?? NULL);
    $this->assertSame('Too many reports from this connection. Wait a while, then try again.', (string) $form['flood_alert']['#value']);
  }

  /**
   * A filled honeypot gets the normal thank-you and nothing is sent.
   */
  public function testHoneypotDropsTheReportSilently(): void {
    $before = $this->container->get('messenger')->messagesByType('status');
    [$form, $state] = $this->submitReport($this->topic, ['reason' => 'owner', 'website' => 'https://spam.example']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $this->assertSame([], $this->sentMails(), 'Nothing is sent');
    $this->assertSame([], $this->watchdog('A problem was reported'), 'Nothing is logged');
    // The same reply a real report gets, so a bot learns nothing.
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains("Thanks. We've sent your report to this site's organizers and to the host.", $messages);
    $state->setProgrammed(FALSE);
    $this->assertSame('localnodes_import.report_sent', $state->getRedirect()->getRouteName());
    $this->assertSame([], $before);

    // The trap is hidden from sight and from assistive technology.
    $this->assertSame('true', $form['trap']['#attributes']['aria-hidden']);
    $this->assertContains('visually-hidden', $form['trap']['#attributes']['class']);
    $this->assertSame('-1', $form['trap']['website']['#attributes']['tabindex']);
    $this->assertSame('off', $form['trap']['website']['#attributes']['autocomplete']);
  }

  /**
   * Taking down a page that is already unpublished changes nothing.
   */
  public function testTakedownOfAnAlreadyUnpublishedPageChangesNothing(): void {
    $this->topic->setUnpublished()->save();
    $revision = (int) $this->reloadNode((int) $this->topic->id())->getRevisionId();
    $this->setCurrentUser($this->organizer);

    $form = $this->buildTakedown($this->reloadNode((int) $this->topic->id()));
    $this->assertSame('This page is already unpublished.', (string) $form['already']['#value']);
    $this->assertArrayNotHasKey('submit', $form['actions'], 'No button that would do nothing');

    $this->submitTakedown($this->reloadNode((int) $this->topic->id()));
    $node = $this->reloadNode((int) $this->topic->id());
    $this->assertFalse($node->isPublished());
    $this->assertSame($revision, (int) $node->getRevisionId(), 'No new revision was made');
    $messages = array_map('strval', $this->container->get('messenger')->messagesByType('status'));
    $this->assertContains('This page is already unpublished.', $messages);
    $this->assertSame([], $this->watchdog('Page @nid was unpublished'), 'Nothing was logged as a takedown');
  }

  /**
   * A page in no group is reported to the site managers and the host only.
   */
  public function testPageInNoGroupGoesToStewardsAndHost(): void {
    $loose = $this->importedTopic(NULL, 'A page with no group');
    [, $state] = $this->submitReport($loose, ['reason' => 'owner']);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $to = array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $this->sentMails());
    $this->assertEqualsCanonicalizing(['steward@mail.localnodes.xyz', self::HOST], $to);

    // The steward can still take it down, with no group to ask.
    $this->assertTrue($this->routeAccess('localnodes_import.takedown', $loose, $this->steward));
    $this->assertFalse($this->routeAccess('localnodes_import.takedown', $loose, $this->organizer), 'A group organizer has no say over a page outside the group');
  }

  /**
   * With no host address the stewards still get the report; one warning.
   */
  public function testNoHostSettingStillMailsStewardsAndWarnsOnce(): void {
    $this->setSetting('localnodes_host_report_email', '');
    [, $state] = $this->submitReport($this->topic, ['reason' => 'owner', 'contact_email' => self::REPORTER]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $to = array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $this->sentMails());
    $this->assertEqualsCanonicalizing(['steward@mail.localnodes.xyz', 'organizer@mail.localnodes.xyz', $this->siteOwner->getEmail()], $to);
    $this->assertSame([self::THANKS_ORGANIZERS], $this->statusMessages(), 'The thank-you names only the organizers');

    $warnings = $this->watchdog('No host address is set');
    $this->assertCount(1, $warnings, 'One warning');
    $this->assertSame(RfcLogLevel::WARNING, (int) $warnings[0]->severity);
    $this->assertSame((int) $this->topic->id(), (int) unserialize($warnings[0]->variables, ['allowed_classes' => FALSE])['@nid'], 'It names the page by id');
    $this->assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.\w/', (string) $warnings[0]->message . $warnings[0]->variables, 'It names no address');
  }

  /**
   * Only imported pages have the link and the report route.
   */
  public function testNonImportedPageHasNoLinkAndNoReportRoute(): void {
    $plain = $this->createGroupTopic($this->group, 'A page someone wrote', '<p>Written here.</p>', 'public');
    $this->assertStringNotContainsString('report-problem', $this->renderFull($plain));
    $this->assertStringNotContainsString('Report a problem', $this->renderFull($plain));

    foreach ([
      fn () => $this->buildReport($plain),
      fn () => $this->buildTakedown($plain),
    ] as $build) {
      $this->setCurrentUser($this->steward);
      try {
        $build();
        $this->fail('Expected a 404');
      }
      catch (NotFoundHttpException) {
        $this->addToAssertionCount(1);
      }
    }
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition('\Drupal\localnodes_import\Controller\ReportSentController');
    $this->expectException(NotFoundHttpException::class);
    $controller->view($plain);
  }

  /**
   * The log holds the page's id and the reason's key, never the reporter's words.
   */
  public function testTheLogNeverHoldsTheReportersAddressOrWords(): void {
    $this->submitReport($this->topic, [
      'reason' => 'personal_info',
      'details' => 'Distinctive details phrase 7731.',
      'contact_email' => self::REPORTER,
    ]);
    $this->setCurrentUser($this->organizer);
    $this->submitTakedown($this->topic);

    $rows = $this->watchdog('');
    $this->assertNotEmpty($rows);
    foreach ($rows as $row) {
      $text = $row->message . ' ' . $row->variables;
      $this->assertStringNotContainsString(self::REPORTER, $text, 'No log record holds the reporter\'s address');
      $this->assertStringNotContainsString('rights.holder', $text);
      $this->assertStringNotContainsString('7731', $text, 'Nor the details');
    }
    $report = $this->watchdog('A problem was reported');
    $this->assertCount(1, $report);
    $variables = unserialize($report[0]->variables, ['allowed_classes' => FALSE]);
    $this->assertSame(['@nid' => (int) $this->topic->id(), '@reason' => 'personal_info'], $variables, 'The id and the reason key, nothing else');
    $takedown = $this->watchdog('Page @nid was unpublished');
    $this->assertCount(1, $takedown);
    $this->assertSame(['@nid' => (int) $this->topic->id(), '@uid' => (int) $this->organizer->id()], unserialize($takedown[0]->variables, ['allowed_classes' => FALSE]), 'Who and which page');
  }

  /**
   * What a reporter types is plain text in the mail, never markup.
   */
  public function testReporterTextIsEscapedInTheMail(): void {
    $this->submitReport($this->topic, [
      'reason' => 'other',
      'details' => '<script>alert(1)</script><b>bold</b>',
      'contact_email' => self::REPORTER,
    ]);
    $mails = $this->sentMails();
    $this->assertCount(4, $mails);
    $html = (string) $mails[0]->getHtmlBody();
    $this->assertStringNotContainsString('<script>', $html);
    $this->assertStringNotContainsString('<b>bold</b>', $html);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
  }

  /**
   * A page a signed-out visitor can't see: the host gets a bare alert only.
   *
   * The organizers' mails carry the whole report as before. The host's mail
   * names the site, the time, the page's id and whether an organizer got the
   * report, and nothing that came from the page or the reporter.
   *
   * @dataProvider nonPublicPages
   */
  public function testNonPublicPageSendsTheHostAnAlertWithoutDetails(string $group_visibility, string $page_visibility): void {
    $this->makeNonPublic($group_visibility, $page_visibility);
    $details = 'Distinctive private details 4417.';

    $this->setCurrentUser($this->member);
    $this->messenger()->deleteAll();
    [, $state] = $this->submitReport($this->topic, [
      'reason' => 'personal_info',
      'details' => $details,
      'contact_email' => self::REPORTER,
    ]);
    $this->assertSame([], array_map('strval', $state->getErrors()));

    $by_address = [];
    foreach ($this->sentMails() as $mail) {
      $by_address[$mail->getTo()[0]->getEmail()] = $mail;
    }
    $this->assertArrayHasKey(self::HOST, $by_address, 'The host is still told');
    $host = $by_address[self::HOST];
    unset($by_address[self::HOST]);
    $this->assertNotEmpty($by_address, 'At least one organizer got the report');

    // The organizers' mails are unchanged.
    foreach ($by_address as $mail) {
      $this->assertSame('A page was reported on ' . self::COMMUNITY, $mail->getSubject());
      $body = $this->plain((string) $mail->getHtmlBody());
      $this->assertStringContainsString('Someone reported ' . self::PAGE_TITLE, $body);
      $this->assertStringContainsString("It shares someone's personal information", $body);
      $this->assertStringContainsString($details, $body);
      $this->assertStringContainsString(self::REPORTER, $body);
      $this->assertStringContainsString('Unpublish this page', $body);
    }

    // The host's alert: no title, no details, no address, no page path.
    $this->assertSame('A page was reported on ' . self::COMMUNITY, $host->getSubject());
    $html = (string) $host->getHtmlBody();
    $body = $this->plain($html);
    foreach ([self::PAGE_TITLE, 'Watershed', $details, '4417', self::REPORTER, 'rights.holder', "It shares someone's personal information"] as $secret) {
      $this->assertStringNotContainsString($secret, $html, "The host's mail leaves out: $secret");
    }
    $this->assertStringNotContainsString('/node/', $html, 'No link to the page or its takedown');
    $this->assertStringNotContainsString('Unpublish this page', $body);
    $this->assertStringContainsString('Someone reported a page on ' . self::COMMUNITY, $body);
    $this->assertStringContainsString('Page id: ' . $this->topic->id(), $body);
    $this->assertMatchesRegularExpression('/When: \S.*\d{4}/', $body, 'The time of the report');
    $this->assertStringContainsString("The report reached this site's organizers.", $body);

    // The thank-you says the same.
    $this->assertSame([self::THANKS_PRIVATE], $this->statusMessages());
  }

  /**
   * The form's words on a page a signed-out visitor can't see.
   */
  public function testNonPublicPageFormSaysTheHostGetsNoDetails(): void {
    $this->makeNonPublic('members', 'group');
    $this->setCurrentUser($this->member);
    $form = $this->buildReport($this->topic);
    $this->assertSame("Tell us what's wrong with " . self::PAGE_TITLE . ". Your report goes to this site's organizers. The host is told a report was made, without its details.", (string) $form['intro']['#markup']);
    $this->assertSame("Add it if you'd like a reply. The organizers will see it. The host won't.", (string) $form['contact_email']['#description']);
  }

  public static function nonPublicPages(): array {
    return [
      'page in a members-only house' => ['members', 'group'],
      'members-only page in a public house' => ['public', 'group'],
      'signed-in-only page' => ['public', 'community'],
    ];
  }

  /**
   * A public page: the host still gets the whole report.
   */
  public function testPublicPageHostMailKeepsTheWholeReport(): void {
    $this->assertTrue($this->topic->access('view', $this->anonymous()), 'The page is public');
    $form = $this->buildReport($this->topic);
    $this->assertSame("Tell us what's wrong with " . self::PAGE_TITLE . ". Your report goes to this site's organizers and to the host of the site.", (string) $form['intro']['#markup']);

    $this->submitReport($this->topic, [
      'reason' => 'owner',
      'details' => 'Public page details 5521.',
      'contact_email' => self::REPORTER,
    ]);
    $host = array_values(array_filter($this->sentMails(), fn ($mail) => $mail->getTo()[0]->getEmail() === self::HOST));
    $this->assertCount(1, $host);
    $html = (string) $host[0]->getHtmlBody();
    $body = $this->plain($html);
    $this->assertStringContainsString('Someone reported ' . self::PAGE_TITLE, $body);
    $this->assertStringContainsString("Reason: I own this and didn't agree to it being shared", $body);
    $this->assertStringContainsString('Public page details 5521.', $body);
    $this->assertStringContainsString('Their email: ' . self::REPORTER, $body);
    $this->assertMatchesRegularExpression('#href="https?://[^"/]+/node/' . $this->topic->id() . '/takedown"#', $html);
    $this->assertStringNotContainsString('Page id:', $body, 'Not the bare alert');
    $this->assertSame([self::THANKS_BOTH], $this->statusMessages());
  }

  /**
   * A non-public page with no host address: the same single warning.
   */
  public function testNonPublicPageWithNoHostSettingWarnsOnce(): void {
    $this->makeNonPublic('members', 'group');
    $this->setSetting('localnodes_host_report_email', '');
    $this->setCurrentUser($this->member);
    $this->messenger()->deleteAll();
    [, $state] = $this->submitReport($this->topic, ['reason' => 'owner', 'contact_email' => self::REPORTER]);
    $this->assertSame([], array_map('strval', $state->getErrors()));
    $to = array_map(fn ($mail) => $mail->getTo()[0]->getEmail(), $this->sentMails());
    $this->assertEqualsCanonicalizing(['organizer@mail.localnodes.xyz', $this->siteOwner->getEmail()], $to);
    $this->assertSame([self::THANKS_ORGANIZERS], $this->statusMessages());
    $warnings = $this->watchdog('No host address is set');
    $this->assertCount(1, $warnings, 'One warning');
    $this->assertSame(RfcLogLevel::WARNING, (int) $warnings[0]->severity);
  }

  /**
   * A non-public page where no organizer got it: not sent, and the host's
   * alert says no organizer was reached.
   */
  public function testNonPublicPageWithNoOrganizerReachedIsNotSent(): void {
    $this->makeNonPublic('members', 'group');
    $this->setCurrentUser($this->member);
    $calls = $this->stubMailResults(['*' => FALSE, self::HOST => TRUE]);
    $this->assertNotSent(['reason' => 'owner', 'details' => 'Please look at this.']);
    $this->assertSame(self::HOST, end($calls->addresses), 'The host is told last, after every organizer send');
    $host = $calls->sends[count($calls->sends) - 1];
    $this->assertSame('report_host_alert', $host['key']);
    $this->assertFalse($host['params']['organizers_reached']);
    $this->assertSame(['nid', 'organizers_reached', 'reported_at'], array_keys($host['params']), 'Nothing from the reporter reaches the host');
    foreach (array_slice($calls->sends, 0, -1) as $send) {
      $this->assertSame('report_problem', $send['key'], 'Organizers get the whole report');
    }
  }

  /**
   * The honeypot's reply matches what a real report on the page would say.
   */
  public function testHoneypotOnNonPublicPageGivesThatPagesThanks(): void {
    $this->makeNonPublic('members', 'group');
    $this->setCurrentUser($this->member);
    $this->messenger()->deleteAll();
    $this->submitReport($this->topic, ['reason' => 'owner', 'website' => 'https://spam.example']);
    $this->assertSame([], $this->sentMails());
    $this->assertSame([self::THANKS_PRIVATE], $this->statusMessages());
  }

  /**
   * Makes the reported page one a signed-out visitor can't see.
   */
  private function makeNonPublic(string $group_visibility, string $page_visibility): void {
    $this->group->set('field_flexible_group_visibility', $group_visibility)->save();
    $this->topic->set('field_content_visibility', $page_visibility)->save();
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    $this->container->get('entity_type.manager')->getAccessControlHandler('group')->resetCache();
    $this->topic = $this->reloadNode((int) $this->topic->id());
    $this->assertFalse($this->topic->access('view', $this->anonymous()), 'A signed-out visitor cannot see the page');
    $this->assertTrue($this->topic->access('view', $this->member), 'A member can');
  }

  /**
   * Reads the log records whose message starts with the text.
   *
   * @return object[]
   *   watchdog rows, oldest first.
   */
  private function watchdog(string $starts_with): array {
    $rows = $this->container->get('database')->select('watchdog', 'w')
      ->fields('w')
      ->condition('type', 'localnodes_import')
      ->orderBy('wid')
      ->execute()
      ->fetchAll();
    return array_values(array_filter($rows, fn ($row) => str_starts_with((string) $row->message, $starts_with)));
  }

  /**
   * Creates an active account with an explicit address.
   *
   * @param string[] $roles
   *   Role ids.
   */
  private function createAccount(string $name, string $mail, array $roles): UserInterface {
    $account = $this->createUser([], $name, FALSE, ['roles' => $roles, 'mail' => $mail, 'status' => 1]);
    return $this->container->get('entity_type.manager')->getStorage('user')->load($account->id());
  }

  /**
   * Creates a published Topic in the group that the importer says it imported.
   */
  private function importedTopic(?GroupInterface $group, string $title): NodeInterface {
    if ($group !== NULL) {
      $topic = $this->createGroupTopic($group, $title, '<p>Body.</p>', 'public');
    }
    else {
      $topic = Node::create(['type' => 'topic', 'title' => $title, 'body' => ['value' => '<p>Body.</p>', 'format' => 'plain_text'], 'field_content_visibility' => 'public', 'uid' => $this->siteOwner->id(), 'status' => NodeInterface::PUBLISHED]);
      $topic->save();
    }
    $manager = $this->container->get('entity_type.manager');
    $run = $manager->getStorage('localnodes_import_run')->create([
      'gid' => $group?->id() ?? 0,
      'uid' => $this->steward->id(),
      'kind' => ImportRun::KIND_FILES,
      'status' => ImportRun::STATUS_FINISHED,
      'visibility' => 'public',
      'rights_confirmed_by' => $this->steward->id(),
      'rights_confirmed_at' => 1000,
    ]);
    $run->save();
    $manager->getStorage('localnodes_import_item')->create([
      'run' => $run->id(),
      'gid' => $group?->id() ?? 0,
      'source_name' => 'primer.pdf',
      'outcome' => ImportItem::OUTCOME_IMPORTED,
      'nid' => $topic->id(),
    ])->save();
    return $topic;
  }

  /**
   * Renders a Topic's full view as HTML.
   */
  private function renderFull(NodeInterface $node): string {
    $manager = $this->container->get('entity_type.manager');
    $manager->getStorage('node')->resetCache();
    $node = $manager->getStorage('node')->load($node->id());
    $build = $manager->getViewBuilder('node')->view($node, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
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
   * Every send failing: an error, the text kept, no thank-you.
   */
  public function testEverySendFailingSaysTheReportWasNotSent(): void {
    $this->stubMailResults(['*' => FALSE]);
    $this->assertNotSent(['reason' => 'owner', 'details' => 'Please look at this.']);
  }

  /**
   * Every send cancelled by a mail hook: also not sent.
   */
  public function testEverySendCancelledSaysTheReportWasNotSent(): void {
    $this->stubMailResults(['*' => NULL]);
    $this->assertNotSent(['reason' => 'owner', 'details' => 'Please look at this.']);
  }

  /**
   * Nobody to send to: not sent, and no mail is attempted.
   */
  public function testNoRecipientsSaysTheReportWasNotSent(): void {
    $this->setSetting('localnodes_host_report_email', '');
    $this->steward->block()->save();
    $calls = $this->stubMailResults(['*' => TRUE]);
    $loose = $this->importedTopic(NULL, 'A page with no group');
    $this->messenger()->deleteAll();
    [, $state] = $this->submitReport($loose, ['reason' => 'owner', 'details' => 'Nobody hears this.']);
    $this->assertSame([], $calls->addresses, 'No mail was attempted');
    $this->assertSame([self::NOT_SENT], $this->errorMessages());
    $this->assertSame([], $this->statusMessages());
    $this->assertTrue($state->isRebuilding(), 'The form is shown again with its text');
  }

  /**
   * Only the host's mail went: the thank-you names the host only.
   */
  public function testOnlyTheHostReceivedIt(): void {
    $this->stubMailResults(['*' => FALSE, self::HOST => TRUE]);
    [, $state] = $this->submitReport($this->topic, ['reason' => 'owner']);
    $this->assertSame([self::THANKS_HOST], $this->statusMessages());
    $this->assertSame([], $this->errorMessages());
    $this->assertFalse($state->isRebuilding());
    $state->setProgrammed(FALSE);
    $this->assertSame('localnodes_import.report_sent', $state->getRedirect()->getRouteName());
  }

  /**
   * The host's mail failed: the thank-you names the organizers only.
   */
  public function testHostSendFailedNamesOnlyTheOrganizers(): void {
    $this->stubMailResults(['*' => TRUE, self::HOST => FALSE]);
    [, $state] = $this->submitReport($this->topic, ['reason' => 'owner']);
    $this->assertSame([self::THANKS_ORGANIZERS], $this->statusMessages());
    $this->assertSame([], $this->errorMessages());
    $state->setProgrammed(FALSE);
    $this->assertSame('localnodes_import.report_sent', $state->getRedirect()->getRouteName());
  }

  /**
   * One organizer's mail failing still counts the organizers as told.
   */
  public function testOneOrganizerFailingStillThanksBoth(): void {
    $this->stubMailResults(['*' => TRUE, 'steward@mail.localnodes.xyz' => FALSE]);
    $this->submitReport($this->topic, ['reason' => 'owner']);
    $this->assertSame([self::THANKS_BOTH], $this->statusMessages());
  }

  /**
   * The host's address is also an organizer's: counted as both.
   */
  public function testAnAddressThatIsBothCountsForBoth(): void {
    $this->setSetting('localnodes_host_report_email', 'steward@mail.localnodes.xyz');
    $calls = $this->stubMailResults(['*' => FALSE, 'steward@mail.localnodes.xyz' => TRUE]);
    $this->submitReport($this->topic, ['reason' => 'owner']);
    $this->assertSame(1, array_count_values($calls->addresses)['steward@mail.localnodes.xyz'], 'Mailed once');
    $this->assertSame([self::THANKS_BOTH], $this->statusMessages());
  }

  /**
   * Failed sends still count against the flood limit.
   */
  public function testFailedSendsCountTowardsTheFloodLimit(): void {
    $this->stubMailResults(['*' => FALSE]);
    for ($i = 1; $i <= 5; $i++) {
      [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => "Report $i"]);
      $this->assertSame([], array_map('strval', $state->getErrors()), "Report $i is not refused");
    }
    [, $state] = $this->submitReport($this->topic, ['reason' => 'other', 'details' => 'Report 6']);
    $this->assertSame(['flood' => 'Too many reports from this connection. Wait a while, then try again.'], array_map('strval', $state->getErrors()));
  }

  /**
   * Submits a report that no one received, and checks the reply.
   */
  private function assertNotSent(array $values): void {
    $this->messenger()->deleteAll();
    [, $state] = $this->submitReport($this->topic, $values);
    $this->assertSame([], array_map('strval', $state->getErrors()), 'Not a validation error');
    $this->assertSame([self::NOT_SENT], $this->errorMessages());
    $this->assertSame([], $this->statusMessages(), 'No thank-you');
    $this->assertTrue($state->isRebuilding(), 'The form is shown again, not the thank-you page');
    $this->assertSame($values['details'], $state->getValue('details'), 'The text is kept');
  }

  /**
   * Replaces the mail manager with one that answers per address.
   *
   * @param array<string, bool|null> $results
   *   Result by address; '*' for any other. NULL is a send a hook cancelled.
   *
   * @return object
   *   Holds the addresses mailed, in order, in ->addresses.
   */
  private function stubMailResults(array $results): object {
    $calls = new \stdClass();
    $calls->addresses = [];
    $calls->sends = [];
    $mail = $this->createMock(MailManagerInterface::class);
    $mail->method('mail')->willReturnCallback(function ($module, $key, $to, $langcode, $params = []) use ($results, $calls) {
      $calls->addresses[] = $to;
      $calls->sends[] = ['to' => $to, 'key' => $key, 'params' => $params];
      $result = array_key_exists($to, $results) ? $results[$to] : $results['*'];
      return ['result' => $result, 'send' => $result !== NULL];
    });
    $this->container->set('plugin.manager.mail', $mail);
    return $calls;
  }

  /**
   * Status messages as strings, then cleared.
   */
  private function statusMessages(): array {
    return array_map('strval', $this->messenger()->deleteByType('status'));
  }

  /**
   * Error messages as strings, then cleared.
   */
  private function errorMessages(): array {
    return array_map('strval', $this->messenger()->deleteByType('error'));
  }

  /**
   * The messenger.
   */
  private function messenger(): \Drupal\Core\Messenger\MessengerInterface {
    return $this->container->get('messenger');
  }

  /**
   * Every mail sent since the last call.
   *
   * @return \Drupal\symfony_mailer\EmailInterface[]
   *   In the order they were sent.
   */
  private function sentMails(): array {
    return $this->container->get('symfony_mailer.test')->getEmails();
  }

  /**
   * A mail body as text: tags gone, entities decoded, spaces collapsed.
   */
  private function plain(string $html): string {
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('>', '> ', $html)), ENT_QUOTES)));
  }

  /**
   * Whether a route's access check lets the account in.
   */
  private function routeAccess(string $route, NodeInterface $node, $account): bool {
    return $this->container->get('access_manager')->checkNamedRoute($route, ['topic' => $node->id()], $account);
  }

  /**
   * Pushes a routed request for one of the two routes.
   */
  private function routedRequest(string $route, NodeInterface $node, string $method): void {
    $paths = [
      'localnodes_import.report_problem' => '/node/' . $node->id() . '/report-problem',
      'localnodes_import.takedown' => '/node/' . $node->id() . '/takedown',
    ];
    $request = Request::create($paths[$route], $method);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName($route));
    $request->attributes->set('topic', $node);
    $request->attributes->set('_raw_variables', new ParameterBag(['topic' => (string) $node->id()]));
    $this->container->get('request_stack')->push($request);
  }

  /**
   * The report form as first shown.
   */
  private function buildReport(NodeInterface $node): array {
    $this->routedRequest('localnodes_import.report_problem', $node, 'GET');
    $state = (new FormState())->addBuildInfo('args', [$node]);
    return $this->container->get('form_builder')->buildForm(self::REPORT_FORM, $state);
  }

  /**
   * The takedown confirmation as first shown.
   */
  private function buildTakedown(NodeInterface $node): array {
    $this->routedRequest('localnodes_import.takedown', $node, 'GET');
    $state = (new FormState())->addBuildInfo('args', [$node]);
    return $this->container->get('form_builder')->buildForm(self::TAKEDOWN_FORM, $state);
  }

  /**
   * A programmed submission of the report form.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   *   The built form and its state.
   */
  private function submitReport(NodeInterface $node, array $values): array {
    $this->routedRequest('localnodes_import.report_problem', $node, 'POST');
    return $this->submitProgrammed(self::REPORT_FORM, $node, $values);
  }

  /**
   * A programmed submission of the takedown confirmation.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   *   The built form and its state.
   */
  private function submitTakedown(NodeInterface $node): array {
    $this->routedRequest('localnodes_import.takedown', $node, 'POST');
    return $this->submitProgrammed(self::TAKEDOWN_FORM, $node, []);
  }

  /**
   * Builds, validates and submits a form the way a POST would.
   *
   * @return array{0: array, 1: \Drupal\Core\Form\FormStateInterface}
   *   The built form and its state.
   */
  private function submitProgrammed(string $class, NodeInterface $node, array $values): array {
    $builder = $this->container->get('form_builder');
    $state = (new FormState())->setValues($values);
    $state->addBuildInfo('args', [$node]);
    $state->setUserInput($values);
    $state->setProgrammed();
    $state->setRequestMethod('POST');
    $form_id = $builder->getFormId($class, $state);
    $form = $builder->retrieveForm($form_id, $state);
    $state->setSubmitted();
    $state->setValidationEnforced();
    $state->clearErrors();
    $builder->prepareForm($form_id, $form, $state);
    $builder->processForm($form_id, $form, $state);
    return [$form, $state];
  }

}
