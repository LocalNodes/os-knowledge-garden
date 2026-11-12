<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\localnodes_platform\Export\ExportAccess;
use Drupal\localnodes_platform\Export\ExportIdentity;
use Drupal\localnodes_platform\Export\ExportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** The approved settings screen and its small identity confirmation panel. */
final class SiteExportForm extends FormBase {

  public function __construct(private readonly ExportManager $exports, private readonly ExportAccess $access, private readonly ExportIdentity $identity) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('localnodes_platform.export_manager'), $container->get('localnodes_platform.export_access'), $container->get('localnodes_platform.export_identity'));
  }

  public function getFormId(): string { return 'localnodes_site_export'; }

  public function buildForm(array $form, FormStateInterface $form_state, string $operation = ''): array {
    $form['#cache']['max-age'] = 0;
    $form['#attached']['library'][] = 'localnodes_platform/site_export';
    if (!$this->access->hasOwner()) {
      $form['off'] = ['#markup' => '<p>' . $this->t('This site has no owner on record, so exporting is off. Whoever runs the server can name an owner.') . '</p>'];
      return $form;
    }
    if (!$this->access->allows((int) $this->currentUser()->id())) return $form;
    $job = $this->exports->current();
    $form_state->set('export_operation', $operation);
    $form_state->set('export_id', $job['id'] ?? '');
    if ($operation !== '') {
      $form['#prefix'] = '<div class="ln-export-confirm">';
      $form['#suffix'] = '</div>';
      $form['heading'] = ['#markup' => '<h2>' . $this->t('Confirm it is you') . '</h2><p>' . $this->t('Use your current password to request this copy and each time you download it.') . '</p>'];
      $user = $this->access->account((int) $this->currentUser()->id());
      if (!$user?->getPassword()) {
        $form['help'] = $this->help();
        return $form;
      }
      $form['acknowledged'] = ['#type' => 'checkbox', '#title' => $this->t('I understand this copy includes private records from every group.'), '#required' => TRUE];
      $form['password'] = ['#type' => 'password', '#title' => $this->t('Your current password'), '#required' => TRUE, '#attributes' => ['autocomplete' => 'current-password']];
      $labels = ['request' => 'Confirm and request export', 'download' => 'Confirm and download', 'delete' => 'Confirm and delete this download'];
      if (!isset($labels[$operation])) return [];
      $form['actions']['#type'] = 'actions';
      $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t($labels[$operation])];
      $form['help_text'] = ['#markup' => '<p>' . $this->t('Sign in without a password?') . '</p>'];
      $form['help'] = $this->help();
      $form['cancel'] = ['#type' => 'link', '#title' => $this->t('Cancel'), '#url' => Url::fromRoute('localnodes_platform.site_export')];
      return $form;
    }
    $status = $job['status'] ?? 'never';
    if ($status === 'ready' && !$this->exports->available($job)) $status = 'expired';
    if (in_array($status, ['queued', 'running'], TRUE) && !$this->access->allows((int) $job['uid'])) $status = 'expired';
    $actions = ['#type' => 'container', '#attributes' => ['class' => ['ln-export-actions']]];
    $link = fn (string $label, string $op): array => ['#type' => 'link', '#title' => $this->t($label), '#url' => Url::fromRoute('localnodes_platform.site_export_confirm', ['operation' => $op]), '#attributes' => ['class' => ['btn', 'btn-primary']]];
    if ($status === 'ready') {
      $actions['download'] = $link('Download export (.tar)', 'download');
      $actions['size'] = ['#plain_text' => self::size((int) $job['bytes']) . ' total'];
      $actions['delete'] = $link('Delete this download', 'delete');
      $actions['delete']['#attributes']['class'] = ['btn', 'btn-default'];
    }
    elseif (in_array($status, ['queued', 'running'], TRUE)) {
      $actions['busy'] = ['#type' => 'button', '#value' => $status === 'running' ? $this->t('Preparing export…') : $this->t('Export requested'), '#disabled' => TRUE];
      $actions['refresh'] = ['#type' => 'link', '#title' => $this->t('Check status'), '#url' => Url::fromRoute('localnodes_platform.site_export'), '#attributes' => ['class' => ['btn', 'btn-default']]];
    }
    else $actions['request'] = $link($status === 'failed' ? 'Try again' : 'Export this site', 'request');
    if ($status === 'failed' || !$this->access->account((int) $this->currentUser()->id())?->getPassword()) $actions['help'] = $this->help();
    if (!$this->access->account((int) $this->currentUser()->id())?->getPassword()) unset($actions['request'], $actions['download'], $actions['delete']);
    $parts = [];
    foreach (['records', 'files', 'library'] as $key) $parts[$key] = (in_array($status, ['ready', 'running'], TRUE) && isset($job['parts'][$key])) ? self::size((int) $job['parts'][$key]) : NULL;
    $last = $this->exports->last();
    $form['screen'] = [
      '#theme' => 'localnodes_site_export', '#status' => $status, '#parts' => $parts,
      '#actions' => $actions, '#failure' => $job['failure'] ?? '',
      '#requested_by' => $this->name((int) ($job['uid'] ?? 0)), '#requested_at' => $this->date((int) ($job['requested'] ?? 0)),
      '#last_by' => $this->name((int) ($last['uid'] ?? 0)), '#last_at' => $this->date((int) ($last['time'] ?? 0)),
      '#expires' => $this->date((int) ($job['expires'] ?? 0)), '#reference' => $job ? 'EX-' . strtoupper(substr($job['id'], 0, 8)) : '',
    ];
    return $form;
  }

  private function help(): array {
    return ['#type' => 'link', '#title' => $this->t('Ask for export help'), '#url' => Url::fromRoute('localnodes_platform.get_help')];
  }

  private function name(int $uid): string { return $this->access->account($uid)?->getDisplayName() ?? ''; }
  private function date(int $time): string {
    if (!$time) return '';
    $zone = (string) (\Drupal::config('system.date')->get('timezone.default') ?: 'UTC');
    return \Drupal::service('date.formatter')->format($time, 'custom', 'M j, Y \\a\\t g:i a T', $zone);
  }
  private static function size(int $bytes): string { return number_format($bytes / 1000000, 1) . ' MB'; }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ok = $this->identity->confirm((int) $this->currentUser()->id(), (string) $form_state->getValue('password', ''), (bool) $form_state->getValue('acknowledged'));
    // Do not retain a password in form state, input or a rebuilt form.
    $form_state->setValue('password', '');
    $input = $form_state->getUserInput();
    unset($input['password']);
    $form_state->setUserInput($input);
    if (!$ok) $form_state->setErrorByName('password', $this->t('We could not confirm it is you. Try again or ask for export help.'));
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $this->currentUser()->id();
    try {
      switch ($form_state->get('export_operation')) {
        case 'request': $this->exports->request($uid); break;
        case 'download':
          $response = $this->exports->download($uid, (string) $form_state->get('export_id'));
          if ($response) { $form_state->setResponse($response); return; }
          $this->messenger()->addError($this->t('This download is no longer available. Request another copy when you need one.'));
          break;
        case 'delete':
          if (!$this->exports->delete($uid, (string) $form_state->get('export_id'))) $this->messenger()->addError($this->t('The download is off. Cleanup will be retried.'));
          break;
      }
    }
    catch (\Throwable) { $this->messenger()->addError($this->t('We could not prepare your copy. Ask for export help.')); }
    $form_state->setRedirect('localnodes_platform.site_export');
  }

}
