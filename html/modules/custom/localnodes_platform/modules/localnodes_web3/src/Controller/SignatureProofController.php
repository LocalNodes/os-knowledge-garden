<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\localnodes_web3\SignatureSession;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The calls a page makes to prove this session with the member's wallet.
 *
 * 0. GET status: {"proven", "canProve", "addUrl"}, so a page can tell which
 *    step to offer without asking for a message to sign. addUrl is the
 *    member's own wallet page, where a wallet is added to the account, or NULL
 *    while the site has no such page.
 * 1. POST challenge: {"message", "expectedAddress"}; the wallet signs the
 *    message exactly as given (personal_sign).
 * 2. POST prove {"message", "signature"}: {"proven": true}.
 *
 * Fixed answers, all JSON and never cached:
 * - 200 {"proven": true} when the session already is, from either call.
 * - 409 {"error": "wallet_not_attached"}: the account has no wallet address
 *   to prove with; attach one first.
 * - 429 {"error": "too_many_attempts"}.
 * - 403 {"error": "not_proven"}: anything else, including a bad signature.
 */
final class SignatureProofController extends ControllerBase {

  /**
   * The sign-in module's wallet page, /user/{user}/wallet.
   */
  public const ADD_WALLET_ROUTE = 'siwe_login.wallet';

  public function __construct(
    private readonly SignatureSession $sessions,
    private readonly FloodInterface $flood,
    private readonly RouteProviderInterface $routes,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get('localnodes_web3.signature_session'), $container->get('flood'), $container->get('router.route_provider'));
  }

  /**
   * Says whether this session is proven and whether it can be.
   */
  public function status(): JsonResponse {
    $account = $this->currentUser();
    try {
      $proven = $this->sessions->isProven($account);
      $canProve = $proven || $this->sessions->canProve($account);
    }
    catch (\Throwable) {
      $proven = $canProve = FALSE;
    }
    return $this->answer(['proven' => $proven, 'canProve' => $canProve, 'addUrl' => $canProve ? NULL : self::walletUrl($this->routes, $account)], 200);
  }

  /**
   * This member's wallet page, or NULL while the site has none.
   */
  public static function walletUrl(RouteProviderInterface $routes, AccountInterface $account): ?string {
    try {
      if ($account->isAuthenticated() && count($routes->getRoutesByNames([self::ADD_WALLET_ROUTE])) === 1) {
        return Url::fromRoute(self::ADD_WALLET_ROUTE, ['user' => $account->id()])->toString();
      }
    }
    catch (\Throwable) {
    }
    return NULL;
  }

  /**
   * Issues the message to sign.
   */
  public function challenge(Request $request): JsonResponse {
    $account = $this->currentUser();
    $uid = (string) $account->id();
    try {
      if ($this->sessions->isProven($account)) {
        return $this->answer(['proven' => TRUE], 200);
      }
      if (!$this->sessions->canProve($account)) {
        return $this->answer(['error' => 'wallet_not_attached'], 409);
      }
      if (!$this->flood->isAllowed('localnodes_session_proof_challenge', 20, 3600, $uid)) {
        return $this->answer(['error' => 'too_many_attempts'], 429);
      }
      $this->flood->register('localnodes_session_proof_challenge', 3600, $uid);
      return $this->answer($this->sessions->challenge($account), 200);
    }
    catch (\Throwable) {
      return $this->answer(['error' => 'not_proven'], 403);
    }
  }

  /**
   * Checks the signed message.
   */
  public function prove(Request $request): JsonResponse {
    $account = $this->currentUser();
    $uid = (string) $account->id();
    try {
      if (!$this->flood->isAllowed('localnodes_session_proof_fail', 5, 3600, $uid)) {
        return $this->answer(['error' => 'too_many_attempts'], 429);
      }
      if ($request->getContentTypeFormat() === 'json') {
        $input = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
        $message = is_array($input) ? ($input['message'] ?? NULL) : NULL;
        $signature = is_array($input) ? ($input['signature'] ?? NULL) : NULL;
        if (is_string($message) && is_string($signature) && $this->sessions->prove($account, $message, $signature)) {
          return $this->answer(['proven' => TRUE], 200);
        }
      }
    }
    catch (\Throwable) {
    }
    $this->flood->register('localnodes_session_proof_fail', 3600, $uid);
    return $this->answer(['error' => 'not_proven'], 403);
  }

  /**
   * A JSON answer that is never cached.
   */
  private function answer(array $body, int $status): JsonResponse {
    return new JsonResponse($body, $status, [
      'Cache-Control' => 'private, no-store, max-age=0',
      'Pragma' => 'no-cache',
      'Referrer-Policy' => 'no-referrer',
    ]);
  }

}
