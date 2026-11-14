<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;

/**
 * The community-wide group's tour, and the lines around a first landing.
 *
 * One tour (tour.tour.localnodes_group) is shipped for every group page. Its
 * tips carry placeholders: {community} for the group's name, {kind} for the
 * kind of community ("co-op"), {units} for its smaller groups ("houses") and
 * {groups_url} for the groups list. They are filled here for the community-wide
 * group only (see FirstLanding::communityGroupIn()); on any other group page
 * the tour has no tips, so nothing is shown.
 *
 * The tour starts only when the page address has ?tour=1: the first landing
 * adds it, and so does the replay link on the help page. Opening the tour
 * never joins anyone.
 */
final class GroupTour {

  use StringTranslationTrait;

  /**
   * The shipped tour's id.
   */
  public const TOUR_ID = 'localnodes_group';

  /**
   * The nouns for each community template, by template id.
   *
   * Kind of community, its smaller groups, and one of them.
   */
  public const NOUNS = [
    'housing_coop' => ['kind' => 'co-op', 'units' => 'houses', 'unit' => 'house'],
    'market' => ['kind' => 'market', 'units' => 'vendors', 'unit' => 'vendor'],
    'neighbourhood' => ['kind' => 'neighbourhood', 'units' => 'blocks', 'unit' => 'block'],
  ];

  /**
   * The nouns for a community without a known template.
   */
  public const DEFAULT_NOUNS = ['kind' => 'community', 'units' => 'groups', 'unit' => 'group'];

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Fills the tour's tips for this group, or empties them.
   *
   * @param \Drupal\tour\TipPluginInterface[] $tips
   *   The tour's tips.
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The group whose page is showing, or NULL.
   */
  public function alterTips(array &$tips, ?GroupInterface $group): void {
    $community = $this->communityGroup();
    if ($group === NULL || $community === NULL || (int) $group->id() !== (int) $community->id()) {
      $tips = [];
      return;
    }
    $nouns = $this->nouns();
    $replace = [
      '{community}' => Html::escape((string) $group->label()),
      '{kind}' => $nouns['kind'],
      '{units}' => $nouns['units'],
      '{groups_url}' => Url::fromUserInput('/all-groups')->toString(),
    ];
    foreach ($tips as $tip) {
      $body = $tip->get('body');
      if (is_string($body)) {
        $tip->set('body', strtr($body, $replace));
      }
    }
  }

  /**
   * The line shown once on the first landing.
   */
  public function landingLine(int $groupId): ?TranslatableMarkup {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group instanceof GroupInterface) {
      return NULL;
    }
    return $this->t("You're in @community. Take a quick look around, then find your other groups.", [
      '@community' => $group->label(),
    ]);
  }

  /**
   * The line shown instead when the first landing is the invitations page.
   */
  public function invitationLine(): TranslatableMarkup {
    return $this->t('Your @unit invitation is here. Choose Accept, then Join if asked.', [
      '@unit' => $this->nouns()['unit'],
    ]);
  }

  /**
   * The tour's address for replay, or NULL.
   *
   * The community-wide group, only if this person may open it. What that
   * answer depends on (the group, and the access result's contexts and tags)
   * is added to $cache, whichever way it goes.
   */
  public function replayUrl(AccountInterface $account, RefinableCacheableDependencyInterface $cache): ?Url {
    $group = $this->communityGroup();
    if ($group === NULL) {
      return NULL;
    }
    $access = $group->access('view', $account, TRUE);
    $cache->addCacheableDependency($group)->addCacheableDependency($access);
    return $access->isAllowed() ? Url::fromRoute('entity.group.canonical', ['group' => $group->id()], ['query' => ['tour' => 1]]) : NULL;
  }

  /**
   * The community-wide group, or NULL unless exactly one exists.
   */
  private function communityGroup(): ?GroupInterface {
    $recorded = $this->state->get(FirstLanding::TEMPLATE_STATE);
    if ($recorded === NULL || !$this->entityTypeManager->hasDefinition('group')) {
      return NULL;
    }
    return FirstLanding::communityGroupIn($recorded, $this->entityTypeManager->getStorage('group'));
  }

  /**
   * The nouns for the recorded template.
   *
   * @return array{kind: string, units: string, unit: string}
   *   The nouns.
   */
  private function nouns(): array {
    $recorded = $this->state->get(FirstLanding::TEMPLATE_STATE);
    $id = is_array($recorded) ? ($recorded['id'] ?? NULL) : NULL;
    return self::NOUNS[$id] ?? self::DEFAULT_NOUNS;
  }

}
