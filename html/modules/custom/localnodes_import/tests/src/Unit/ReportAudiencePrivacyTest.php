<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Unit;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Site\Settings;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRoleInterface;
use Drupal\group\GroupMembership;
use Drupal\localnodes_import\Form\ReportProblemForm;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\NullLogger;

/** @group localnodes_import */
final class ReportAudiencePrivacyTest extends UnitTestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    new Settings([]);
    parent::tearDown();
  }

  private function account(int $uid, string $mail, array $roles = ['sitemanager']): UserInterface {
    $account = $this->createMock(UserInterface::class);
    $account->method('id')->willReturn((string) $uid);
    $account->method('getEmail')->willReturn($mail);
    $account->method('getRoles')->willReturn($roles);
    $account->method('isActive')->willReturn(TRUE);
    $account->method('isAnonymous')->willReturn(FALSE);
    return $account;
  }

  private function audience(array $siteManagers = [], array $groups = []): ReportAudience {
    $query = $this->createMock(QueryInterface::class);
    foreach (['accessCheck', 'condition', 'sort'] as $method) {
      $query->method($method)->willReturnSelf();
    }
    $query->method('execute')->willReturn(array_keys($siteManagers));
    $users = $this->createMock(EntityStorageInterface::class);
    $users->method('getQuery')->willReturn($query);
    $users->method('loadMultiple')->willReturn($siteManagers);
    $relationships = [];
    foreach ($groups as $group) {
      $relationships[] = new class($group) {
        public function __construct(private GroupInterface $group) {}
        public function getGroup(): GroupInterface { return $this->group; }
      };
    }
    $groupContent = $this->createMock(EntityStorageInterface::class);
    $groupContent->method('loadByProperties')->willReturn($relationships);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->willReturnMap([['user', $users], ['group_content', $groupContent]]);
    return new ReportAudience($entities);
  }

  public function testSiteRoleCannotStewardAPageItCannotView(): void {
    $manager = $this->account(7, 'outside@example.test');
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('access')->with('view', $manager)->willReturn(FALSE);
    $this->assertFalse($this->audience()->isSteward($node, $manager));
  }

  public function testSiteManagerStillStewardsAnAccessiblePage(): void {
    $manager = $this->account(7, 'site@example.test');
    $node = $this->createMock(NodeInterface::class);
    $node->expects($this->once())->method('access')->with('view', $manager)->willReturn(TRUE);
    $this->assertTrue($this->audience()->isSteward($node, $manager));
  }

  /** @dataProvider explicitGroupAudiences */
  public function testCurrentGroupPrivacyOverridesCachedAnonymousAccess(string $bundle, ?string $visibility, bool $expected): void {
    $manager = $this->account(7, 'site@example.test');
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('3');
    $group->method('bundle')->willReturn($bundle);
    $group->method('hasField')->willReturn($visibility !== NULL);
    $group->method('get')->willReturn((object) ['value' => $visibility]);
    // A group just changed from public can retain its earlier access result
    // within this request. Its current privacy setting must still take effect.
    $group->method('access')->willReturn(TRUE);
    $node = $this->createMock(NodeInterface::class);
    $node->method('access')->willReturn(TRUE);
    $this->assertSame($expected, $this->audience([], [$group])->isSteward($node, $manager));
  }

  public static function explicitGroupAudiences(): array {
    return [
      'public flexible house' => ['flexible_group', 'public', TRUE],
      'community flexible house' => ['flexible_group', 'community', FALSE],
      'members flexible house' => ['flexible_group', 'members', FALSE],
      'legacy closed house' => ['closed_group', NULL, FALSE],
      'legacy secret house' => ['secret_group', NULL, FALSE],
    ];
  }

  public function testReportRecipientsExcludeInaccessibleSiteManagers(): void {
    $this->assertSame([
      'inside@example.test' => ['organizers'],
      'house@example.test' => ['organizers'],
      'host@example.test' => ['host'],
    ], $this->reportAddresses(FALSE, FALSE));
  }

  /** @dataProvider privateAudiences */
  public function testPrivateReportExcludesPlainSiteManagerMembers(bool $privateGroup, bool $privateNode): void {
    $this->assertSame([
      'house@example.test' => ['organizers'],
      'host@example.test' => ['host'],
    ], $this->reportAddresses($privateGroup, $privateNode));
  }

  public static function privateAudiences(): array {
    return ['private house' => [TRUE, FALSE], 'private page in public house' => [FALSE, TRUE]];
  }

  private function reportAddresses(bool $privateGroup, bool $privateNode): array {
    $outside = $this->account(7, 'outside@example.test');
    $inside = $this->account(8, 'inside@example.test');
    $house = $this->account(9, 'house@example.test', ['verified']);
    $membership = $this->createMock(GroupMembership::class);
    $membership->method('getUser')->willReturn($house);
    $membership->method('getRoles')->willReturn(['flexible_group-group_manager' => $this->createMock(GroupRoleInterface::class)]);
    $ordinaryMembership = $this->createMock(GroupMembership::class);
    $ordinaryMembership->method('getRoles')->willReturn([]);
    $group = $this->createMock(GroupInterface::class);
    $group->method('id')->willReturn('3');
    $group->method('bundle')->willReturn('flexible_group');
    $group->method('getMembers')->willReturn([$membership]);
    $group->method('getMember')->willReturnCallback(fn ($account) => (int) $account->id() === 9 ? $membership : $ordinaryMembership);
    $group->method('access')->willReturn(!$privateGroup);
    $audience = $this->audience([7 => $outside, 8 => $inside], [$group]);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn('12');
    $node->method('hasField')->willReturn($privateNode);
    $node->method('get')->willReturn((object) ['value' => 'group']);
    $node->method('access')->willReturnCallback(fn ($operation, $account) => in_array((int) $account->id(), [8, 9], TRUE));
    new Settings(['localnodes_host_report_email' => 'host@example.test']);
    $container = new ContainerBuilder();
    $validator = $this->createMock(EmailValidatorInterface::class);
    $validator->method('isValid')->willReturn(TRUE);
    $container->set('email.validator', $validator);
    \Drupal::setContainer($container);
    $form = new class($audience) extends ReportProblemForm {
      public function __construct(ReportAudience $audience) {
        $this->audience = $audience;
        $this->logger = new NullLogger();
      }
      public function addresses(NodeInterface $node): array { return $this->recipients($node); }
    };
    return $form->addresses($node);
  }

}
