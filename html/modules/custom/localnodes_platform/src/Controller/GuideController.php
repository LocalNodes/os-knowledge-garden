<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The organizer guide at /guide (23-13, ONB-03, T-4).
 *
 * The words are 23-UI-SPEC section 8, word for word, in the template. This
 * controller owns what varies: whether the site has localnodes_web3 on (the
 * fund and fees sections show only then), and the three links a step may
 * carry. A link is made only when its page exists and the signed-in person
 * may open it; otherwise the same words print as plain text, so the page
 * never offers a link that ends in "access denied".
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
    );
  }

  /**
   * Renders the guide.
   */
  public function page(): array {
    $web3 = $this->web3Enabled();
    $import_url = $this->groupTabUrl('localnodes_import.landing');
    $fund_url = $web3 ? $this->fundTabUrl() : NULL;

    return [
      '#theme' => 'localnodes_guide',
      '#show_fund' => $web3,
      '#show_fees' => $web3,
      '#group_link' => $this->linkOrText($this->t('one group'), $this->groupListUrl()),
      '#import_link' => $this->linkOrText($this->t('Import tab'), $import_url),
      '#fund_link' => $this->linkOrText($this->t('Open the Fund tab'), $fund_url),
      '#attached' => ['library' => ['localnodes_platform/guide']],
      '#cache' => [
        // The links depend on who is looking and on which group is first.
        'contexts' => ['user.permissions', 'user.group_permissions'],
        'tags' => ['group_list'],
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
   * The group list, or NULL when the site has none.
   */
  protected function groupListUrl(): ?Url {
    return $this->routeExists('view.newest_groups.page_all_groups')
      ? Url::fromRoute('view.newest_groups.page_all_groups')
      : NULL;
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
   * The id of the community's first group (the lowest id), or NULL.
   *
   * 23-09's localnodes:organizer-welcome makes it and names it after the
   * community.
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
