<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Access\RefreshAccessCheck;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Exception\RefreshConflictException;
use Drupal\localnodes_import\Exception\RefreshNotAllowedException;
use Drupal\localnodes_import\Service\RefreshFromSource;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The "Refresh from source" confirmation (UI-SPEC Copywriting Contract,
 * "Destructive confirmation"; D-15, D-25b).
 *
 * Reached from a duplicate web row of a run report, with ?run= naming that
 * report so "Keep current version" goes back to it. The page must be a Topic
 * in the route's group that was imported from the web; anything else is a
 * 404 (no cross-group IDOR). The route needs the group's import permission
 * and view and update access to the Topic (RefreshAccessCheck, merge review
 * R1 item 2), and the form token protects the POST (T-22-70).
 *
 * The rights checkbox is required on the server before anything is fetched
 * (T-22-90). The refresh is its own run (orchestrator default, Tom to
 * confirm): who ticked the box comes from the session and when from the
 * request; visibility and page type are copied from the Topic, never from
 * the POST. RefreshFromSource does the fetch and writes the run's one row.
 */
class RefreshFromSourceConfirmForm extends ConfirmFormBase {

  use ImportFormTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The time service.
   */
  protected TimeInterface $time;

  /**
   * The refresh service.
   */
  protected RefreshFromSource $refresher;

  /**
   * Who may refresh a Topic.
   */
  protected RefreshAccessCheck $refreshAccess;

  /**
   * The group in the address.
   */
  protected ?GroupInterface $group = NULL;

