<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Site\Settings;
use Drupal\localnodes_platform\OrganizerActivation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /localnodes/activate: the sign-up app hands the site over (23-35, B-1).
 *
 * Called by the sign-up app after the first payment, with this sign-up's own
 * token: `Authorization: Bearer <PROVISION_CALLBACK_TOKEN>` and the JSON body
 * {"provision_id": "<PROVISION_ID>"}. Both values reach Drupal through
 * settings.php ($settings['localnodes_provision']); nothing here reads the
 * process environment.
 *
 * The bearer is compared (hash_equals, the known value first) before the body
 * is read (the 23-11 "token first" rule). Replies are fixed JSON with
 * Cache-Control no-store, private, and never carry a token, an address or a
 * link:
 * - 200 {"activated":true}: the first call, and every later one (nothing
 *   changes after the first);
 * - 400 {"error":"bad_request"}: the body is not {"provision_id": <22 chars>};
 * - 401 {"error":"unauthorized"}: no bearer, a wrong one, or another
 *   sign-up's provision id;
 * - 404 {"error":"not_found"}: a site with no callback token (demo and manual
 *   instances) answers this to every request;
 * - 409 {"error":"not_ready"}: the install has not finished;
 * - 409 {"error":"busy"}: another activate call holds the activation lock
 *   (23-43); nothing changed, retry later;
 * - 503 {"error":"mail_failed"}: the welcome mail did not go out; retry.
 * A GET is refused by the router (405, methods [POST]).
 */
final class ActivationController implements ContainerInjectionInterface {

  /**
   * A sign-up's provision id: 22 base64url characters.
   */
  private const PROVISION_ID_PATTERN = '/^[A-Za-z0-9_-]{22}$/';

  /**
   * The reply for each result of OrganizerActivation::activate().
   */
  private const RESULTS = [
    'activated' => [200, ['activated' => TRUE]],
    'already' => [200, ['activated' => TRUE]],
    'not_ready' => [409, ['error' => 'not_ready']],
    'busy' => [409, ['error' => 'busy']],
    'mail_failed' => [503, ['error' => 'mail_failed']],
  ];

  public function __construct(
    private readonly OrganizerActivation $activation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('localnodes_platform.organizer_activation'));
  }

  /**
   * Checks the bearer and the body, then activates.
   */
  public function activate(Request $request): JsonResponse {
    $provision = Settings::get('localnodes_provision', []);
    $token = is_array($provision) ? (string) ($provision['callback_token'] ?? '') : '';
    $provision_id = is_array($provision) ? (string) ($provision['id'] ?? '') : '';
    if ($token === '' || $provision_id === '') {
      return $this->reply(404, ['error' => 'not_found']);
    }

    // The token first, before anything else of the request is read.
    $header = (string) $request->headers->get('Authorization', '');
    if (!str_starts_with($header, 'Bearer ') || !hash_equals($token, substr($header, 7))) {
      return $this->reply(401, ['error' => 'unauthorized']);
    }

    try {
      $body = json_decode((string) $request->getContent(), TRUE, 4, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->reply(400, ['error' => 'bad_request']);
    }
    if (!is_array($body) || array_is_list($body) || !isset($body['provision_id']) || !is_string($body['provision_id'])
      || !preg_match(self::PROVISION_ID_PATTERN, $body['provision_id'])) {
      return $this->reply(400, ['error' => 'bad_request']);
    }
    // Another sign-up's id gets the same reply as a wrong token.
    if (!hash_equals($provision_id, $body['provision_id'])) {
      return $this->reply(401, ['error' => 'unauthorized']);
    }

    [$status, $data] = self::RESULTS[$this->activation->activate()];
    return $this->reply($status, $data);
  }

  /**
   * A fixed JSON reply that no cache keeps.
   */
  private function reply(int $status, array $data): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
