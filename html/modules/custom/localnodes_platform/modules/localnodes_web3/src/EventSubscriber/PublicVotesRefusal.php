<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_web3\Votes\PublicVotes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Tells a person why a members-only group can't start or publish a vote.
 *
 * Only for the voting steps PublicVotes refuses, and only for someone who
 * can see the group; others get Drupal's own "access denied". A page for the
 * set-up and proposal pages, and the voting module's own refusal shape,
 * {"error": sentence}, for the scripts behind them.
 */
final class PublicVotesRefusal extends HttpExceptionSubscriberBase {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly PublicVotes $publicVotes,
    private readonly AccountInterface $currentUser,
    private readonly BareHtmlPageRendererInterface $pages,
    private readonly AttachmentsResponseProcessorInterface $attachments,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats(): array {
    return ['html', 'json'];
  }

  /**
   * {@inheritdoc}
   *
   * Ahead of the sign-in redirect and the site's own 403 page.
   */
  protected static function getPriority(): int {
    return 10;
  }

  /**
   * Answers a refused voting step of a members-only group in words.
   */
  public function on403(ExceptionEvent $event): void {
    $request = $event->getRequest();
    if (!in_array($request->attributes->get('_route'), PublicVotes::ROUTES, TRUE)) {
      return;
    }
    $group = $request->attributes->get('group');
    if (!$group instanceof GroupInterface || !PublicVotes::refuses($group)) {
      return;
    }
    if (!$group->access('view', $this->currentUser)) {
      return;
    }
    if ($request->getRequestFormat() === 'json') {
      $this->finish($event, new JsonResponse(['error' => PublicVotes::SENTENCE], 403));
      return;
    }
    $content = [
      'sentence' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '#value' => t(PublicVotes::SENTENCE),
      ],
      'back' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#attributes' => ['href' => Url::fromRoute('safe_governance_group.votes', ['group' => $group->id()])->toString()],
        '#value' => t('Back to votes'),
      ],
    ];
    $event->setResponse($this->page($content, t('Votes'), 403));
  }

  /**
   * The bare refusal page, with a status, never cached.
   *
   * The page's assets are put in now and it is answered as a plain
   * response: core keeps a plain response's own Cache-Control, while it
   * rewrites a cacheable page's.
   *
   * @param array<string, mixed> $content
   *   The page's content.
   * @param string|\Stringable $title
   *   The page's title.
   * @param int $status
   *   The status code.
   */
  public function page(array $content, string|\Stringable $title, int $status): Response {
    $page = $this->attachments->processAttachments($this->pages->renderBarePage($content, $title, 'maintenance_page'));
    $response = new Response((string) $page->getContent(), $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    return $response;
  }

  /**
   * Sets the answer: 403, never cached.
   */
  private function finish(ExceptionEvent $event, Response $response): void {
    $response->setStatusCode(403);
    $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
    $event->setResponse($response);
  }

}
