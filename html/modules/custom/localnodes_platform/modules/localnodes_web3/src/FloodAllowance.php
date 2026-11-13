<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks a sign-in mail's flood limits and takes one try, in one step.
 *
 * Core's flood checks a count, then registers a try, as two steps. Two
 * requests at once can both see the last free try and both send. Here each
 * limit key is locked while it is checked and registered, so two requests
 * for the same key take turns, and the second sees the first's try.
 *
 * Several limits are checked together: either every one has a free try and
 * every one is registered, or none is registered and nothing may be sent.
 * A request that cannot get a key's lock in time is refused too.
 */
final class FloodAllowance {

  /**
   * Seconds to wait for another request's turn before refusing.
   *
   * Another request holds a key only for one flood read and one write.
   */
  public const LOCK_WAIT = 2;

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly LockBackendInterface $lock,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Takes one try under every limit, or none.
   *
   * @param array<string|int, array{0: string, 1: int, 2: int, 3?: string|null}> $limits
   *   Each limit: the flood event, the tries allowed, the window in seconds,
   *   and what is counted (NULL or left out: the visitor's IP address).
   *
   * @return string|int|null
   *   NULL when a try was taken under every limit. Otherwise the key of the
   *   limit that refused (the first one, when a lock could not be had), and
   *   nothing was registered.
   */
  public function reserve(array $limits): string|int|null {
    $ip = (string) $this->requestStack->getCurrentRequest()?->getClientIp();
    $keyed = [];
    foreach ($limits as $key => $limit) {
      $limit[3] = $limit[3] ?? $ip;
      $keyed[$key] = $limit;
    }
    // One lock per event and identifier, taken in a fixed order so two
    // requests that share keys never each hold what the other waits for.
    $names = [];
    foreach ($keyed as $key => [$event, , , $identifier]) {
      $names[$key] = 'localnodes_web3.flood:' . hash('sha256', $event . "\0" . $identifier);
    }
    $order = array_unique($names);
    sort($order);
    $held = [];
    try {
      foreach ($order as $name) {
        if (!$this->take($name)) {
          return array_key_first($keyed);
        }
        $held[] = $name;
      }
      foreach ($keyed as $key => [$event, $allowed, $window, $identifier]) {
        if (!$this->flood->isAllowed($event, $allowed, $window, $identifier)) {
          return $key;
        }
      }
      foreach ($keyed as [$event, , $window, $identifier]) {
        $this->flood->register($event, $window, $identifier);
      }
      return NULL;
    }
    finally {
      foreach ($held as $name) {
        $this->lock->release($name);
      }
    }
  }

  /**
   * The identifier for a recipient address: the same however it was typed.
   *
   * Hashed, so the flood table holds no address (its column is short ASCII).
   */
  public static function recipient(string $email): string {
    return hash('sha256', mb_strtolower(trim($email)));
  }

  /**
   * Gets a lock, waiting once for another request to finish with it.
   */
  private function take(string $name): bool {
    if ($this->lock->acquire($name, 10.0)) {
      return TRUE;
    }
    $this->lock->wait($name, self::LOCK_WAIT);
    return $this->lock->acquire($name, 10.0);
  }

}
