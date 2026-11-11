<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\localnodes_import\Access\TakedownAccessCheck;
use Drupal\localnodes_import\Service\ReportAudience;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "Unpublish this page": the takedown confirmation (ONB-05; UI-SPEC section 11).
 *
 * Reached from the report mail. The route lets in only the page's editors,
 * site managers and the group's managers (TakedownAccessCheck, T-23-17); the
 * form asks the same question again before it saves. Nothing is deleted: the
 * page is unpublished in a new revision that says why, and can be published
 * again from its edit form. A page that is already unpublished is left alone
 * and the person is told so. The page must be an imported page (404 if not).
 */
class TakedownConfirmForm extends ConfirmFormBase {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected TakedownAccessCheck $takedownAccess;

  protected ReportAudience $audience;

  protected LoggerInterface $logger;

  /**
   * The page to unpublish.
   */
  protected ?NodeInterface $node = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $form = new static();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->takedownAccess = $container->get('localnodes_import.takedown_access');
    $form->audience = $container->get('localnodes_import.report_audience');
    $form->logger = $container->get('logger.channel.localnodes_import');
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_import_takedown';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Unpublish @title?', ['@title' => $this->node?->label() ?? '']);
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
    return $this->t("This hides the page from everyone except people who can edit it. Nothing is deleted. You can publish it again from the page's edit form.");
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Unpublish this page');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): TranslatableMarkup {
    return $this->t('Keep page published');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->node instanceof NodeInterface ? $this->node->toUrl() : Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $topic = NULL): array {
    if (!$topic instanceof NodeInterface || !$this->audience->isImportedPage($topic)) {
      throw new NotFoundHttpException();
    }
    $this->node = $topic;
    $form_state->set('nid', (int) $topic->id());

    $form['#attributes']['class'][] = 'localnodes-import-takedown';
    $form['#attached']['library'][] = 'localnodes_import/web';

    // Already hidden: say so, offer no button that would do nothing.
    if (!$topic->isPublished()) {
      $form['already'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->alreadyMessage(),
        '#attributes' => ['class' => ['localnodes-import-takedown__body']],
      ];
      $form['actions'] = [
        '#type' => 'actions',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to @title', ['@title' => $topic->label()]),
          '#url' => $this->getCancelUrl(),
          '#attributes' => ['class' => ['btn', 'btn-default']],
        ],
      ];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);
    $form['description']['#prefix'] = '<p class="localnodes-import-takedown__body">';
    $form['description']['#suffix'] = '</p>';
    $form['actions']['submit']['#button_type'] = 'danger';
    // The safe choice is a default button and gets initial focus.
    $form['actions']['cancel']['#attributes']['class'][] = 'btn';
    $form['actions']['cancel']['#attributes']['class'][] = 'btn-default';
    $form['actions']['cancel']['#attributes']['autofocus'] = 'autofocus';
    // Safe button first, in the page and in the Tab order (23-UI-SPEC section
    // 11; 23-29). ConfirmFormBase puts the destructive submit first.
    $form['actions']['cancel']['#weight'] = -10;
    $form['actions']['submit']['#weight'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $node = $this->entityTypeManager->getStorage('node')->load((int) $form_state->get('nid'));
    if (!$node instanceof NodeInterface) {
      throw new NotFoundHttpException();
    }
    $this->node = $node;
    $account = $this->currentUser();
    $form_state->setRedirectUrl($this->afterUrl($node));

    // Access may have changed since the page was opened.
    if (!$this->takedownAccess->check($node, $account)->isAllowed()) {
      $this->messenger()->addError($this->t("You can't unpublish this page."));
      $form_state->setRedirectUrl(Url::fromRoute('<front>'));
      return;
    }
    // Changes nothing, and says so.
    if (!$node->isPublished()) {
      $this->messenger()->addStatus($this->alreadyMessage());
      return;
    }

    try {
      $node->setUnpublished();
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Unpublished after a problem report.');
      $node->setRevisionUserId((int) $account->id());
      $node->save();
    }
    catch (\Throwable $e) {
      $this->logger->error('Page @nid could not be unpublished: @message', ['@nid' => (int) $node->id(), '@message' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Something went wrong on our side. The page is still published.'));
      return;
    }
    // Who did it and when (the log records the time); the page's id.
    $this->logger->notice('Page @nid was unpublished by user @uid after a problem report.', ['@nid' => (int) $node->id(), '@uid' => (int) $account->id()]);
    $this->messenger()->addStatus($this->t('@title is unpublished. To publish it again, edit the page and turn Published back on.', ['@title' => $node->label()]));
  }

  /**
   * Where the person goes next: the page if they can still see it, else home.
   */
  protected function afterUrl(NodeInterface $node): Url {
    return $node->access('view', $this->currentUser()) ? $node->toUrl() : Url::fromRoute('<front>');
  }

  /**
   * The sentence for a page that is already hidden.
   */
  protected function alreadyMessage(): TranslatableMarkup {
    return $this->t('This page is already unpublished.');
  }

}
