<?php

declare(strict_types=1);

namespace Drupal\localnodes_quick_poll\Controller;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;

/**
 * A group's quick polls page and its "ask a quick question" form.
 */
final class QuickPollController extends ControllerBase {

  /**
   * How many quick polls a group's page lists, newest first.
   */
  private const LIST_LIMIT = 50;

  /**
   * Opens a group's quick polls to its members and site administrators.
   */
  public static function access(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return _localnodes_quick_poll_member_access($group, $account);
  }

  /**
   * Lists a group's quick polls, each with its answer form or its answers.
   */
  public function list(GroupInterface $group): array {
    $build = [
      '#cache' => [
        'tags' => ['poll_list'],
        'contexts' => ['user'],
      ],
      '#attached' => ['library' => ['localnodes_quick_poll/quick_poll']],
    ];
    $build['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('A quick poll is an informal check on how members of @group lean. It does not settle anything for the group, and only its members can see it.', ['@group' => $group->label()]),
    ];
    $build['ask'] = [
      '#type' => 'link',
      '#title' => $this->t('Ask a quick question'),
      '#url' => Url::fromRoute('localnodes_quick_poll.group_add', ['group' => $group->id()]),
      '#attributes' => ['class' => ['btn', 'btn-primary']],
      '#prefix' => '<div class="localnodes-quick-poll-ask">',
      '#suffix' => '</div>',
    ];

    $storage = $this->entityTypeManager()->getStorage('poll');
    // The query is limited to this group, and the route has already checked
    // that the visitor may see this group's polls.
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('quick_poll_group', $group->id())
      ->sort('created', 'DESC')
      ->range(0, self::LIST_LIMIT)
      ->execute();
    if ($ids === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No quick polls yet.'),
      ];
      return $build;
    }
    $view_builder = $this->entityTypeManager()->getViewBuilder('poll');
    foreach ($storage->loadMultiple($ids) as $poll) {
      $build['polls'][$poll->id()] = $view_builder->view($poll, 'teaser');
      $build['polls'][$poll->id()]['#attributes']['class'][] = 'card';
      $build['polls'][$poll->id()]['#attributes']['class'][] = 'localnodes-quick-poll';
    }
    return $build;
  }

  /**
   * Shows the form for a new quick poll in the group.
   *
   * The group is set here from the page's address, never from a form value,
   * so a member can only ask in a group whose page they could open.
   */
  public function add(GroupInterface $group): array {
    $poll = $this->entityTypeManager()->getStorage('poll')->create([
      'quick_poll_group' => $group->id(),
      'uid' => $this->currentUser()->id(),
    ]);
    // A question needs at least two choices, so the form starts with two
    // empty ones instead of the widget's one. The widget keeps its count in
    // the form state; "Add another item" still adds more.
    $form_state_additions = [
      'storage' => [
        'field_storage' => [
          '#parents' => [
            '#fields' => [
              'choice' => ['items_count' => 1, 'array_parents' => []],
            ],
          ],
        ],
      ],
    ];
    return $this->entityFormBuilder()->getForm($poll, 'default', $form_state_additions);
  }

}
