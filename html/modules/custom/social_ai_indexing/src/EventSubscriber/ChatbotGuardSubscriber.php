<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bounds chatbot spend: a length limit and flood caps (D-27; Tom, 2026-11-05).
 *
 * /api/deepchat (ai_chatbot.api) is open to anonymous visitors and members,
 * and every message DeepChatApi::api() processes starts a paid agent loop.
 * This runs on KernelEvents::REQUEST at priority 25, after routing (32) and
 * the access check (30), and refuses before the controller:
 * - a latest user message over max_message_chars: 413;
 * - over the per-member limit, the shared anonymous bucket (all visitors
 *   share uid 0, so this bounds anonymous spend site-wide) or the per-IP
 *   limit: 429 with Retry-After.
 * Every message that passes counts once against the member-or-anonymous
 * event and the IP event. The IP leg is advisory: production settings.php
 * trusts every proxy address for X-Forwarded-For (as for the overview).
 *
 * ai_usage_limits can't count per user or per IP, so this is flood control;
 * 22-33 adds the site-wide token ceiling and, from drupal/ai 1.4, the
 * InputLengthLimit guardrail as a second layer.
 *
 * Log records carry a reason code and the limit name, never message text.
 */
final class ChatbotGuardSubscriber implements EventSubscriberInterface {

  /**
   * The chatbot's API route.
   */
  public const ROUTE = 'ai_chatbot.api';

  /**
   * Flood events.
   */
  public const USER_EVENT = 'social_ai_indexing.chatbot_user';
  public const ANONYMOUS_EVENT = 'social_ai_indexing.chatbot_anonymous';
  public const IP_EVENT = 'social_ai_indexing.chatbot_ip';

  /**
   * Config object with the limits.
   */
  public const CONFIG = 'social_ai_indexing.chatbot_limits';

  /**
   * Built-in limits, for instances that enabled the module before the config
   * object shipped: redeploys run updatedb + cr only, so they never get it.
   */
  public const DEFAULTS = [
    'max_message_chars' => 2000,
    'user_limit' => 60,
    'anonymous_limit' => 200,
    'ip_limit' => 120,
    'window' => 3600,
  ];

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 25]];
  }

  /**
   * Refuses an oversized or over-limit chatbot message.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$event->isMainRequest() || !$request->isMethod('POST') || $request->attributes->get('_route') !== self::ROUTE) {
      return;
    }
    $text = self::latestUserText($request->getContent());
    // No user message, or a body DeepChatApi can't parse: its own 400/422.
    if ($text === NULL) {
      return;
    }

    $max = $this->setting('max_message_chars');
    if (mb_strlen($text) > $max) {
      $this->logger->notice('chatbot_message_too_long limit=@limit', ['@limit' => 'max_message_chars']);
      $event->setResponse(new JsonResponse([
        'error' => sprintf('That message is too long. Keep it under %s characters.', number_format($max)),
      ], 413));
      return;
    }

    $window = $this->setting('window');
    $ip = (string) $request->getClientIp();
    if ($this->currentUser->isAnonymous()) {
      $account_event = self::ANONYMOUS_EVENT;
      $account_id = 'anonymous';
      $account_limit = 'anonymous_limit';
    }
    else {
      $account_event = self::USER_EVENT;
      $account_id = 'uid:' . $this->currentUser->id();
      $account_limit = 'user_limit';
    }

    $tripped = NULL;
    if (!$this->flood->isAllowed($account_event, $this->setting($account_limit), $window, $account_id)) {
      $tripped = $account_limit;
    }
    elseif (!$this->flood->isAllowed(self::IP_EVENT, $this->setting('ip_limit'), $window, $ip)) {
      $tripped = 'ip_limit';
    }
    if ($tripped !== NULL) {
      $this->logger->notice('chatbot_rate_limited limit=@limit', ['@limit' => $tripped]);
      $event->setResponse(new JsonResponse(
        ['error' => "You've sent a lot of messages in a short time. Wait a little while, then ask again."],
        429,
        ['Retry-After' => (string) $window],
      ));
      return;
    }

    $this->flood->register($account_event, $window, $account_id);
    $this->flood->register(self::IP_EVENT, $window, $ip);
  }

  /**
   * The latest user text, the way DeepChatApi::api() picks it, or NULL.
   */
  private static function latestUserText(string $content): ?string {
    $data = Json::decode($content);
    if (!is_array($data) || !isset($data['messages']) || !is_array($data['messages'])) {
      return NULL;
    }
    $latest = NULL;
    foreach ($data['messages'] as $message) {
      if (is_array($message) && isset($message['role'], $message['text']) && $message['role'] === 'user') {
        $latest = $message['text'];
      }
    }
    return is_string($latest) ? $latest : NULL;
  }

  /**
   * A limit from config, or its built-in default.
   */
  private function setting(string $key): int {
    $value = $this->configFactory->get(self::CONFIG)->get($key);
    return is_numeric($value) && (int) $value > 0 ? (int) $value : self::DEFAULTS[$key];
  }

}
