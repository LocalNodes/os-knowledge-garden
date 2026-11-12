<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\EmailBuilder;

use Drupal\Core\Url;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\user\UserInterface;

/**
 * Fixed export notices link only to the settings page, never an artifact.
 *
 * @EmailBuilder(
 *   id = "localnodes_export",
 *   sub_types = {
 *     "requested" = @Translation("Site export requested"),
 *     "ready" = @Translation("Site export ready"),
 *     "failed" = @Translation("Site export failed"),
 *   },
 *   common_adjusters = {},
 * )
 */
final class SiteExportEmailBuilder extends EmailBuilderBase {

  public function createParams(EmailInterface $email, ?UserInterface $user = NULL, array $job = []) {
    $email->setParam('user', $user)->setParam('export_job', $job);
  }

  public function init(EmailInterface $email) {
    parent::init($email);
    $email->setTo($email->getParam('user'));
  }

  public function build(EmailInterface $email) {
    $subjects = ['requested' => 'A site export was requested', 'ready' => 'Your site export is ready', 'failed' => 'Your site export could not be prepared'];
    $email->setSubject($subjects[$email->getSubType()]);
  }

  public function preRender(EmailInterface $email) {
    $job = $email->getParam('export_job');
    $actor = \Drupal::entityTypeManager()->getStorage('user')->load($job['uid']);
    $name = $actor?->getDisplayName() ?? $this->t('The site owner');
    $url = Url::fromRoute('localnodes_platform.site_export', [], ['absolute' => TRUE])->toString();
    $expires = empty($job['expires']) ? '' : \Drupal::service('date.formatter')->format($job['expires'], 'custom', 'M j, Y g:i a T', (string) (\Drupal::config('system.date')->get('timezone.default') ?: 'UTC'));
    $text = match ($email->getSubType()) {
      'requested' => $this->t('@name requested a copy of the whole community site, including private group records and member accounts. If your community did not expect this, contact the owner. View the request: @url', ['@name' => $name, '@url' => $url]),
      'ready' => $this->t('The copy requested by @name is ready. Available until @expires. Sign in to view it: @url. Keep any copy you download safe.', ['@name' => $name, '@url' => $url, '@expires' => $expires]),
      default => $this->t('No download was made available for @name’s request. Sign in for the next step: @url', ['@name' => $name, '@url' => $url]),
    };
    $email->setBody(['#markup' => $text]);
  }

}
