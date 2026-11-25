<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_web3\Votes\MembersOnlyWait;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Publishing a vote, under the fund's lock.
 *
 * In front of the voting module's publish route (PublicVotesRoutes). Under
 * the fund's lock, the one a switch to members-only takes, the visibility
 * of every group of the fund is read fresh, the same read the voting
 * set-up makes, and the lock is held through the voting module's publish:
 * a vote is not published from a fund that a members-only group also
 * uses, and nothing reaches the voting service. The sentence is shown
 * when the group itself is members-only, and to people who can see a
 * members-only group of the fund; others get the publish route's own
 * answer when it can't publish now. Votes on proposals already published
 * are not behind this. The voting module's controller is final: it is built through its own
 * create() and called through its public publish(), never extended.
 */
final class ProposalPublishLock implements ContainerInjectionInterface {

  /**
   * The route in front of which this controller sits.
   */
  public const ROUTE = 'safe_governance_group.proposal_publish';

  /**
   * The publish route's own answer when it can't publish now.
   *
   * ProposalPublisher::UNAVAILABLE, which the voting module's controller
   * answers with 503; propose.js shows its own words.
   */
  private const BUSY = ['error' => 'unavailable'];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly MembersOnlyWait $wait,
    private readonly ClassResolverInterface $classResolver,
    private readonly RouteProviderInterface $routeProvider,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_web3.members_only_wait'),
      $container->get('class_resolver'),
      $container->get('router.route_provider'),
      $container->get('current_user'),
    );
  }

  /**
   * POST the member-signed proposal.
   */
  public function publish(GroupInterface $group, Request $request): Response {
    $definition = $this->routeProvider->getRouteByName(self::ROUTE)->getDefault(SetupApprovalLock::INNER);
    if (!is_string($definition) || !str_contains($definition, '::')) {
      throw new \LogicException('The publish route keeps no voting controller.');
    }
    [$class, $method] = explode('::', ltrim($definition, '\\'), 2);
    $inner = [$this->classResolver->getInstanceFromDefinition($class), $method];
    if (!is_callable($inner)) {
      throw new \LogicException(sprintf('%s is not callable.', $definition));
    }
    $fund_id = $this->wait->fundId($group);
    if ($fund_id === NULL) {
      return $inner($group, $request);
    }
    if (!$this->wait->lockFund($fund_id)) {
      return PublicVotes::notStored(new JsonResponse(self::BUSY, 503));
    }
    try {
      if ($this->wait->membersOnlyNow($group->id())) {
        return PublicVotes::jsonRefusal();
      }
      $groups = $this->wait->membersOnlyGroups($fund_id);
      if ($groups !== []) {
        foreach ($groups as $other) {
          if ($other->access('view', $this->currentUser)) {
            return PublicVotes::jsonRefusal();
          }
        }
        return PublicVotes::notStored(new JsonResponse(self::BUSY, 503));
      }
      return $inner($group, $request);
    }
    finally {
      $this->wait->unlockFund($fund_id);
    }
  }

}
