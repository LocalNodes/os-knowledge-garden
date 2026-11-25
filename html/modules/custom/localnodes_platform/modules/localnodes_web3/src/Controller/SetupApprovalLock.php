<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_web3\Votes\MembersOnlyWait;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The voting set-up's approval step, under the fund's lock.
 *
 * In front of the voting module's set-up approval routes
 * (PublicVotesRoutes): messages, the step that stores the set-up's fund
 * payment, and approve and restart with it. Under the fund's lock, the one
 * a switch to members-only takes, the group's visibility is read fresh: a
 * fund whose group has turned members-only after the route's check is
 * refused with the sentence, and nothing is prepared or stored. Otherwise
 * the voting module's own controller answers, unchanged. The voting
 * module's controller is final: it is built through its own create() and
 * called through its public methods, never extended.
 */
final class SetupApprovalLock implements ContainerInjectionInterface {

  /**
   * The routes in front of which this controller sits, to its methods.
   */
  public const ROUTES = [
    'safe_governance_group.setup_messages' => 'messages',
    'safe_governance_group.setup_approve' => 'approve',
    'safe_governance_group.setup_restart' => 'restart',
  ];

  /**
   * The route default that keeps the voting module's controller.
   */
  public const INNER = '_localnodes_web3_inner';

  /**
   * The voting module's own busy answers, by method.
   */
  private const BUSY = [
    'messages' => ['error' => "We couldn't prepare the approvals right now.", 'code' => 'busy'],
    'restart' => ['error' => "We couldn't prepare the approvals right now.", 'code' => 'busy'],
    'approve' => ['error' => "We couldn't check this approval right now."],
  ];

  /**
   * Constructs the controller.
   */
  public function __construct(
    private readonly MembersOnlyWait $wait,
    private readonly ClassResolverInterface $classResolver,
    private readonly RouteProviderInterface $routeProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('localnodes_web3.members_only_wait'),
      $container->get('class_resolver'),
      $container->get('router.route_provider'),
    );
  }

  /**
   * GET the set-up's approvals, preparing them on the first call.
   */
  public function messages(GroupInterface $group, Request $request): Response {
    return $this->run('messages', $group, [$group]);
  }

  /**
   * POST one approval of the set-up.
   */
  public function approve(GroupInterface $group, Request $request): Response {
    return $this->run('approve', $group, [$group, $request]);
  }

  /**
   * POST: start the set-up's approvals again.
   */
  public function restart(GroupInterface $group, Request $request): Response {
    return $this->run('restart', $group, [$group]);
  }

  /**
   * Runs the voting module's method under the fund's lock, read fresh.
   *
   * @param string $method
   *   The method, a value of ROUTES.
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group from the route.
   * @param array<int, mixed> $args
   *   The voting module's method's arguments.
   */
  private function run(string $method, GroupInterface $group, array $args): Response {
    $inner = $this->inner($method);
    $fund_id = $this->wait->fundId($group);
    if ($fund_id === NULL) {
      return $inner(...$args);
    }
    if (!$this->wait->lockFund($fund_id)) {
      return PublicVotes::notStored(new JsonResponse(self::BUSY[$method], 503));
    }
    try {
      if ($this->wait->fundMembersOnlyNow($fund_id)) {
        return PublicVotes::jsonRefusal();
      }
      return $inner(...$args);
    }
    finally {
      $this->wait->unlockFund($fund_id);
    }
  }

  /**
   * The voting module's controller method, from the route's default.
   *
   * Read through the route provider, so a direct call needs no request
   * attribute.
   */
  private function inner(string $method): callable {
    $name = array_search($method, self::ROUTES, TRUE);
    $definition = is_string($name) ? $this->routeProvider->getRouteByName($name)->getDefault(self::INNER) : NULL;
    if (!is_string($definition) || !str_contains($definition, '::')) {
      throw new \LogicException(sprintf('The route of %s keeps no voting controller.', $method));
    }
    [$class, $inner_method] = explode('::', ltrim($definition, '\\'), 2);
    $callable = [$this->classResolver->getInstanceFromDefinition($class), $inner_method];
    if (!is_callable($callable)) {
      throw new \LogicException(sprintf('%s is not callable.', $definition));
    }
    return $callable;
  }

}
