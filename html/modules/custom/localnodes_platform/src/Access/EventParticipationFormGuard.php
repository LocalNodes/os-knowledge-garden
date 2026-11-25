<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Access;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\social_event\Form\EnrollActionForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Rechecks house membership at the point an enrollment AJAX callback writes. */
final class EventParticipationFormGuard {

  private const EVENT = '#localnodes_enrollment_event';
  private const ORIGINAL = '#localnodes_enrollment_ajax';

  public function __construct(
    private readonly DepartedAuthorAccess $houses,
    private readonly EntityTypeManagerInterface $entities,
    private readonly AccountInterface $actor,
    private readonly RouteMatchInterface $route,
  ) {}

  public function alter(array &$form, FormStateInterface $state): void {
    $form = $this->protect($form, $state);
    $form['#after_build'][] = [self::class, 'afterBuild'];
  }

  public static function afterBuild(array $form, FormStateInterface $state): array {
    return \Drupal::service('localnodes_platform.event_participation_form_guard')->protect($form, $state);
  }

  public function protect(array $form, FormStateInterface $state): array {
    $id = $form['event']['#value'] ?? $form[self::EVENT] ?? NULL;
    if ($id === NULL) {
      $argument = $state->getBuildInfo()['args'][0] ?? $this->route->getParameter('node');
      $id = $argument instanceof NodeInterface ? $argument->id() : $argument;
    }
    $this->wrap($form);
    $this->wrapSelectedButtons($state);
    $event = $this->event($id);
    if ($event === NULL) {
      $form['#access'] = FALSE;
      return $form;
    }
    $form[self::EVENT] = (int) $event->id();
    if ($this->houses->deniesParticipation($event, $this->actor)) $form['#access'] = FALSE;
    CacheableMetadata::createFromRenderArray($form)->merge($this->houses->accessMetadata($event, $this->actor))->applyTo($form);
    return $form;
  }

  private function wrap(array &$element): void {
    if (isset($element['#ajax']['callback']) && $element['#ajax']['callback'] !== [self::class, 'ajax']) {
      $element[self::ORIGINAL] = $element['#ajax']['callback'];
      $element['#ajax']['callback'] = [self::class, 'ajax'];
    }
    foreach ($element as $key => &$child) {
      if (is_array($child) && !str_starts_with((string) $key, '#')) $this->wrap($child);
    }
  }

  /** Form API keeps copies selected before root after-build callbacks. */
  private function wrapSelectedButtons(FormStateInterface $state): void {
    if ($trigger = $state->getTriggeringElement()) {
      $this->wrap($trigger);
      $state->setTriggeringElement($trigger);
    }
    $buttons = $state->getButtons();
    foreach ($buttons as &$button) $this->wrap($button);
    $state->setButtons($buttons);
  }

  public static function ajax(array &$form, FormStateInterface $state, ?Request $request = NULL) {
    return \Drupal::service('localnodes_platform.event_participation_form_guard')->dispatch($form, $state, $request);
  }

  public function dispatch(array &$form, FormStateInterface $state, ?Request $request = NULL) {
    $trigger = $state->getTriggeringElement();
    $original = isset($trigger[self::ORIGINAL]) ? $state->prepareCallback($trigger[self::ORIGINAL]) : NULL;
    if (!is_callable($original)) throw new AccessDeniedHttpException();
    $id = $state->getValue('event');
    // Match the base callback's actual fallback, not one invented for other forms.
    if ($id === NULL && is_array($original) && $original[0] instanceof EnrollActionForm && $original[1] === 'ajaxSubmitEnrollForm') {
      $id = $this->route->getRawParameter('node');
    }
    if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0 || (int) $id !== ($form[self::EVENT] ?? 0)) {
      throw new AccessDeniedHttpException();
    }
    $event = $this->event($id);
    if ($event === NULL || $this->houses->deniesParticipation($event, $this->actor)) throw new AccessDeniedHttpException();
    return call_user_func_array($original, [&$form, &$state, $request]);
  }

  private function event($id): ?NodeInterface {
    if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) return NULL;
    $storage = $this->entities->getStorage('node');
    $storage->resetCache([(int) $id]);
    $event = $storage->load($id);
    return $event instanceof NodeInterface && $event->bundle() === 'event' ? $event : NULL;
  }

}
