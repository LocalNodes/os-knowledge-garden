<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Votes;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueWorkerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Holds the voting module's queued steps for a members-only group.
 *
 * The worker of the voting module's set-up and role-change queues
 * (localnodes_web3_queue_info_alter()). For a voting record whose fund
 * belongs to a members-only group, nothing is sent or published: the voting
 * module's own worker is not called, and its record, role rows and fund
 * payment are kept exactly as they are. The hold is marked, one key per
 * queue and record, with one log notice when it starts. Every other item,
 * an item it can't read included, goes to the voting module's own worker
 * unchanged, and the record's mark is removed.
 *
 * The decision is made under the fund's lock, the one a switch to
 * members-only takes, from a fresh read of the fund's groups. While another
 * request holds the lock, the item neither runs nor is held: it returns
 * normally, and the next cron run tries again.
 *
 * A held item returns normally, so the queue keeps no item: the voting
 * module queues a new round only when its queue is empty, so a kept item
 * would stop every other group's steps. The voting module queues the record
 * again at each cron run while the work waits, so the next run after the
 * group is public again carries it out.
 */
final class PublicVotesQueueHold extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The voting module's queues this worker holds.
   */
  public const QUEUES = ['safe_governance_setup', 'safe_governance_membership'];

  /**
   * The key-value collection of holds: "queue:record" => when first held.
   */
  public const HELD = 'localnodes_web3.held_steps';

  /**
   * The plugin definition key that keeps the voting module's own worker.
   */
  public const INNER = 'localnodes_web3_inner';

  /**
   * The holds.
   */
  private readonly KeyValueStoreInterface $held;

  /**
   * Constructs the worker.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The queue id.
   * @param array<string, mixed> $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Queue\QueueWorkerInterface $inner
   *   The voting module's own worker for this queue.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The site module's logger.
   * @param \Drupal\Core\KeyValueStore\KeyValueFactoryInterface $keyValue
   *   The key-value store.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock.
   * @param \Drupal\localnodes_web3\Votes\MembersOnlyWait $wait
   *   The fund's lock and the fresh read of its groups.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly QueueWorkerInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    KeyValueFactoryInterface $keyValue,
    private readonly TimeInterface $time,
    private readonly MembersOnlyWait $wait,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->held = $keyValue->get(self::HELD);
  }

  /**
   * {@inheritdoc}
   *
   * The voting module's worker is built by its own create(), so this class
   * names none of the voting module's classes.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $class = $plugin_definition[self::INNER] ?? NULL;
    if (!is_string($class) || !is_subclass_of($class, QueueWorkerInterface::class)) {
      throw new \LogicException(sprintf('The queue %s has no worker to hold for.', $plugin_id));
    }
    $inner = is_subclass_of($class, ContainerFactoryPluginInterface::class)
      ? $class::create($container, $configuration, $plugin_id, $plugin_definition)
      : new $class($configuration, $plugin_id, $plugin_definition);
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $inner,
      $container->get('entity_type.manager'),
      $container->get('logger.channel.localnodes_web3'),
      $container->get('keyvalue'),
      $container->get('datetime.time'),
      $container->get('localnodes_web3.members_only_wait'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = is_array($data) ? ($data['governance'] ?? NULL) : NULL;
    $record = is_int($id) ? $this->entityTypeManager->getStorage('safe_governance')->load($id) : NULL;
    $fund_id = $record !== NULL && method_exists($record, 'get') ? (int) $record->get('safe_account')->target_id : 0;
    if ($fund_id === 0) {
      if (is_int($id)) {
        $this->held->delete($this->key($id));
      }
      $this->inner->processItem($data);
      return;
    }
    if (!$this->wait->lockFund($fund_id)) {
      // Busy: neither run nor held; the next cron run tries again.
      return;
    }
    try {
      if ($this->wait->fundMembersOnlyNow($fund_id)) {
        $this->hold((int) $id);
        return;
      }
      $this->held->delete($this->key((int) $id));
      $this->inner->processItem($data);
    }
    finally {
      $this->wait->unlockFund($fund_id);
    }
  }

  /**
   * Marks the record held for this queue; tells the log the first time.
   */
  private function hold(int $id): void {
    if ($this->held->setIfNotExists($this->key($id), (int) $this->time->getRequestTime())) {
      $this->logger->notice('A @queue step of voting record @id waits: its group is members-only. It is kept and runs once the group is public again.', [
        '@queue' => $this->getPluginId(),
        '@id' => $id,
      ]);
    }
  }

  /**
   * The hold's key for a record in this queue.
   */
  private function key(int $id): string {
    return $this->getPluginId() . ':' . $id;
  }

}
