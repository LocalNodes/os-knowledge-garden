<?php

namespace Drupal\localnodes_platform\Plugin\EmailBuilder;

use Drupal\Core\Url;
use Drupal\symfony_mailer\EmailInterface;
use Drupal\symfony_mailer\Processor\EmailBuilderBase;
use Drupal\user\UserInterface;

/**
 * Builds the welcome mail a provisioned community's organizer gets (23-04).
 *
 * Sent by `drush localnodes:organizer-welcome`. It renders through the
 * LocalNodes wrapper from 22.1-07 (email--localnodes.html.twig), which prints
 * the community's name at the top and Tom's footer, and through
 * social_swiftmail's preprocess, which adds the "Hi <name>," greeting for the
 * account the mail goes to. The words are the UI-SPEC "Mail copy" table.
 *
 * The one-time sign-in link is made here, inside the body, with
 * user_pass_reset_url(). It is never returned, logged or printed (T-23-13).
 *
 * @EmailBuilder(
 *   id = "localnodes_platform",
 *   sub_types = {
 *     "organizer_welcome" = @Translation("Organizer welcome"),
 *   },
 *   common_adjusters = {},
 * )
 */
class OrganizerWelcomeEmailBuilder extends EmailBuilderBase {

  /**
   * Saves the account for a newly created email.
   *
   * @param \Drupal\symfony_mailer\EmailInterface $email
   *   The email to modify.
   * @param \Drupal\user\UserInterface|null $user
   *   The organizer's account.
   */
  public function createParams(EmailInterface $email, ?UserInterface $user = NULL) {
    assert($user !== NULL);
    $email->setParam('user', $user);
  }

  /**
   * {@inheritdoc}
   */
  public function init(EmailInterface $email) {
    parent::init($email);
    $email->setTo($email->getParam('user'));
  }

  /**
   * {@inheritdoc}
   *
   * The subject is "Welcome to <community name>" (BRAND.md section 9), the
   * same wording as the user.mail welcome subjects that 22.1-07 sets.
   */
  public function build(EmailInterface $email) {
    $site_name = (string) \Drupal::config('system.site')->get('name');
    $email->setSubject('Welcome to ' . $site_name);
  }

  /**
   * {@inheritdoc}
   */
  public function preRender(EmailInterface $email) {
    /** @var \Drupal\user\UserInterface $account */
    $account = $email->getParam('user');
    $options = ['absolute' => TRUE, 'language' => \Drupal::languageManager()->getLanguage($email->getLangcode())];
    $email->setBody([
      '#theme' => 'localnodes_organizer_welcome',
      '#reset_url' => user_pass_reset_url($account, $options),
      '#guide_url' => Url::fromUserInput('/guide', ['absolute' => TRUE])->toString(),
      '#sign_in_button' => \Drupal::moduleHandler()->moduleExists('localnodes_web3'),
    ]);
  }

}
