<?php

declare(strict_types=1);

namespace Drupal\localnodes_consensus\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Link;
use Drupal\Core\Controller\ControllerBase;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_consensus\Access\CurrentEligibleReader;
use Drupal\localnodes_consensus\Entity\ConsensusNotice;
use Drupal\localnodes_consensus\Entity\ConsensusRound;
use Drupal\localnodes_consensus\Record\DecisionRecordInterface;
use Drupal\localnodes_consensus\Service\DecisionRegister;
use Drupal\localnodes_group_setup\Service\GroupScope;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Group's Decisions register and each member's notice list.
 *
 * Every item on either page passed its own entity view access for the
 * current user, so the built record handler and the one reader decide who
 * sees what. This controller decides nothing about membership, adds
 * no permission and names no Group type or kind.
 */
class DecisionsController extends ControllerBase {

  public function __construct(
    protected readonly DecisionRegister $decisions,
    protected readonly GroupScope $groupScope,
    protected readonly CurrentEligibleReader $reader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_consensus.decision_register'),
      $container->get('localnodes_group_setup.group_scope'),
      $container->get('localnodes_consensus.current_eligible_reader'),
    );
  }

  /**
   * Only a Group whose type is set up for decisions has a register.
   */
  public function registerAccess(GroupInterface $group): AccessResultInterface {
    return AccessResult::allowedIf($this->groupScope->isAllowedGroup($group))
      ->addCacheableDependency($group)
      ->addCacheTags(['config:localnodes_group_setup.settings']);
  }

  /**
   * The Group's register, built for the current user.
   */
  public function register(GroupInterface $group): array {
    $build = $this->decisions->registerBuild($group, $this->currentUser());
    $build['#cache']['contexts'][] = 'user';
    $build['#cache']['tags'][] = 'group_content_list';
    return $build;
  }

  /**
   * The current user's notices, newest first.
   *
   * A row is listed only when its own view access allows it (its recipient,
   * then the reader at the round's visibility) and the reader still counts
   * the recipient as a reader of the round's Group at group visibility:
   * a notice belongs to current members only, so after leaving or removal
   * every row goes, public rounds' included. Each row shows the event
   * words, the Group's name and one link: no text, count or name. The
   * cacheability of every check, kept or dropped, is merged into the page.
   */
  public function notices(): array {
    $account = $this->currentUser();
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user'])
      ->addCacheTags([
        ConsensusNotice::ENTITY_TYPE_ID . '_list',
        DecisionRecordInterface::ENTITY_TYPE_ID . '_list',
        'group_content_list',
      ]);
    $storage = $this->entityTypeManager()->getStorage(ConsensusNotice::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('recipient', $account->id())
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->execute();
    $items = [];
    foreach ($ids ? $storage->loadMultiple($ids) : [] as $notice) {
      $access = $notice->access('view', $account, TRUE);
      $cacheability->addCacheableDependency($access);
      $round = $notice instanceof ConsensusNotice ? $notice->get('round')->entity : NULL;
      $group = $round instanceof ConsensusRound ? $round->get('gid')->entity : NULL;
      if (!$access->isAllowed() || !$group instanceof GroupInterface) {
        continue;
      }
      $belongs = $this->reader->access($group, $account, CurrentEligibleReader::GROUP);
      $cacheability->addCacheableDependency($belongs);
      if (!$belongs->isAllowed()) {
        continue;
      }
      $words = ConsensusNotice::words($notice->getKind(), (string) $group->label());
      if ($words === NULL) {
        continue;
      }
      [$heading, $line] = $words;
      $items[] = [
        '#wrapper_attributes' => ['class' => ['consensus-notices__item']],
        'heading' => ['#type' => 'html_tag', '#tag' => 'strong', '#value' => $heading],
        'line' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $line],
        'link' => Link::fromTextAndUrl($this->t('Open the decision'), $round->toUrl())->toRenderable(),
      ];
    }
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['consensus-notices']],
      'about' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Notices link to the record. They do not include proposal text or response counts.'),
      ],
      'belonging' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Notices appear here while you belong to the group. After you leave or are removed, they stop.'),
      ],
      'list' => $items ? [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['consensus-notices__list']],
      ] : [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('You have no notices.'),
      ],
    ];
    $cacheability->applyTo($build);
    return $build;
  }

}
