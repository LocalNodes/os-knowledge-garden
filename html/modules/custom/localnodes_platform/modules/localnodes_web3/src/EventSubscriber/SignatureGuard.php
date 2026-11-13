<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\EventSubscriber;

use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\localnodes_web3\Controller\SignatureProofController;
use Drupal\localnodes_web3\ProofRoutes;
use Drupal\localnodes_web3\SignatureSession;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;

/**
 * Refuses changing requests to marked routes unless the session is proven.
 *
 * A route asks for it with the option SignatureSession::ROUTE_OPTION: TRUE.
 * A GET or HEAD passes, so the page can still show and offer the proof step;
 * anything else from a session that is not proven gets a 403, never cached:
 * {"error": "signature_proof_required"}, or, for a plain browser form post
 * (JavaScript off), a page in words with a link back to the form.
 *
 * The method is the one the client really sent. Symfony lets a POST name
 * another method in X-HTTP-Method-Override; Drupal then routes it as that
 * method, but a form on the page still reads the posted values and runs its
 * submit handlers. So a POST is a POST here, whatever it claims to be.
 *
 * A route whose GET changes something also carries
 * SignatureSession::READ_OPTION: TRUE, and then a GET or HEAD needs the
 * proof too (see ProofRoutes::PROOF_ON_READ).
 *
 * Group's own relationship pages (entity.group_content.*) serve every
 * relation type, members included, so they are not marked; a request there,
 * or to any page handed a relationship, needs the proof when the relation
 * type comes from one of the fund and vote modules (unlinking a group fund
 * through the generic delete form, for example).
 *
 * Runs after routing and route access (the router listens at 32).
 */
final class SignatureGuard implements EventSubscriberInterface {

  public function __construct(
    private readonly SignatureSession $sessions,
    private readonly AccountInterface $currentUser,
    private readonly ?PluginManagerInterface $relationTypes = NULL,
    private readonly ?BareHtmlPageRendererInterface $pages = NULL,
    private readonly ?RouteProviderInterface $routes = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Answers 403 for a changing request that needs proof and lacks it.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if (!$this->needsProof($request)) {
      return;
    }
    if (in_array($request->getRealMethod(), ['GET', 'HEAD'], TRUE) && !self::readsNeedProof($request)) {
      return;
    }
    if ($this->sessions->isProven($this->currentUser)) {
      return;
    }
    $response = $this->pages !== NULL && self::wantsPage($request)
      ? $this->page($request)
      : new JsonResponse(['error' => 'signature_proof_required'], 403);
    $response->setStatusCode(403);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $response->headers->set('Pragma', 'no-cache');
    $event->setResponse($response);
  }

  /**
   * Whether even a GET or HEAD to this route needs a proven session.
   */
  private static function readsNeedProof(Request $request): bool {
    $route = $request->attributes->get('_route_object');
    return $route instanceof Route && $route->getOption(SignatureSession::READ_OPTION) === TRUE;
  }

  /**
   * Whether this is a plain browser form post that wants a page back.
   *
   * Only when HTML is asked for by name, ahead of any JSON type. fetch() sends
   * "*\/*" and jQuery marks itself, and both keep the JSON answer the page's
   * script reads; so do Drupal's AJAX calls and JSON bodies.
   */
  public static function wantsPage(Request $request): bool {
    if ($request->isXmlHttpRequest() || $request->query->has('_wrapper_format') || $request->request->has('_wrapper_format')) {
      return FALSE;
    }
    $format = $request->query->get('_format');
    if ($format !== NULL && $format !== 'html') {
      return FALSE;
    }
    if (str_contains(strtolower((string) $request->headers->get('Content-Type')), 'json')) {
      return FALSE;
    }
    foreach ($request->getAcceptableContentTypes() as $type) {
      if ($type === 'text/html' || $type === 'application/xhtml+xml') {
        return TRUE;
      }
      if (str_contains($type, 'json')) {
        return FALSE;
      }
    }
    return FALSE;
  }

  /**
   * The page in words: the panel's own sentence and a link back.
   */
  private function page(Request $request): Response {
    try {
      $canProve = $this->sessions->canProve($this->currentUser);
    }
    catch (\Throwable) {
      $canProve = FALSE;
    }
    $wallet = $canProve || $this->routes === NULL ? NULL : SignatureProofController::walletUrl($this->routes, $this->currentUser);
    $content = [
      'sentence' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $canProve
          ? t('Confirm it is you to continue. This costs nothing.')
          : t('This account has no wallet yet, so it cannot confirm this step.'),
      ],
      'how' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => t('Your wallet confirms it on the page, so this step needs JavaScript turned on in your browser.'),
        '#access' => $canProve,
      ],
      'links' => [
        '#theme' => 'item_list',
        '#items' => array_filter([
          $wallet === NULL ? NULL : self::link($wallet, (string) t('Add a wallet to this account')),
          self::link(self::backPath($request), (string) t('Back to where you were')),
        ]),
      ],
    ];
    $response = $this->pages->renderBarePage($content, t('Confirm your sign-in'), 'maintenance_page');
    $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
    return $response;
  }

  /**
   * A link to a path on this site; the path is escaped as an attribute.
   */
  private static function link(string $path, string $text): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'a',
      '#attributes' => ['href' => $path],
      '#value' => $text,
    ];
  }

  /**
   * Where the member was: a same-site referrer, or else this form's own page.
   */
  public static function backPath(Request $request): string {
    $referer = (string) $request->headers->get('Referer');
    if ($referer !== '') {
      $parts = parse_url($referer);
      $origin = is_array($parts) && isset($parts['scheme'], $parts['host'])
        ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
        : NULL;
      if ($origin !== NULL && strcasecmp($origin, $request->getSchemeAndHttpHost()) === 0) {
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (self::isLocalPath($path)) {
          return $path;
        }
      }
    }
    $path = $request->getRequestUri();
    return self::isLocalPath($path) ? $path : '/';
  }

  /**
   * A path on this site: one leading slash, never "//" or "/\".
   */
  private static function isLocalPath(string $path): bool {
    return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_starts_with($path, '/\\');
  }

  /**
   * Whether a changing request to this page needs a proven session.
   */
  public function needsProof(Request $request): bool {
    $route = $request->attributes->get('_route_object');
    if (!$route instanceof Route) {
      return FALSE;
    }
    if ($route->getOption(SignatureSession::ROUTE_OPTION) === TRUE) {
      return TRUE;
    }
    if ($this->relationTypes === NULL) {
      return FALSE;
    }
    // Any page handed a relationship (group's own pages, and others that take
    // one), or group's add and create pages for a relation type.
    $plugin = NULL;
    foreach ($request->attributes->all() as $value) {
      if ($value instanceof GroupRelationshipInterface) {
        $plugin = $value->getPluginId();
        break;
      }
    }
    if ($plugin === NULL && str_starts_with((string) $request->attributes->get('_route'), 'entity.group_content.')) {
      $plugin = $request->attributes->get('plugin_id');
    }
    if (!is_string($plugin) || $plugin === '') {
      return FALSE;
    }
    $definition = $this->relationTypes->getDefinition($plugin, FALSE);
    // A relation type nobody knows is treated as covered: closed, not open.
    if ($definition === NULL) {
      return TRUE;
    }
    $provider = is_object($definition) && method_exists($definition, 'getProvider') ? $definition->getProvider() : ($definition['provider'] ?? '');
    return in_array($provider, ProofRoutes::MODULES, TRUE);
  }

}
