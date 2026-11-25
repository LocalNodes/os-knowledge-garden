<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\localnodes_web3\Votes\MembersOnlyWait;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Entity\SafeTransactionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sending a fund payment, held for a members-only group's voting.
 *
 * In front of the fund payment's execute route (PublicVotesRoutes). The
 * voting set-up's payment and a role batch are held while a group of
 * their fund is members-only (PublicVotesQueueHold), and are not sent
 * from here either. For one of those payments, a request that asks to send
 * ({"check": true}, which the browser asks before its wallet sends) or has
 * the community's sponsor send it ({"sponsored": true}) is decided under
 * the fund's lock, from a fresh read of the fund's groups: while one is
 * members-only it is refused and nothing is sent. The sentence is shown to
 * people who can see one of those groups; others, an approver who is not
 * a member included, get the payment module's own refusal of a check.
 * Every
 * refusal and busy answer is never stored.
 * Otherwise, and for every other payment or request, the payment module's
 * own controller answers, unchanged. A wallet's report of a send it
 * already made is never refused: it records what happened.
 */
final class FundSendLock implements ContainerInjectionInterface {

  /**
   * The route in front of which this controller sits.
   */
  public const ROUTE = 'safe_smart_accounts.api_transaction_execute';

  /**
   * The payment module's own answer when another send of the fund is open.
   *
   * SafeTransactionController::NONCE_BUSY, answered with 409 (the browser
   * stops and shows it).
   */
  private const BUSY = ['error' => "Another payment from this fund is still being sent. Try again once it's done."];

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
   * POST: send a fund payment, or ask whether it may be sent now.
   */
  public function execute(SafeAccountInterface $safe_account, SafeTransactionInterface $safe_transaction, Request $request): Response {
    $inner = $this->inner();
    $body = json_decode((string) $request->getContent(), TRUE);
    $sends = is_array($body) && (($body['sponsored'] ?? NULL) === TRUE || ($body['check'] ?? NULL) === TRUE);
    $fund_id = (int) $safe_account->id();
    if (!$sends
      || (int) $safe_transaction->getSafeAccount()?->id() !== $fund_id
      || !$this->wait->isVotingPayment((int) $safe_transaction->id(), $fund_id)) {
      return $inner($safe_account, $safe_transaction, $request);
    }
    if (!$this->wait->lockFund($fund_id)) {
      return PublicVotes::notStored(new JsonResponse(self::BUSY, 409));
    }
    try {
      $groups = $this->wait->membersOnlyGroups($fund_id);
      if ($groups !== []) {
        foreach ($groups as $group) {
          if ($group->access('view', $this->currentUser)) {
            return PublicVotes::jsonRefusal();
          }
        }
        return PublicVotes::notStored(new JsonResponse(self::BUSY, 409));
      }
      return $inner($safe_account, $safe_transaction, $request);
    }
    finally {
      $this->wait->unlockFund($fund_id);
    }
  }

  /**
   * The payment module's controller method, from the route's default.
   */
  private function inner(): callable {
    $definition = $this->routeProvider->getRouteByName(self::ROUTE)->getDefault(SetupApprovalLock::INNER);
    if (!is_string($definition) || !str_contains($definition, '::')) {
      throw new \LogicException('The execute route keeps no payment controller.');
    }
    [$class, $method] = explode('::', ltrim($definition, '\\'), 2);
    $callable = [$this->classResolver->getInstanceFromDefinition($class), $method];
    if (!is_callable($callable)) {
      throw new \LogicException(sprintf('%s is not callable.', $definition));
    }
    return $callable;
  }

}
