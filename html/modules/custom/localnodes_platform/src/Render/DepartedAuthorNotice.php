<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Render;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/** Explains retained reading at the page and its former comment box. */
final class DepartedAuthorNotice {

  use StringTranslationTrait;

  public function __construct(
    private readonly DepartedAuthorAccess $policy,
    private readonly GroupNoticeCleanup $departures,
    private readonly DateFormatterInterface $dates,
    private readonly AccountInterface $actor,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  private function rootOwner(EntityInterface $entity): ?UserInterface {
    $root = $this->policy->root($entity);
    if (!in_array($root->getEntityTypeId(), ['node', 'post'], TRUE) || !$root instanceof EntityOwnerInterface) return NULL;
    $owner = $root->getOwner();
    return $owner instanceof UserInterface ? $owner : NULL;
  }

  private function note(): array {
    // Use the installed theme's information treatment and native typography.
    return ['#type' => 'container', '#attributes' => ['class' => ['alert', 'alert-info'], 'role' => 'note']];
  }

  private function metadata(array &$build, EntityInterface $root, AccountInterface $viewer, int $owner, int $gid): void {
    $this->policy->accessMetadata($root, $viewer)
      ->addCacheTags(['localnodes_departure:' . $owner . ':' . $gid])
      ->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
  }

  public function memberNotice(EntityInterface $entity, AccountInterface $viewer): array {
    $root = $this->policy->root($entity);
    $owner = $this->rootOwner($root);
    if (!$owner || !$owner->isActive() || !$this->policy->canRetainView($root, $owner)) return [];
    foreach ($this->policy->groups($root) as $group) {
      $uid = (int) $owner->id();
      $gid = (int) $group->id();
      $date = $this->departures->departureTime($uid, $gid);
      if ($date === NULL || $group->getMember($owner) || !$group->getMember($viewer)
        || $this->departures->retainedReadingClosed($uid, $gid)) continue;
      $build = $this->note();
      $build['text'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('@name left this house on @date and can still read this page and its replies.', ['@name' => $owner->getDisplayName(), '@date' => $this->dates->format($date, 'custom', 'F j, Y')])];
      $build['hint'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('To keep the next part of this conversation with current house members, start a new house-only page.')];
      // House organizers can still close this reading for safety, including
      // after a removal made where no safety choice was offered.
      if ($group->hasPermission('administer members', $viewer)) {
        $build['close'] = [
          '#type' => 'link',
          '#title' => $this->t('Close @name’s reading for safety', ['@name' => $owner->getDisplayName()]),
          '#url' => Url::fromRoute('localnodes_platform.close_retained_reading', ['group' => $gid, 'user' => $uid]),
          '#prefix' => '<p>', '#suffix' => '</p>',
        ];
      }
      CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($owner)->applyTo($build);
      $this->metadata($build, $root, $viewer, $uid, $gid);
      return $build;
    }
    return [];
  }

  public function readingPanel(EntityInterface $entity, AccountInterface $viewer): array {
    $root = $this->policy->root($entity);
    $owner = $this->rootOwner($root);
    if (!$owner || (int) $owner->id() !== (int) $viewer->id() || !$this->policy->canRetainView($root, $viewer)) return [];
    foreach ($this->policy->groups($root) as $group) {
      $uid = (int) $owner->id();
      $gid = (int) $group->id();
      $date = $this->departures->departureTime($uid, $gid);
      if ($date === NULL || $group->getMember($viewer) || $this->departures->retainedReadingClosed($uid, $gid)) continue;
      $args = ['@house' => $group->label(), '@date' => $this->dates->format($date, 'custom', 'F j, Y')];
      $text = $this->departures->wasRemoved($uid, $gid)
        ? $this->t('You were removed from @house on @date. You can read this page and all its replies, including new ones. You can no longer edit, delete, comment or react in this house, or receive its notices.', $args)
        : $this->t('You left @house on @date. You can read this page and all its replies, including new ones. You can no longer edit, delete, comment or react in this house, or receive its notices.', $args);
      $build = $this->note();
      $build['text'] = ['#type' => 'html_tag', '#tag' => 'p', '#value' => $text];
      $this->metadata($build, $root, $viewer, $uid, $gid);
      return $build;
    }
    return [];
  }

  public function attachToView(array &$build, EntityInterface $entity, AccountInterface $viewer): void {
    // Keep these notices on the conversation page, outside stream teasers.
    if (!in_array($build['#view_mode'] ?? '', ['full', 'default'], TRUE)
      || !in_array($entity->getEntityTypeId(), ['node', 'post'], TRUE)
      || !$this->policy->isPrivate($entity)) return;
    $banner = $this->memberNotice($entity, $viewer);
    if ($banner) $build['localnodes_departed_author'] = $banner + ['#weight' => -20];
    $panel = $this->readingPanel($entity, $viewer);
    if ($panel && $entity instanceof FieldableEntityInterface) {
      foreach ($entity->getFieldDefinitions() as $name => $definition) {
        if ($definition->getType() === 'comment' && isset($build[$name][0])) $build[$name][0]['comment_form'] = $panel;
      }
    }
    $this->policy->accessMetadata($entity, $viewer)->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
  }

  /** A system.403 controller: retain core's response for every other denial. */
  public function on403(Request $request): array {
    // Core clones the original request for its 403 subrequest and overwrites
    // routing attributes. Converted entity objects remain; query IDs do not
    // qualify. Never ask for a private page label or render its body here.
    foreach (['node', 'post', 'comment'] as $parameter) {
      $entity = $request->attributes->get($parameter);
      if (!$entity instanceof EntityInterface || $entity->getEntityTypeId() !== $parameter) continue;
      $root = $this->policy->root($entity);
      $owner = $this->rootOwner($root);
      if (!$owner || (int) $owner->id() !== (int) $this->actor->id()
        || !$this->policy->isPrivate($root) || $this->policy->isCurrentMember($root, $this->actor)
        || $this->policy->canRetainView($root, $this->actor)) continue;
      foreach ($this->policy->groups($root) as $group) {
        $uid = (int) $this->actor->id();
        $gid = (int) $group->id();
        $date = $this->departures->departureTime($uid, $gid);
        if ($date === NULL || !$this->departures->wasRemoved($uid, $gid) || !$this->departures->retainedReadingClosed($uid, $gid)) continue;
        $build = [
          'text' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $this->t('You were removed from @house on @date. Your continued access to house-only pages you wrote and their replies was closed for safety.', ['@house' => $group->label(), '@date' => $this->dates->format($date, 'custom', 'F j, Y')])],
          'back' => ['#type' => 'link', '#title' => $this->t('Back to the community'), '#url' => Url::fromRoute('<front>')],
        ];
        $this->metadata($build, $root, $this->actor, $uid, $gid);
        return $build;
      }
    }
    return ['#markup' => $this->t('You are not authorized to access this page.')];
  }

}
