<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\Core\Database\Connection;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\localnodes_demo_packs\PackUserRules;
use Drupal\localnodes_demo_packs\ResolvesPackTokens;
use Drupal\social_demo\DemoUser;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pack User: social_demo's user loader, with safe passwords and an owner.
 *
 * social_demo sets each account's password to its username, so anyone could
 * sign in as a demo member. Every account this plugin creates gets a random
 * password instead, which is never shown or logged. Presenters sign in with
 * a one-time link from `drush user:login`.
 *
 * Before anyone is created, the user file is checked: reserved test
 * addresses only, and at most one `export_owner: true`. That user gets the
 * sitemanager role and becomes the site's export owner, unless the site
 * already has one.
 *
 * All of it runs in one database transaction: social_demo saves each
 * account with its username as password before this plugin replaces it,
 * so a load that stops part way must keep no account and no owner. The
 * next load then starts clean. The loaders run in their own drush
 * process with no outer transaction; inside one, only a savepoint is
 * released here and the caller's rollback decides.
 *
 * @DemoContent(
 *   id = "pack_user",
 *   label = @Translation("Pack User"),
 *   source = "content/entity/user.yml",
 *   entity_type = "user"
 * )
 */
class PackUser extends DemoUser {

  use ResolvesPackTokens;

  /**
   * The password generator.
   */
  protected PasswordGeneratorInterface $passwordGenerator;

  /**
   * The state store.
   */
  protected StateInterface $state;

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->passwordGenerator = $container->get('password_generator');
    $instance->state = $container->get('state');
    $instance->database = $container->get('database');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function createContent($generate = FALSE, $max = NULL) {
    $data = $this->fetchData();
    PackUserRules::check($data);
    $transaction = $this->database->startTransaction();
    try {
      $content = $this->createAccounts($data, $generate, $max);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      // Forget the rolled-back accounts in memory too, so a retry on this
      // plugin or a load in this process cannot see them.
      $this->content = [];
      $this->entityStorage->resetCache();
      $this->profileStorage->resetCache();
      $this->state->resetCache();
      throw $e;
    }
    // The transaction commits when it goes out of scope.
    unset($transaction);
    return $content;
  }

  /**
   * Creates the accounts, then sets their passwords and the owner.
   *
   * @return \Drupal\user\UserInterface[]
   *   The created accounts.
   */
  private function createAccounts(array $data, bool $generate, ?int $max): array {
    $content = parent::createContent($generate, $max);

    foreach ($content as $account) {
      $account->setPassword($this->passwordGenerator->generate(32))->save();
    }

    foreach ($data as $uuid => $item) {
      if (empty($item['export_owner'])) {
        continue;
      }
      $owner = NULL;
      foreach ($content as $account) {
        if ($account->uuid() === $uuid) {
          $owner = $account;
        }
      }
      if (!$owner instanceof UserInterface) {
        continue;
      }
      if (!$owner->hasRole('sitemanager')) {
        $owner->addRole('sitemanager');
        $owner->save();
      }
      if ((int) $this->state->get('localnodes.organizer_uid', 0) === 0) {
        $this->state->set('localnodes.organizer_uid', (int) $owner->id());
      }
      else {
        $this->loggerChannelFactory->get('localnodes_demo_packs')->notice('Export owner already set; the pack\'s owner was not applied.');
      }
    }

    return $content;
  }

}
