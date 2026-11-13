<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\social_ai_indexing\Service\AssistantName;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The organizer guide at /guide (23-13, ONB-03, T-4).
 *
 * The words are in the template. This controller owns what varies: whether
 * the site has localnodes_web3 on (the fund and fees sections show only
 * then), the community's group and the assistant's name, and the links a
 * step may carry. A link is made only when its page exists and the
 * signed-in person may open it; otherwise the same words print as plain
 * text, so the page never offers a link that ends in "access denied". The
 * group is the exception: if this person can't open it, step 2 says the
 * group isn't ready and points to Get help.
 *
 * The fees section's last sentence ("As an organizer, you can see how much of
 * this month's limit is used.") and its link are not here: no route for an
 * organizer's monthly-limit page exists in the tree this was built on (see
 * 23-13-SUMMARY.md). Add both when that page ships.
 */
class GuideController extends ControllerBase {

  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    EntityTypeManagerInterface $entityTypeManager,
    protected RouteProviderInterface $routeProvider,
    protected ?AssistantName $assistantName = NULL,
  ) {
    $this->moduleHandler = $moduleHandler;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $container->get('router.route_provider'),
      // The chat's module is a dependency of this one, so the full site has
      // it; the guide still reads without it.
      $container->has('social_ai_indexing.assistant_name') ? $container->get('social_ai_indexing.assistant_name') : NULL,
    );
  }

  /**
   * Renders the guide.
   */
  public function page(): array {
    $web3 = $this->web3Enabled();
    $import_url = $this->groupTabUrl('localnodes_import.landing');
    $fund_url = $web3 ? $this->fundTabUrl() : NULL;
    $group = $this->firstGroup();
    $group_url = $group?->toUrl();
    $help_url = Url::fromRoute('localnodes_platform.get_help');

    $tags = ['group_list'];
    if ($group) {
      $tags = array_merge($tags, $group->getCacheTags());
    }
    if ($this->assistantName) {
      $tags = array_merge($tags, $this->assistantName->cacheTags());
    }

    return [
      '#theme' => 'localnodes_guide',
      '#show_fund' => $web3,
      '#show_fees' => $web3,
      '#group_link' => $group_url && $group_url->access() ? Link::fromTextAndUrl($group->label(), $group_url)->toString() : NULL,
      '#import_link' => $this->linkOrText($this->t('Import tab'), $import_url),
      '#fund_link' => $this->linkOrText($this->t('Open the Fund tab'), $fund_url),
      '#members_link' => $this->linkOrText($this->t('Manage members tab'), $this->manageMembersUrl()),
      '#get_help_link' => Link::fromTextAndUrl($this->t('Get help'), $help_url)->toString(),
      '#get_help_url' => $help_url->toString(),
      '#assistant_name' => $this->assistantName?->label(),
      '#sign_in_button' => $web3,
      '#attached' => ['library' => ['localnodes_platform/guide']],
      '#cache' => [
        // The links depend on who is looking and on which group is first;
        // the group's name and the assistant's name can be changed.
        'contexts' => ['user.permissions', 'user.group_permissions'],
        'tags' => array_values(array_unique($tags)),
      ],
    ];
  }

  /**
   * Whether this site has the optional Web3 stack on.
   */
  protected function web3Enabled(): bool {
    return $this->moduleHandler->moduleExists('localnodes_web3');
  }

  /**
   * The Fund tab of the community's first group, or NULL.
   *
   * NULL when the group or the tab's route is missing, or when the person
   * may not open it.
   */
  protected function fundTabUrl(): ?Url {
    return $this->groupTabUrl('group_treasury.treasury');
  }

  /**
   * A tab of the first group, if the route exists and the person may open it.
   */
  protected function groupTabUrl(string $route_name): ?Url {
    $gid = $this->firstGroupId();
    if ($gid === NULL || !$this->routeExists($route_name)) {
      return NULL;
    }
    $url = Url::fromRoute($route_name, ['group' => $gid]);
    return $url->access() ? $url : NULL;
  }

  /**
   * The first group's Manage members tab, or NULL.
   *
   * Not asked through the tab's address: Open Social checks that tab against
   * the group of the page being viewed, and on this page there is none, so
   * the answer is always no. The same question is asked about the group
   * itself instead (see mayManageMembers()).
   */
  protected function manageMembersUrl(): ?Url {
    $route_name = 'view.group_manage_members.page_group_manage_members';
    $group = $this->firstGroup();
    if ($group === NULL || !$this->routeExists($route_name) || !self::mayManageMembers($group, $this->currentUser())) {
      return NULL;
    }
    return Url::fromRoute($route_name, ['group' => $group->id()]);
  }

  /**
   * Whether this account may open the group's Manage members tab.
   *
   * Open Social's two checks on that tab, asked about this group.
   * Managers: may manage members site-wide or in this group. Joining: may
   * manage all groups; otherwise, in a flexible group, is a member, and in
   * any other kind of group, manages its members in the group itself. (A
   * flexible group that anyone can join also opens the tab to non-members;
   * the guide doesn't link it for them.)
   */
  public static function mayManageMembers(GroupInterface $group, AccountInterface $account): bool {
    $group_admin = $group->hasPermission('administer members', $account);
    if (!$account->hasPermission('administer members') && !$group_admin) {
      return FALSE;
    }
    if ($account->hasPermission('manage all groups')) {
      return TRUE;
    }
    if (!$account->isAuthenticated()) {
      return FALSE;
    }
    if ($group->getGroupType()->id() !== 'flexible_group') {
      return $group_admin;
    }
    return $group->getMember($account) !== FALSE;
  }

  /**
   * The community's first group (the lowest id), or NULL.
   *
   * The organizer-welcome command makes it on a fresh install and names it
   * after the community.
   */
  protected function firstGroup(): ?GroupInterface {
    $gid = $this->firstGroupId();
    return $gid === NULL ? NULL : $this->entityTypeManager->getStorage('group')->load($gid);
  }

  /**
   * The id of the community's first group (the lowest id), or NULL.
   */
  protected function firstGroupId(): ?int {
    if (!$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Whether a route is defined on this site.
   */
  protected function routeExists(string $route_name): bool {
    try {
      $this->routeProvider->getRouteByName($route_name);
      return TRUE;
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

  /**
   * A link when there is an address, the same words as plain text when not.
   *
   * The link is made as markup, not a render array: the template prints it
   * inside a {% trans %} sentence, whose placeholders take strings and markup.
   *
   * @return \Drupal\Component\Render\MarkupInterface
   *   The link, or the text.
   */
  protected function linkOrText(MarkupInterface $text, ?Url $url): MarkupInterface {
    return $url === NULL ? $text : Link::fromTextAndUrl($text, $url)->toString();
  }

}
