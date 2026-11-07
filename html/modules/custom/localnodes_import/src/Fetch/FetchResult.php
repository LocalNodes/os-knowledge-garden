<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

/**
 * What came back from one SafeFetcher::get() call.
 *
 * Either ok (body, media type and the address it finally came from) or
 * failed with one machine reason code. The reason codes map one to one to
 * the UI-SPEC "Failed: ..." copy rows; bot_challenge has its own row
 * ("This site blocks automatic imports", added by 22-18).
 */
final readonly class FetchResult {

  /**
   * The address is not one we fetch.
   *
   * Private, loopback, wrong scheme, port or credentials; checked on every
   * hop and after DNS resolution.
   */
  public const BLOCKED_ADDRESS = 'blocked_address';

  /**
   * The site didn't answer usefully.
   *
   * It timed out, refused the connection, answered 5xx or redirected more
   * than SafeFetcher::MAX_REDIRECTS times.
   */
  public const NO_RESPONSE = 'no_response';

  /**
   * The body (declared or downloaded, after decoding) is over the cap.
   */
  public const TOO_BIG = 'too_big';

  /**
   * The response is not the content type that was asked for.
   */
  public const NOT_A_PAGE = 'not_a_page';

  /**
   * HTTP 404 or 410.
   */
  public const NOT_FOUND = 'not_found';

  /**
   * The site answered with a bot check (D-23). Reported, never retried.
   */
  public const BOT_CHALLENGE = 'bot_challenge';

  /**
   * Anything else (other 4xx, a redirect with no Location, a broken body).
   */
  public const UNKNOWN = 'unknown';

  /**
   * Every reason code, for callers that validate stored values.
   */
  public const REASONS = [
    self::BLOCKED_ADDRESS,
    self::NO_RESPONSE,
    self::TOO_BIG,
    self::NOT_A_PAGE,
    self::NOT_FOUND,
    self::BOT_CHALLENGE,
    self::UNKNOWN,
  ];

  /**
   * Builds a result. Use the named constructors.
   */
  private function __construct(
    public bool $ok,
    public ?string $body = NULL,
    public ?string $contentType = NULL,
    public ?string $finalUrl = NULL,
    public ?string $reason = NULL,
  ) {}

  /**
   * A fetched body.
   *
   * @param string $body
   *   The decoded response body.
   * @param string $contentType
   *   The lower-case media type, without parameters (text/html, ...).
   * @param string $finalUrl
   *   The address the body came from, after any redirects.
   */
  public static function ok(string $body, string $contentType, string $finalUrl): self {
    return new self(TRUE, $body, $contentType, $finalUrl);
  }

  /**
   * A failure with one of the reason codes.
   */
  public static function failed(string $reason): self {
    if (!in_array($reason, self::REASONS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown fetch failure reason "%s".', $reason));
    }
    return new self(FALSE, reason: $reason);
  }

}
