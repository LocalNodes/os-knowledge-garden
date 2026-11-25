<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets a house organizer close a former member's retained reading afterwards.
 *
 * Removal can happen on screens that offer no safety choice (the bulk
 * "Remove members" action) or before the organizer knew it was needed. This
 * confirmation closes that person's reading of the house-only pages they
 * wrote here, and the replies under them. Rejoining restores normal access.
 */
final class CloseRetainedReadingForm extends ConfirmFormBase {

  private ?GroupInterface $group = NULL;
  private ?UserInterface $target = NULL;

  public function __construct(private readonly GroupNoticeCleanup $departures) {}

  public static function create(ContainerInterface $container): self {
    $form = new self($container->get('localnodes_platform.group_notice_cleanup'));
    $form->setStringTranslation($container->get('string_translation'));
    return $form;
  }

  public function getFormId(): string {
    return 'localnodes_close_retained_reading';
  }

  /**
   * Route access: a current house organizer, about a former member.
   */
  public static function access(GroupInterface $group, UserInterface $user, AccountInterface $account): AccessResultInterface {
    $departures = \Drupal::service('localnodes_platform.group_notice_cleanup');
    return AccessResult::allowedIf(self::allowed($departures, $group, $user, $account))
      ->setCacheMaxAge(0);
  }

  /**
   * The one rule used at route access, form build and submit.
   */
  public static function allowed(GroupNoticeCleanup $departures, GroupInterface $group, UserInterface $target, AccountInterface $actor): bool {
    $uid = (int) $target->id();
    $gid = (int) $group->id();
    return $actor->isAuthenticated()
      && (int) $actor->id() !== $uid
      && $uid > 0
      // A site role alone never qualifies: the organizer must be in the house.
      && (bool) $group->getMember($actor)
      && $group->hasPermission('administer members', $actor)
      // Only someone who actually left this house, and is not back in it.
      && !$group->getMember($target)
      && $departures->departureTime($uid, $gid) !== NULL
      && !$departures->retainedReadingClosed($uid, $gid);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL, ?UserInterface $user = NULL): array {
    $this->group = $group;
    $this->target = $user;
    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#value'] = $this->t('Close their reading');
    $form['actions']['cancel']['#title'] = $this->t('Keep their reading');
    return $form;
  }

  public function getQuestion() {
    return $this->t('Close @name’s continued reading in @house?', $this->args());
  }

  public function getDescription() {
    return $this->t('@name will no longer be able to read the house-only pages they wrote here or the replies under them. If they rejoin @house later, they get normal member access again.', $this->args());
  }

  public function getConfirmText() {
    return $this->t('Close their reading');
  }

  public function getCancelText() {
    return $this->t('Keep their reading');
  }

  public function getCancelUrl(): Url {
    return $this->group ? $this->group->toUrl() : Url::fromRoute('<front>');
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->current()) {
      $form_state->setErrorByName('', $this->t('You are not authorized to access this page.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Check again at submit: the person may have rejoined, or the organizer
    // may have lost the role, since the form was built.
    if (!$current = $this->current()) return;
    [$group, $target] = $current;
    $this->departures->recordRemoval((int) $target->id(), (int) $group->id(), TRUE);
    $this->messenger()->addStatus($this->t('@name’s continued reading access in @house was closed for safety.', $this->args()));
    $form_state->setRedirectUrl($group->toUrl());
  }

  /** Reloads the real house and person instead of trusting the built form. */
  private function current(): ?array {
    if (!$this->group || !$this->target) return NULL;
    $storage = \Drupal::entityTypeManager();
    $group = $storage->getStorage('group')->loadUnchanged($this->group->id());
    $target = $storage->getStorage('user')->loadUnchanged($this->target->id());
    if (!$group instanceof GroupInterface || !$target instanceof UserInterface
      || !self::allowed($this->departures, $group, $target, $this->currentUser())) return NULL;
    return [$group, $target];
  }

  private function args(): array {
    return [
      '@name' => $this->target?->getDisplayName() ?? '',
      '@house' => $this->group?->label() ?? '',
    ];
  }

}
