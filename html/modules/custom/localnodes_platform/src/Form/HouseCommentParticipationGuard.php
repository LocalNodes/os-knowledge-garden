<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Rechecks concrete house membership before normal or AJAX form writes. */
trait HouseCommentParticipationGuard {

  protected DepartedAuthorAccess $departedAuthors;

  public static function create(ContainerInterface $container) {
    $form = parent::create($container);
    $form->departedAuthors = $container->get('localnodes_platform.departed_author_access');
    return $form;
  }

  private function participationDenied(): bool {
    return $this->departedAuthors->deniesParticipation($this->entity, $this->currentUser);
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    if ($this->participationDenied()) {
      $form['#access'] = FALSE;
      $this->departedAuthors->accessMetadata($this->entity, $this->currentUser)->merge(CacheableMetadata::createFromRenderArray($form))->applyTo($form);
      return $form;
    }
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($this->participationDenied()) {
      $form_state->setErrorByName('', $this->t('You cannot add or change replies in this house.'));
      return $this->entity;
    }
    return parent::validateForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state) {
    // A membership can disappear after the form was validated. Never rely on
    // whether its controls were visible when it was originally built.
    if ($this->participationDenied()) {
      throw new AccessDeniedHttpException();
    }
    return parent::save($form, $form_state);
  }

}
