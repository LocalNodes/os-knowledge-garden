<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\social_post\Form\PostForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Rechecks the target house while preserving the installed PostForm behavior. */
final class DepartedPostForm extends PostForm {

  private DepartedAuthorAccess $departedAuthors;
  private RouteMatchInterface $houseRoute;

  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->departedAuthors = $container->get('localnodes_platform.departed_author_access');
    $form->houseRoute = $container->get('current_route_match');
    return $form;
  }

  private function routeGroup(): ?GroupInterface {
    $group = $this->houseRoute->getParameter('group');
    return $group instanceof GroupInterface ? $group : NULL;
  }

  private function targetPost(): EntityInterface {
    $group = $this->routeGroup();
    if ($group && $this->entity->isNew() && $this->entity->hasField('field_recipient_group')
      && $this->entity->get('field_recipient_group')->isEmpty()) {
      // Stock PostForm assigns this converted group only inside save(), after
      // validation. Resolve the intended target without mutating its entity or
      // mistaking a legitimate new private post for an unassigned orphan.
      $target = clone $this->entity;
      $target->set('field_recipient_group', ['target_id' => $group->id()]);
      return $target;
    }
    // A nonempty/existing recipient always takes part in the check, even if
    // someone submits the cached form from another group's route.
    return $this->entity;
  }

  private function participationDenied(): bool {
    if ($this->departedAuthors->deniesParticipation($this->targetPost(), $this->currentUser)) return TRUE;
    $group = $this->routeGroup();
    return $group && ($this->departedAuthors->deniesParticipation($group, $this->currentUser)
      || !$group->hasPermission('add post entities in group', $this->currentUser));
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    if ($this->participationDenied()) {
      $form['#access'] = FALSE;
      $metadata = $this->departedAuthors->accessMetadata($this->targetPost(), $this->currentUser);
      if ($group = $this->routeGroup()) $metadata = $metadata->merge($this->departedAuthors->accessMetadata($group, $this->currentUser));
      $metadata->merge(CacheableMetadata::createFromRenderArray($form))->applyTo($form);
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->participationDenied()) {
      $form_state->setErrorByName('', $this->t('You cannot add or change posts in this house.'));
      return $this->entity;
    }
    return parent::validateForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state) {
    if ($this->participationDenied()) throw new AccessDeniedHttpException();
    return parent::save($form, $form_state);
  }

}
