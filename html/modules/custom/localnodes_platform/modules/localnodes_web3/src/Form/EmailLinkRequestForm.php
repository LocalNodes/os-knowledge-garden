<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\localnodes_web3\EmailLink;

/**
 * "Email me a sign-in link": asks for an address and mails a link to it.
 *
 * The page answers the same way, and as fast, whether or not an account
 * uses the address, so it never tells a visitor who has an account here:
 * the lookup and the mail wait until after the response (EmailLinkMailer).
 * Core's flood limits for password requests apply, under events of their
 * own: per address of the visitor here (user.flood ip_limit, ip_window), and
 * per account in EmailLinkMailer. Each is checked and taken in one step
 * (FloodAllowance), so requests sent at once cannot share the last try.
 * Past either, the page still answers alike.
 */
final class EmailLinkRequestForm extends FormBase {

  private const FLOOD_IP = 'localnodes_web3.email_link_ip';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'localnodes_web3_email_link_request';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // Off, this page is not found (see EmailLink).
    EmailLink::requireSwitchedOn();
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Your email address'),
      '#description' => $this->t('We will email you a link that signs you in. It works once, for one day, on any device.'),
      '#required' => TRUE,
      '#maxlength' => 254,
      '#attributes' => ['autocomplete' => 'email', 'autocorrect' => 'off', 'autocapitalize' => 'off', 'spellcheck' => 'false'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Email me a link'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('user.flood');
    $limit = [
      self::FLOOD_IP,
      (int) $config->get('ip_limit'),
      (int) $config->get('ip_window'),
    ];
    if (\Drupal::service('localnodes_web3.flood_allowance')->reserve([$limit]) !== NULL) {
      $form_state->setErrorByName('mail', $this->t('Too many sign-in links were asked for from here. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    \Drupal::service('localnodes_web3.email_link_mailer')->queue((string) $form_state->getValue('mail'));
    $this->messenger()->addStatus($this->t('If an account here uses that address, a sign-in link is on its way to it. Open the link on any device; it works once, for one day.'));
    // Back to this page, which names no password, to ask again if needed.
    $form_state->setRedirect('localnodes_web3.email_link');
  }

}
