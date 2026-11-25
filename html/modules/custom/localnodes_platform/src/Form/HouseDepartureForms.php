<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\group\Entity\Form\GroupRelationshipDeleteForm;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Form\GroupLeaveForm;
use Drupal\localnodes_platform\Mail\GroupNoticeCleanup;
use Drupal\user\UserInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Applies house departure copy and an explicit, scoped safety choice. */
final class HouseDepartureForms {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  private const CONTEXT = 'localnodes_house_departure';
  private const SNAPSHOT = 'localnodes_house_departure_confirmed';

  public function __construct(
    private EntityTypeManagerInterface $entities,
    private GroupNoticeCleanup $departures,
    private AccountInterface $actor,
    private MessengerInterface $messenger,
    TranslationInterface $translation,
  ) {
    $this->stringTranslation = $translation;
  }

  public function alter(array &$form, FormStateInterface $state): void {
    $native = $state->getFormObject();
    if (!$native instanceof GroupRelationshipDeleteForm) return;
    $relationship = $native->getEntity();
    if (!$relationship instanceof GroupRelationshipInterface || $relationship->getPluginId() !== 'group_membership') return;
    $group = $relationship->getGroup();
    $target = $relationship->getEntity();
    if ($group->bundle() !== 'flexible_group' || !$target instanceof UserInterface || !$this->matchesMembership($group, $target, $relationship)) return;
    $self = (int) $target->id() === (int) $this->actor->id();
    if (($native instanceof GroupLeaveForm && !$self)
      || (!$self && !$this->canManage($group))
      || !$this->authorized($group, $target, $relationship, $self)) return;
    $state->set(self::CONTEXT, [
      'rid' => (int) $relationship->id(), 'gid' => (int) $group->id(),
      'uid' => (int) $target->id(), 'actor' => (int) $this->actor->id(), 'self' => $self,
    ]);
    $args = ['@name' => $target->getDisplayName(), '@house' => $group->label()];
    $form['#title'] = $self ? $this->t('Leave @house?', $args) : $this->t('Remove @name from @house?', $args);
    $first = $self
      ? $this->t('You’ll still be able to read the pages you wrote in this house and all replies under them, including replies added after you leave.')
      : $this->t('@name will still be able to read the pages they wrote here and all replies under them, including later replies, unless you choose the safety option below. House-only pages they only commented on will stay closed to them.', $args);
    $second = $self
      ? $this->t('You won’t be able to edit, delete, comment or react in this house, and you’ll stop receiving its notices. House-only pages you only commented on will stay closed to you.')
      : $this->t('They won’t be able to edit, delete, comment or react in this house, and they’ll stop receiving its notices. Removing house membership does not close their site account.');
    $form['description'] = ['#type' => 'container', '#weight' => -10,
      'reading' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $first],
      'participation' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $second],
    ];
    $form['actions']['submit']['#value'] = $self ? $this->t('Leave this house') : $this->t('Remove from this house');
    $cancel = $self ? $this->t('Stay in this house') : $this->t('Keep as a member');
    $form['actions']['cancel']['#value'] = $cancel;
    $form['actions']['cancel']['#title'] = $cancel;
    if (!$self) {
      $form['localnodes_close_retained_reading'] = [
        '#type' => 'checkbox', '#default_value' => 0,
        '#title' => $this->t('Close @name’s continued reading access for safety.', $args),
        '#description' => $this->t('Off by default. Choose this to close their continued access to house-only pages they wrote and the replies under them.'),
      ];
      $form['localnodes_safety_warning'] = [
        '#type' => 'container', '#weight' => 1,
        '#states' => ['visible' => [':input[name="localnodes_close_retained_reading"]' => ['checked' => TRUE]]],
        'text' => ['#markup' => $this->t('@name will lose continued access to their house-only pages and replies too.', $args)],
      ];
    }
    $form['#validate'][] = [$this, 'validate'];
    $submits = $form['actions']['submit']['#submit'] ?? [];
    // Match FormSubmitter's fallback before adding button handlers. Otherwise
    // our callbacks would hide the form-level native deletion callback.
    if (!$submits) $submits = $form['#submit'] ?? [];
    if (!$submits) $submits = [[$native, 'submitForm']];
    array_unshift($submits, [$this, 'beforeSubmit']);
    $submits[] = [$this, 'afterSubmit'];
    $form['actions']['submit']['#submit'] = $submits;
  }

  private function matchesMembership(GroupInterface $group, UserInterface $target, GroupRelationshipInterface $relationship): bool {
    $membership = $group->getMember($target);
    return $membership && (int) $membership->getGroupRelationship()->id() === (int) $relationship->id();
  }

  private function canManage(GroupInterface $group): bool {
    return (bool) $group->getMember($this->actor) && $group->hasPermission('administer members', $this->actor);
  }

  private function authorized(GroupInterface $group, UserInterface $target, GroupRelationshipInterface $relationship, bool $self): bool {
    return $this->matchesMembership($group, $target, $relationship)
      && ($self ? ((int) $this->actor->id() === (int) $target->id() && $group->hasPermission('leave group', $this->actor))
        : ((int) $this->actor->id() !== (int) $target->id() && $this->canManage($group) && $relationship->access('delete', $this->actor)));
  }

  /** Reloads the actual targeted membership rather than trusting form values. */
  private function currentContext(FormStateInterface $state): ?array {
    $context = $state->get(self::CONTEXT);
    if (!is_array($context) || (int) $context['actor'] !== (int) $this->actor->id()) return NULL;
    $relationship = $this->entities->getStorage('group_content')->loadUnchanged($context['rid']);
    if (!$relationship instanceof GroupRelationshipInterface || $relationship->getPluginId() !== 'group_membership') return NULL;
    $group = $relationship->getGroup();
    $target = $relationship->getEntity();
    if (!$target instanceof UserInterface || (int) $group->id() !== $context['gid'] || (int) $target->id() !== $context['uid']
      || !$this->authorized($group, $target, $relationship, (bool) $context['self'])) return NULL;
    return [$context, $group, $target];
  }

  public function validate(array &$form, FormStateInterface $state): void {
    if (!$this->currentContext($state)) $state->setErrorByName('', $this->t('You are not authorized to access this page.'));
  }

  public function beforeSubmit(array &$form, FormStateInterface $state): void {
    if (!$current = $this->currentContext($state)) throw new AccessDeniedHttpException();
    [$context, $group, $target] = $current;
    $context['safety'] = !$context['self'] && (bool) $state->getValue('localnodes_close_retained_reading', FALSE);
    $context['name'] = $target->getDisplayName();
    $context['house'] = $group->label();
    $state->set(self::SNAPSHOT, $context);
  }

  public function afterSubmit(array &$form, FormStateInterface $state): void {
    $context = $state->get(self::SNAPSHOT);
    if (!is_array($context) || (int) $context['actor'] !== (int) $this->actor->id()
      || $this->entities->getStorage('group_content')->loadUnchanged($context['rid'])) return;
    $group = $this->entities->getStorage('group')->loadUnchanged($context['gid']);
    $target = $this->entities->getStorage('user')->load($context['uid']);
    if (!$group instanceof GroupInterface || !$target instanceof UserInterface || $group->getMember($target)) return;
    $args = ['@name' => $context['name'], '@house' => $context['house']];
    if ($context['self']) {
      // A forged checkbox can never turn voluntary leaving into safety removal.
      if ((int) $this->actor->id() !== (int) $target->id()) return;
      $this->departures->remove($context['uid'], $context['gid']);
      $this->messenger->addStatus($this->t('You’ve left @house. You can still read your pages and their replies.', $args));
    }
    else {
      if (!$this->canManage($group)) return;
      $this->departures->remove($context['uid'], $context['gid']);
      $this->departures->recordRemoval($context['uid'], $context['gid'], $context['safety']);
      if (!$this->departures->wasRemoved($context['uid'], $context['gid'])
        || $this->departures->retainedReadingClosed($context['uid'], $context['gid']) !== $context['safety']) return;
      $this->messenger->addStatus($context['safety']
        ? $this->t('@name has been removed from @house. Their continued reading access was closed for safety.', $args)
        : $this->t('@name has been removed from @house. They can still read their pages and the replies under them.', $args));
    }
    $state->set(self::SNAPSHOT, NULL);
  }

}
