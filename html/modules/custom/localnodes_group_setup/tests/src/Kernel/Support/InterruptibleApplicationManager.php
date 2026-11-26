<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_group_setup\Kernel\Support;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_group_setup\Entity\TemplateApplication;
use Drupal\localnodes_group_setup\Service\TemplateApplicationManager;

/**
 * The application manager, able to stop once at a chosen point.
 *
 * Stands in for a request that dies part way: the exception is thrown after
 * the step's side effect happened and before the set-up record notes it, the
 * worst place for an interruption.
 */
class InterruptibleApplicationManager extends TemplateApplicationManager {

  /**
   * Starter key whose item is created, then interrupted before the ledger.
   */
  public static ?string $interruptAfterStarter = NULL;

  /**
   * Interrupt once after the rules were seeded and before the link is saved.
   */
  public static bool $interruptAfterSeed = FALSE;

  /**
   * Clears both switches.
   */
  public static function reset(): void {
    static::$interruptAfterStarter = NULL;
    static::$interruptAfterSeed = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function createStarterItem(GroupInterface $group, array $item, string $uuid, AccountInterface $actor): int {
    $id = parent::createStarterItem($group, $item, $uuid, $actor);
    if (static::$interruptAfterStarter !== NULL && static::$interruptAfterStarter === $item['key']) {
      static::$interruptAfterStarter = NULL;
      throw new \RuntimeException('Interrupted after creating a starter item.');
    }
    return $id;
  }

  /**
   * {@inheritdoc}
   */
  protected function seedDecisionRules(GroupInterface $group, TemplateApplication $application, AccountInterface $actor): int {
    $id = parent::seedDecisionRules($group, $application, $actor);
    if (static::$interruptAfterSeed) {
      static::$interruptAfterSeed = FALSE;
      throw new \RuntimeException('Interrupted after seeding the decision rules.');
    }
    return $id;
  }

}
