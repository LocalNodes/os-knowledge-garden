<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The group header's one-line description and its two action buttons.
 *
 * The description is the group's own description field, cut to its first
 * line as plain text; with none, nothing is shown. "New post" and "Invite"
 * show to a member of the group whose page for that action would let them in
 * (the route's own access check, never a role name). A non-member sees
 * neither, even one the page would let in: the header is for the people
 * inside the group.
 *
 * The buttons vary by the person's site and group permissions, and by
 * whatever else a page's own access check varies by.
 */
final class GroupHeader {

  use StringTranslationTrait;

  /**
   * The longest description line, in characters, with its ellipsis.
   */
  public const SUMMARY_LIMIT = 140;

  /**
   * The route behind "New post": the group's form for a new topic.
   */
  private const POST_ROUTE = 'entity.group_content.create_form';

  /**
   * The route behind "Invite": the group's invite page (ginvite).
   */
  private const INVITE_ROUTE = 'ginvite.invitation.bulk';

  public function __construct(
    private readonly AccessManagerInterface $accessManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * The first line of the group's description, as plain text.
   *
   * The first paragraph or line that holds any text, with tags dropped,
   * entities read and whitespace collapsed. A line longer than the limit is
   * cut at a word and ends in an ellipsis. Empty when there is no description
   * or the account may not see the group or its description.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The person reading the header.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cache
   *   Collects the group and both access results, so the line varies as
   *   they do.
   */
  public function summary(GroupInterface $group, AccountInterface $account, ?CacheableMetadata $cache = NULL): string {
    $cache ??= new CacheableMetadata();
    $cache->addCacheableDependency($group);
    $access = $group->access('view', $account, TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return '';
    }
    if (!$group->hasField('field_group_description') || $group->get('field_group_description')->isEmpty()) {
      return '';
    }
    $access = $group->get('field_group_description')->access('view', $account, TRUE);
    $cache->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return '';
    }
    $html = (string) $group->get('field_group_description')->value;
    // Each paragraph, block and line break ends a line.
    $text = preg_replace('@<br\s*/?>|</(p|div|li|h[1-6]|blockquote|pre)>@i', "\n", $html);
    $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    foreach (preg_split('/\R/u', $text) as $line) {
      $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
      if ($line !== '') {
        return $this->cut($line);
      }
    }
    return '';
  }

  /**
   * The header's buttons for the current user, with their cache metadata.
   *
   * @return array
   *   A render array: "post" and "invite" links, each only when allowed, in
   *   a row; with neither, only the cache metadata.
   */
  public function actions(GroupInterface $group): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ln-group-actions']],
    ];
    $cache = (new CacheableMetadata())
      ->addCacheableDependency($group)
      ->addCacheContexts(['user.group_permissions', 'user.permissions'])
      // Open Social's invite check reads whether members may invite from this
      // config and names no dependency on it.
      ->addCacheTags(['config:social_group.settings']);

    // Membership is part of the group permissions the context varies by.
    if ($this->currentUser->isAuthenticated() && $group->getMember($this->currentUser)) {
      $parameters = ['group' => $group->id()];
      $buttons = [
        'post' => [
          self::POST_ROUTE,
          $parameters + ['plugin_id' => 'group_node:topic'],
          $this->t('New post'),
          'btn-primary',
        ],
        'invite' => [self::INVITE_ROUTE, $parameters, $this->t('Invite'), 'btn-default'],
      ];
      foreach ($buttons as $key => [$route, $route_parameters, $title, $class]) {
        if (!$this->routeExists($route)) {
          continue;
        }
        $access = $this->accessManager->checkNamedRoute($route, $route_parameters, $this->currentUser, TRUE);
        $cache->addCacheableDependency($access);
        if ($access->isAllowed()) {
          $build[$key] = [
            '#type' => 'link',
            '#title' => $title,
            '#url' => Url::fromRoute($route, $route_parameters),
            '#attributes' => ['class' => ['btn', $class]],
          ];
        }
      }
    }

    // With no button, no row: an empty one would still take its margin.
    if (!array_intersect(['post', 'invite'], array_keys($build))) {
      $build = [];
    }
    // Every context a page's own check names is kept: a check that reads the
    // person varies by the person, even between two people with the same
    // permissions.
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Cuts a line to the limit at a word, with an ellipsis.
   */
  private function cut(string $line): string {
    if (mb_strlen($line) <= self::SUMMARY_LIMIT) {
      return $line;
    }
    return Unicode::truncate($line, self::SUMMARY_LIMIT, TRUE, TRUE);
  }

  /**
   * Whether a route exists on this site (the invite page needs ginvite).
   */
  private function routeExists(string $route): bool {
    try {
      $this->routeProvider->getRouteByName($route);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

}
