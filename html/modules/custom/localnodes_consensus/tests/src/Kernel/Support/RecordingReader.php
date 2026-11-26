<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Kernel\Support;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;

/**
 * The reader, recording each record context it is handed.
 */
class RecordingReader extends CurrentEligibleReader {

  /**
   * Record contexts seen, in order (NULL when none was passed).
   *
   * @var array<int, \Drupal\Core\Entity\EntityInterface|null>
   */
  public static array $records = [];

  /**
   * {@inheritdoc}
   */
  public function access(GroupInterface $group, AccountInterface $account, string $visibility = self::GROUP, ?EntityInterface $record = NULL): AccessResultInterface {
    static::$records[] = $record;
    return parent::access($group, $account, $visibility, $record);
  }

}
