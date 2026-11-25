<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\comment\CommentInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;
use Drupal\views\Views;

/**
 * Site-wide roles do not open comments in members-only or secret groups.
 *
 * Site-wide roles are narrowed so they
 * cannot read private groups. A site manager or content manager who is not a
 * member of a members-only or secret group may not view a comment on a topic
 * there, cannot open the comment admin listing, and the listing shows every
 * account that can open it only comments that account may read. The
 * administrator role (all permissions) is outside this rule and is checked
 * only against its own plain access.
 *
 * How the listing is loaded: the shipped comment admin listing
 * (social_comment's views.view.comment, display page_published, results
 * cache type "tag") is loaded by two accounts with the same roles, a member
 * of both groups first and then a non-member, with per-request statics reset
 * between the loads and the persistent cache kept. Every row the listing
 * gives an account must be a comment that account may view.
 *
 * Role grants follow main's fresh install: social_comment_install()'s grants,
 * then localnodes_platform's site-role boundary
 * (_localnodes_platform_protect_private_groups()), run from main's own
 * localnodes_platform.install.
 *
 * @group localnodes_comment_listing
 */
final class CommentAdminListingAccessTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['profile', 'select2'];

  /**
   * Roles that hold the listing's permission after the install steps.
   *
   * @var string[]
   */
  private array $listingRoles = [];

  /**
   * Comments keyed by fixture name.
   *
   * @var \Drupal\comment\CommentInterface[]
   */
  private array $comments = [];

  /**
   * The groups keyed like the comments.
   *
   * @var \Drupal\group\Entity\GroupInterface[]
   */
  private array $groups = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');
    $this->installShippedConfig('social_profile', [
      'field.storage.profile.field_profile_first_name',
      'field.storage.profile.field_profile_last_name',
    ]);
    // Core's own comment view is installed with the comment module; Open
    // Social ships its replacement under the same ID.
    View::load('comment')?->delete();
    $this->installShippedConfig('social_comment', ['views.view.comment']);
    $this->assertSame('social_comment', View::load('comment')->get('module'));

    // social_comment_install() grants, as on a fresh Open Social install.
    foreach (['contentmanager', 'sitemanager'] as $role) {
      user_role_grant_permissions($role, [
        'access comments',
        'post comments',
        'skip comment approval',
        'edit own comments',
        'delete own comments',
        'administer own comments',
        'administer comments',
      ]);
    }
    // Main's site-role boundary, as localnodes_platform_install() runs it.
    require_once DRUPAL_ROOT . '/modules/custom/localnodes_platform/localnodes_platform.install';
    _localnodes_platform_protect_private_groups();

    foreach (['verified', 'contentmanager', 'sitemanager', 'administrator'] as $id) {
      $role = Role::load($id);
      if ($role->isAdmin() || $role->hasPermission('administer comments')) {
        $this->listingRoles[] = $id;
      }
    }

    $community = $this->createFlexibleGroup('Fictional members-only group', 'community');
    $secret = $this->createSecretGroup('Fictional secret group');
    $this->comments['members-only'] = $this->createComment(
      $this->createGroupTopic($community, 'Fictional members topic', 'A fictional members topic.', 'group'),
      'A fictional members-only reply.'
    );
    $this->comments['secret'] = $this->createComment(
      $this->createGroupTopic($secret, 'Fictional secret topic', 'A fictional secret topic.', 'group'),
      'A fictional secret reply.'
    );
    $this->groups = ['members-only' => $community, 'secret' => $secret];
  }

  /**
   * Site-wide roles, then the listing: member first with the cache on.
   */
  public function testListingShowsOnlyReadableComments(): void {
    // The narrowed site-wide roles, as non-members: no comment, no listing.
    foreach (['sitemanager', 'contentmanager'] as $role) {
      $outsider = $this->createUser([], "site_$role", FALSE, ['roles' => ['verified', $role]]);
      $this->assertFalse(Views::getView('comment')->access('page_published', $outsider), "Role $role cannot open the comment listing.");
      foreach ($this->comments as $name => $comment) {
        $this->assertFalse($comment->access('view', $outsider), "Role $role, not a member, may not view the $name comment.");
        $this->assertFalse($comment->getCommentedEntity()->access('view', $outsider), "Role $role, not a member, may not view the $name topic.");
      }
    }

    $this->assertNotSame([], $this->listingRoles, 'Some shipped role can open the comment listing.');
    foreach ($this->listingRoles as $role) {
      $this->assertListingShowsOnlyReadable($role);
    }
  }

  /**
   * Member first with the results cache on, then a non-member, same roles.
   */
  private function assertListingShowsOnlyReadable(string $role): void {
    $roles = ['verified', $role];

    $member = $this->createUser([], "member_$role", FALSE, ['roles' => $roles]);
    $outsider = $this->createUser([], "outsider_$role", FALSE, ['roles' => $roles]);
    foreach ($this->groups as $group) {
      $this->addMember($group, $member);
    }
    \Drupal::cache('data')->deleteAll();

    // Member positive control: the listing shows the member both comments.
    [$member_rows, $member_key] = $this->listing($member);
    foreach ($this->comments as $name => $comment) {
      $this->assertTrue($comment->access('view', $member), "The member may view the $name comment.");
      $this->assertContains((int) $comment->id(), $member_rows, "The listing shows the member the $name comment.");
    }
    $this->assertNotFalse(\Drupal::cache('data')->get($member_key), 'The member load stored a results cache entry.');

    $this->resetRequestStatics();

    [$outsider_rows, $outsider_key] = $this->listing($outsider);
    $shared = $member_key === $outsider_key ? 'shared with the member' : 'its own';
    foreach ($this->comments as $name => $comment) {
      $message = sprintf('Role %s: the listing shows a non-member only comments they may read (%s comment; results key %s).', $role, $name, $shared);
      if ($comment->access('view', $outsider)) {
        $this->assertContains((int) $comment->id(), $outsider_rows, $message);
      }
      else {
        $this->assertNotContains((int) $comment->id(), $outsider_rows, $message);
      }
    }
  }

  /**
   * Loads the page_published display as the account.
   *
   * @return array
   *   [comment IDs in the result, the results cache key].
   */
  private function listing(AccountInterface $account): array {
    $this->setCurrentUser($account);
    $view = Views::getView('comment');
    $this->assertNotNull($view);
    $this->assertTrue($view->access('page_published', $account), 'The account can open the comment listing.');
    $view->setDisplay('page_published');
    // An unfiltered page load: every exposed filter at its "any" value.
    $view->setExposedInput(['author_name' => '', 'langcode' => 'All', 'title' => '', 'content_type' => 'All', 'group' => 'All']);
    $view->initHandlers();
    foreach (['node', 'group_content', 'gid'] as $id) {
      $handler = $view->relationship[$id] ?? NULL;
      $this->assertNotNull($handler, "Relationship $id is present.");
      $this->assertFalse($handler->broken(), "Relationship $id is not broken.");
    }
    $this->assertSame('tag', $view->display_handler->getOption('cache')['type']);
    $view->preExecute();
    $view->execute();
    $rows = [];
    foreach ($view->result as $row) {
      $rows[] = (int) $row->_entity->id();
    }
    $key = $view->display_handler->getPlugin('cache')->getResultsKey();
    $view->destroy();
    return [$rows, $key];
  }

  /**
   * Clears per-request statics without touching the persistent cache bins.
   */
  private function resetRequestStatics(): void {
    drupal_static_reset();
    $reflection = new \ReflectionProperty('Drupal\social_comment\Entity\Access\CommentQueryAccessHandler', 'accessibleNodeIdsCache');
    $reflection->setValue(NULL, []);
    foreach (['node', 'comment', 'group', 'group_content', 'user'] as $type) {
      \Drupal::entityTypeManager()->getStorage($type)->resetCache();
    }
  }

}