  /**
   * The page to refresh.
   */
  protected ?NodeInterface $node = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->time = $container->get('datetime.time');
    $form->refresher = $container->get('localnodes_import.refresh_from_source');
    $form->refreshAccess = $container->get('localnodes_import.refresh_access');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_import_refresh_from_source';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    // Also the page title, so Title Case (UI-SPEC Part 6, Tom 2026-11-07);
    // the page's own name keeps its spelling.
    return $this->t('Refresh @title from Its Source?', ['@title' => $this->node?->label() ?? '']);
  }

  /**
   * The page title: the question, with the page's name.
   */
  public function title(?NodeInterface $topic = NULL): TranslatableMarkup {
    $this->node = $topic;
    return $this->getQuestion();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t("This replaces the page's text with the current version from @site. The page address, its comments and its Source box stay. Any edits made to the text on this site will be lost.", [
      '@site' => $this->siteName(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Replace page text');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): TranslatableMarkup {
    return $this->t('Keep current version');
  }

  /**
   * {@inheritdoc}
   *
   * The report named by ?run= when it is a run of this group, otherwise
   * the group's Import tab.
   */
  public function getCancelUrl(): Url {
    $gid = (int) $this->group?->id();
    $run_id = (int) $this->getRequest()->query->get('run');
    if ($run_id > 0) {
      $run = $this->entityTypeManager->getStorage('localnodes_import_run')->load($run_id);
      if ($run instanceof ImportRun && $run->getGroupId() === $gid) {
        return Url::fromRoute('localnodes_import.run_report', ['group' => $gid, 'localnodes_import_run' => $run_id]);
      }
    }
    return Url::fromRoute('localnodes_import.landing', ['group' => $gid]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL, ?NodeInterface $topic = NULL): array {
    if (!$group instanceof GroupInterface || !$topic instanceof NodeInterface || !$this->isImportedWebPage($group, $topic)) {
      throw new NotFoundHttpException();
    }
    $this->group = $group;
    $this->node = $topic;
    $form_state->set('group_id', (int) $group->id());
    $form_state->set('nid', (int) $topic->id());

    $form = parent::buildForm($form, $form_state);
    $form['#attributes']['class'][] = 'localnodes-import-refresh';
    $form['#attached']['library'][] = 'localnodes_import/web';
    $form['description']['#prefix'] = '<p class="localnodes-import-refresh__body">';
    $form['description']['#suffix'] = '</p>';
    // D-25b: the same rights checkbox, above the buttons, never pre-ticked.
    $actions = $form['actions'];
    unset($form['actions']);
    $form['rights_confirmed'] = $this->rightsElement();
    $form['actions'] = $actions;
    $form['actions']['submit']['#button_type'] = 'danger';
    // The safe choice is a default button and gets initial focus.
    $form['actions']['cancel']['#attributes']['class'][] = 'btn';
    $form['actions']['cancel']['#attributes']['class'][] = 'btn-default';
    $form['actions']['cancel']['#attributes']['autofocus'] = 'autofocus';
    $form['actions']['cancel']['#cache']['contexts'][] = 'url.query_args:run';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->validateRights($form_state);
    $errors = $form_state->getErrors();
    if ($errors !== []) {
      $form = ['error_summary' => $this->errorSummary(static::summaryEntries($errors))] + $form;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $gid = (int) $form_state->get('group_id');
    $node = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('nid'));
    if (!$node instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }
    // Access may have changed since the page was opened: no run for someone
    // who can't change the page now.
    if (!$this->refreshAccess->check($node, $gid, $this->currentUser())->isAllowed()) {
      $this->messenger()->addError($this->notAllowedMessage());
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $topic_type = $node->hasField('field_topic_type') ? $node->get('field_topic_type')->target_id : NULL;
    /** @var \Drupal\localnodes_import\Entity\ImportRun $run */
    $run = $this->entityTypeManager->getStorage('localnodes_import_run')->create([
      'gid' => $gid,
      'uid' => $this->currentUser()->id(),
      'kind' => ImportRun::KIND_WEB,
      'status' => ImportRun::STATUS_IN_PROGRESS,
      // From the Topic, never the POST.
      'visibility' => (string) ($node->hasField('field_content_visibility') ? $node->get('field_content_visibility')->value : ''),
      'topic_type' => $topic_type !== NULL ? (int) $topic_type : NULL,
      'license' => '',
      // Who and when come from the session and the request (D-25b).
      'rights_confirmed_by' => $this->currentUser()->id(),
      'rights_confirmed_at' => $this->time->getRequestTime(),
    ]);
    $run->save();

    try {
      $item = $this->refresher->refresh($node, $run);
      if ($item->get('outcome')->value === ImportItem::OUTCOME_REFRESHED) {
        $this->messenger()->addStatus($this->t('@title was refreshed from its source.', ['@title' => $node->label()]));
      }
    }
    catch (RefreshNotAllowedException) {
      // The run's row records the refusal; nothing was saved.
      $this->messenger()->addError($this->notAllowedMessage());
    }
    catch (RefreshConflictException) {
      // Someone edited the page during the fetch; their edit is kept.
      $this->messenger()->addError($this->t('This page changed while it was being refreshed. Try again.'));
    }
    $form_state->setRedirect('localnodes_import.run_report', [
      'group' => $gid,
      'localnodes_import_run' => $run->id(),
    ]);
  }

  /**
   * The error when the person may not change the page (any more).
   */
  protected function notAllowedMessage(): TranslatableMarkup {
    return $this->t("You can't change this page, so it wasn't refreshed.");
  }

  /**
   * Whether the node is a web-imported Topic in this group.
   */
  protected function isImportedWebPage(GroupInterface $group, NodeInterface $node): bool {
    if ($node->bundle() !== 'topic' || !$node->hasField('field_source_url') || $node->get('field_source_url')->isEmpty()) {
      return FALSE;
    }
    $relationships = $this->entityTypeManager->getStorage('group_content')->loadByProperties([
      'gid' => $group->id(),
      'plugin_id' => 'group_node:topic',
      'entity_id' => $node->id(),
    ]);
    return $relationships !== [];
  }

  /**
   * The source site's name for the body copy: the attribution, or the host.
   */
  protected function siteName(): string {
    if ($this->node === NULL) {
      return '';
    }
    if ($this->node->hasField('field_source_attribution') && !$this->node->get('field_source_attribution')->isEmpty()) {
      $name = trim((string) $this->node->get('field_source_attribution')->value);
      if ($name !== '') {
        return $name;
      }
    }
    return (string) parse_url((string) $this->node->get('field_source_url')->uri, PHP_URL_HOST);
  }

}
