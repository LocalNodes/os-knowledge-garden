<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\comment\Entity\Comment;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\social_post\Entity\Post;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authored house conversations remain readable, with every writing door shut.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class DepartedAuthorInteractionTest extends GroupAccessKernelTestBase {

  protected static $modules = ['block', 'dropdown', 'profile', 'social_post', 'ajax_comments', 'votingapi', 'like_and_dislike', 'localnodes_platform'];

  protected function installConfig($modules) {
    // The real module installer has settings before role dependency validation.
    // This shared kernel base imports user roles first, so supply the shipped
    // empty enabled-type list before the permission callback is first asked.
    if (in_array('user', (array) $modules, TRUE)) {
      $this->config('like_and_dislike.settings')->set('enabled_types', [])->save();
    }
    parent::installConfig($modules);
  }

  protected function setUp(): void {
    parent::setUp();
    // Kernel module lists skip install hooks. Both stock formatters rely on
    // the statistics enabled by the actual core Comment install hook.
    $this->container->get('module_handler')->loadInclude('comment', 'install');
    comment_install();
    $this->installEntitySchema('post');
    $this->installEntitySchema('profile');
    $this->installEntitySchema('vote');
    $this->installEntitySchema('vote_result');
    $this->installConfig(['votingapi', 'like_and_dislike']);
    $this->config('like_and_dislike.settings')->set('enabled_types', ['node' => ['topic'], 'post' => ['post'], 'comment' => ['comment', 'post_comment']])->save();
    $this->installShippedConfig('social_group_flexible_group', ['field.storage.group.field_group_allowed_join_method', 'field.field.group.flexible_group.field_group_allowed_join_method']);
    $this->installShippedConfig('social_group', ['views.view.group_information']);
    $this->installShippedConfig('social_post', [
      'social_post.post_type.post', 'core.entity_form_mode.post.group', 'comment.type.post_comment',
      'field.storage.post.field_visibility', 'field.field.post.post.field_visibility',
      'field.storage.post.field_recipient_group', 'field.field.post.post.field_recipient_group',
      'field.storage.post.field_post', 'field.field.post.post.field_post',
      'field.storage.post.field_post_comments', 'field.field.post.post.field_post_comments',
      'field.field.comment.post_comment.field_comment_body',
    ]);
    user_role_grant_permissions('authenticated', ['view public posts', 'view published post entities']);
    user_role_grant_permissions('verified', [
      'view community posts', 'edit own comments', 'delete own comments',
      'add or remove like votes on topic of node', 'add or remove like votes on post of post',
      'add or remove like votes on comment of comment', 'add or remove like votes on post_comment of comment', 'view like widget',
    ]);
    foreach (['comment', 'post_comment'] as $bundle) {
      EntityViewDisplay::create(['targetEntityType' => 'comment', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE])
        ->setComponent('field_comment_body', ['type' => 'text_default', 'label' => 'hidden'])
        ->setComponent('links', ['weight' => 10])->setComponent('like_and_dislike', ['weight' => 11])->save();
    }
    // Ajax Comments resolves each reply's formatter from a saved display of
    // its parent, including when only the comment field is rendered in a test.
    // Use the shipped component with AJAX enabled by its normal defaults.
    foreach ([['node', 'topic', 'social_topic', 'field_topic_comments'], ['post', 'post', 'social_post', 'field_post_comments']] as [$type, $bundle, $module, $field]) {
      $path = $this->container->get('extension.list.module')->getPath($module);
      $source = new \Drupal\Core\Config\FileStorage($path . '/config/install');
      $definition = $source->read("core.entity_view_display.$type.$bundle.default");
      $display = EntityViewDisplay::load("$type.$bundle.default") ?? EntityViewDisplay::create([
        'targetEntityType' => $type, 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE,
      ]);
      $display->setComponent($field, $definition['content'][$field])->save();
    }
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('router.builder')->rebuild();
  }

  public static function roots(): array {
    return ['topic closed' => ['node', 'community'], 'topic secret' => ['node', 'members'], 'post closed' => ['post', 'community'], 'post secret' => ['post', 'members']];
  }

  /** @return array{\Drupal\group\Entity\GroupInterface, AccountInterface, AccountInterface, EntityInterface} */
  private function conversation(string $type, string $visibility): array {
    $group = $this->createFlexibleGroup('House', $visibility);
    $group->set('field_group_allowed_join_method', ['added'])->save();
    $author = $this->createPersona('former_author');
    $member = $this->createPersona('current_member');
    $this->addMember($group, $author);
    $this->addMember($group, $member);
    if ($type === 'node') {
      $root = $this->createGroupTopic($group, 'Authored conversation', 'Author words', 'group');
      $root->setOwnerId((int) $author->id())->save();
    }
    else {
      $root = Post::create(['type' => 'post', 'user_id' => $author->id(), 'status' => 1, 'field_visibility' => '3', 'field_recipient_group' => $group->id(), 'field_post' => ['value' => 'Author words', 'format' => 'plain_text']]);
      $root->save();
    }
    // Model the stored entities used by the next request after departure.
    $author = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($author->id());
    $member = $this->container->get('entity_type.manager')->getStorage('user')->loadUnchanged($member->id());
    $root = $this->container->get('entity_type.manager')->getStorage($type)->loadUnchanged($root->id());
    $this->assertSame((string) $author->id(), (string) $root->getOwnerId());
    return [$group, $author, $member, $root];
  }

  private function reply(EntityInterface $root, AccountInterface $author, string $words): Comment {
    $comment = Comment::create([
      'entity_type' => $root->getEntityTypeId(), 'entity_id' => $root->id(),
      'field_name' => $root->getEntityTypeId() === 'post' ? 'field_post_comments' : 'field_topic_comments',
      'comment_type' => $root->getEntityTypeId() === 'post' ? 'post_comment' : 'comment',
      'field_comment_body' => ['value' => $words, 'format' => 'plain_text'],
      'uid' => $author->id(), 'status' => 1,
    ]);
    $comment->save();
    return $comment;
  }

  private function depart($group, AccountInterface $author): void {
    $group->removeMember($author);
    // The real departure hook records this on a site. Calling the same service
    // also makes this fixture independent of hook ordering in a minimal kernel.
    $this->container->get('localnodes_platform.group_notice_cleanup')->remove((int) $author->id(), (int) $group->id());
    foreach (['node', 'post', 'comment'] as $type) $this->container->get('entity_type.manager')->getAccessControlHandler($type)->resetCache();
    $this->container->get('current_user')->setAccount($author);
  }

  /** @dataProvider roots */
  public function testAllPublishedRepliesRemainReadableAndRenderedWithoutWriteControls(string $type, string $visibility): void {
    [$group, $author, $member, $root] = $this->conversation($type, $visibility);
    $old = $this->reply($root, $member, 'Before leaving reply');
    $own = $this->reply($root, $author, 'Former author reply');
    $this->depart($group, $author);
    $later = $this->reply($root, $member, 'After leaving reply');
    if ($type === 'node') $later->set('pid', $old->id())->save();
    $this->assertTrue($root->access('view', $author));
    foreach ([$old, $own, $later] as $comment) $this->assertTrue($comment->access('view', $author));
    $this->assertFalse($own->access('update', $author));
    $this->assertFalse($own->access('delete', $author));
    $hidden = $this->reply($root, $member, 'Unpublished moderation text');
    $hidden->setUnpublished()->save();
    $this->assertFalse($hidden->access('view', $author));
    $root = $this->container->get('entity_type.manager')->getStorage($type)->loadUnchanged($root->id());
    $field = $type === 'post' ? 'field_post_comments' : 'field_topic_comments';
    $this->assertSame(3, (int) $root->get($field)->comment_count, 'Core statistics include the three published replies.');
    $parent_display = $this->container->get('ajax_comments.field_settings_helper')->getEntityViewDisplay($old);
    $this->assertNotNull($parent_display, 'The real link builder finds the saved parent display.');
    $formatter = $this->container->get('plugin.manager.field.formatter')->getInstance([
      'field_definition' => $root->getFieldDefinition($field), 'view_mode' => 'default',
      'configuration' => ['type' => $type === 'post' ? 'comment_post' : 'comment_group_content', 'label' => 'hidden', 'settings' => ['num_comments' => 0, 'order' => 'ASC', 'view_mode' => 'default', 'pager_id' => 0]],
    ]);
    $build = $formatter->view($root->get($field), 'en');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    foreach (['Before leaving reply', 'Former author reply', 'After leaving reply'] as $words) $this->assertStringContainsString($words, $html);
    foreach (['Unpublished moderation text', 'comment-form', 'comment-reply', 'mention-reply', '/comment/reply/', 'vote-widget', 'Please log in'] as $words) $this->assertStringNotContainsString($words, $html);
    $this->assertTrue($later->access('view', $member));
    $this->container->get('current_user')->setAccount($member);
    $member_build = $this->container->get('entity_type.manager')->getViewBuilder('comment')->view($old);
    $member_html = (string) $this->container->get('renderer')->renderInIsolation($member_build);
    $this->assertStringContainsString('vote-widget', $member_html, 'The normal member reaction widget is actually configured and rendered.');
  }

  /** @dataProvider roots */
  public function testCoreAndAjaxReplyRoutesDenyFormerAuthors(string $type, string $visibility): void {
    [$group, $author, $member, $root] = $this->conversation($type, $visibility);
    $comment = $this->reply($root, $member, 'Existing reply');
    $this->depart($group, $author);
    $params = ['entity_type' => $type, 'entity' => $root->id(), 'field_name' => $comment->getFieldName(), 'pid' => $comment->id()];
    foreach (['comment.reply', 'ajax_comments.add', 'ajax_comments.reply', 'ajax_comments.save_reply'] as $route) {
      $this->assertFalse($this->container->get('access_manager')->checkNamedRoute($route, $params, $author, TRUE)->isAllowed(), $route . ' denies direct requests.');
      $this->container->get('current_user')->setAccount($member);
      $this->assertTrue($this->container->get('access_manager')->checkNamedRoute($route, $params, $member, TRUE)->isAllowed(), $route . ' keeps current-member access.');
      $this->container->get('current_user')->setAccount($author);
    }
  }

  /** @dataProvider roots */
  public function testAnEmbeddedFormBuiltBeforeDepartureCannotSave(string $type, string $visibility): void {
    [$group, $author, , $root] = $this->conversation($type, $visibility);
    $draft = Comment::create(['entity_type' => $type, 'entity_id' => $root->id(), 'field_name' => $type === 'post' ? 'field_post_comments' : 'field_topic_comments', 'comment_type' => $type === 'post' ? 'post_comment' : 'comment', 'field_comment_body' => ['value' => 'Must not be saved', 'format' => 'plain_text'], 'uid' => $author->id(), 'status' => 1]);
    // AjaxCommentsForm's real save path also reads the saved parent display.
    // The ordinary web formatter prepares this before embedding its form.
    $this->assertNotNull(EntityViewDisplay::load($type . '.' . $root->bundle() . '.default'), 'Shared setup supplies the saved parent display.');
    $form = $this->container->get('entity_type.manager')->getFormObject('comment', 'default')->setEntity($draft);
    // AjaxCommentsForm::save() reads the callback object from FormState,
    // which FormBuilder normally supplies before validation/submission.
    $form_state = (new FormState())->setFormObject($form);
    $before = $this->container->get('entity_type.manager')->getStorage('comment')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->depart($group, $author);
    try {
      $form->save([], $form_state);
    }
    catch (AccessDeniedHttpException) {
      // A direct/stale submission is a normal forbidden request.
    }
    $after = $this->container->get('entity_type.manager')->getStorage('comment')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->assertSame((int) $before, (int) $after, 'The real form save path cannot insert a reply after departure.');
    $this->assertTrue($draft->isNew());
    $this->assertFalse($this->container->get('entity_type.manager')->getAccessControlHandler('comment')->createAccess($draft->bundle(), $author, ['entity' => $root]));
  }

  /** @dataProvider roots */
  public function testReactionEndpointRequiresMembershipEvenWhenTheAuthorCanRead(string $type, string $visibility): void {
    [$group, $author, $member, $root] = $this->conversation($type, $visibility);
    $comment = $this->reply($root, $member, 'Reaction target reply');
    $this->depart($group, $author);
    foreach ([$root, $comment] as $target) {
      $params = ['entity_type_id' => $target->getEntityTypeId(), 'vote_type_id' => 'like', 'entity_id' => $target->id()];
      $this->assertFalse($this->container->get('access_manager')->checkNamedRoute('like_and_dislike.vote', $params, $author, TRUE)->isAllowed());
      $this->container->get('current_user')->setAccount($member);
      $this->assertTrue($this->container->get('access_manager')->checkNamedRoute('like_and_dislike.vote', $params, $member, TRUE)->isAllowed());
      $this->container->get('current_user')->setAccount($author);
    }
    $this->assertSame(0, (int) $this->container->get('entity_type.manager')->getStorage('vote')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

  public static function newPostHouseVisibility(): array {
    return ['discoverable house' => ['community'], 'secret house' => ['members']];
  }

  /** @dataProvider newPostHouseVisibility */
  public function testANewPostFormCannotWriteAfterItsAuthorLeaves(string $visibility): void {
    [$group, $author, $member] = $this->conversation('post', $visibility);
    user_role_grant_permissions('verified', ['add post entities', 'add post post entities']);
    $display = \Drupal\Core\Entity\Entity\EntityFormDisplay::create([
      'targetEntityType' => 'post', 'bundle' => 'post', 'mode' => 'group', 'status' => TRUE,
    ]);
    $display->save();
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.group.canonical');
    $request->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME, 'entity.group.canonical');
    $request->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('group', $group);
    $this->container->get('current_route_match')->resetRouteMatch();
    $draft = Post::create([
      'type' => 'post', 'user_id' => $author->id(), 'status' => 1,
      'field_visibility' => '3',
      // The installed PostForm assigns the converted group late in save().
      'field_post' => ['value' => 'Must not be posted after leaving', 'format' => 'plain_text'],
    ]);
    $form = $this->container->get('entity_type.manager')->getFormObject('post', 'default')->setEntity($draft);
    $state = (new FormState())->setFormObject($form)->set('form_display', $display);
    $before = (int) $this->container->get('entity_type.manager')->getStorage('post')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->depart($group, $author);
    try {
      $form->save([], $state);
    }
    catch (AccessDeniedHttpException) {
      // A stale form is forbidden before the actual parent save can insert.
    }
    $after = (int) $this->container->get('entity_type.manager')->getStorage('post')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->assertSame($before, $after);
    $this->assertTrue($draft->isNew());
    $this->assertTrue($draft->get('field_recipient_group')->isEmpty(), 'Resolving policy context does not mutate a denied draft.');

    // A current member with the same initially empty recipient still reaches
    // the actual upstream save, which assigns the intended group and inserts.
    $this->container->get('current_user')->setAccount($member);
    $allowed = Post::create([
      'type' => 'post', 'user_id' => $member->id(), 'status' => 1,
      'field_visibility' => '3',
      'field_post' => ['value' => 'A current member may post', 'format' => 'plain_text'],
    ]);
    $member_form = $this->container->get('entity_type.manager')->getFormObject('post', 'default')->setEntity($allowed);
    $member_state = (new FormState())->setFormObject($member_form)->set('form_display', $display);
    $member_form->save([], $member_state);
    $this->assertFalse($allowed->isNew());
    $this->assertSame((int) $group->id(), (int) $allowed->get('field_recipient_group')->target_id);
    $this->assertSame($before + 1, (int) $this->container->get('entity_type.manager')->getStorage('post')->getQuery()->accessCheck(FALSE)->count()->execute());
  }

}
