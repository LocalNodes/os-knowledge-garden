// The proposal publish answer as one fixed-format public line.
//
// A live run failed at the V5 publish: the site answered the /votes/publish POST with
// a refusal whose code has no copy of its own, so the browser showed the catch-all
// (safe_governance 1.0.0-alpha1 js/propose.js:181) and the public log could not say which code it
// was. The judge now prints
//
//   CAPTURE propose_publish <http status> <code> [<reason>]
//
// - <code> is one of PUBLISH_CODES (the site's own fixed reason codes:
//   ProposalPublisher, ProposalPublishController, the public-record notice), else `other`.
// - <reason> is one of PUBLISH_REASONS, else `other`. From safe_governance's refusal-key
//   release on, the site answers {error, reason} with `reason` a fixed key (ProposalPublisher's
//   check keys, ProposalHubRefusal's keys) on `refused`, `rejected` and `unavailable`, and a key
//   is printed as it is, for any code. A `rejected` answer without a key (alpha1 sends only
//   {error}) maps a string `reason` through SNAPSHOT_REFUSALS, else prints `other`. Nothing
//   outside the fixed lists reaches the log, and the voting service's text never does.
//
// The watcher follows only the publish request this page sends to the expected address and is
// removed when the step ends; printPublishCapture is the one place the line is written, and it
// checks every token against the fixed lists again before it writes.

/** The site's fixed refusal codes for the publish routes. */
export const PUBLISH_CODES = [
  'refused',
  'wrong_account',
  'not_proposer',
  'rate_limited',
  'hub',
  'rejected',
  'unavailable',
  'mismatch',
  'public_notice',
  'no_wallet',
] as const;

/**
 * Our keys for the voting service's proposal refusals (snapshot-sequencer src/ingestor.ts and
 * src/writer/proposal.ts, read 2026-11-16). Each string matches exactly, after trimming and
 * lower-casing; the three templated ones match their fixed beginning only.
 */
export const SNAPSHOT_REFUSALS: Readonly<Record<string, { exact?: readonly string[]; prefix?: readonly string[] }>> = {
  wrong_timestamp: { exact: ['wrong timestamp'] },
  invalid_signature: { exact: ['invalid signature', 'signature validation failed'] },
  wrong_domain: { exact: ['wrong domain'] },
  wrong_types: { exact: ['wrong types'] },
  wrong_format: { exact: ['wrong envelope format', 'wrong proposal format', 'invalid proposal content', 'proposal cannot contain whitespace'] },
  space_unknown: { exact: ['unknown space', 'invalid space id'] },
  space_settings: { prefix: ['invalid space settings'] },
  space_hibernated: { exact: ['space hibernated'] },
  network_not_premium: { exact: ['space is using a non-premium network'] },
  not_author: { exact: ['only space authors can propose'] },
  validation: { exact: ['validation failed', 'failed to check validation'] },
  proposal_limit: { exact: ['proposal limit reached', 'active proposal limit reached for author', 'failed to check proposals limit'] },
  voting_delay: { exact: ['invalid voting delay'] },
  voting_period: { exact: ['invalid voting period'] },
  voting_type: { exact: ['invalid voting type'] },
  dates: { exact: ['invalid start date', 'proposal end date must be in the future'] },
  snapshot_block: { exact: ['invalid snapshot block', 'proposal snapshot must be after network start', 'unable to fetch block'] },
  choices: { exact: ['invalid choice', 'wrong choices for basic type voting'], prefix: ['number of choices can not exceed'] },
  too_large: { exact: ['too large message', 'too large metadata'], prefix: ['proposal body length can not exceed'] },
  duplicate: { exact: ['duplicate message'] },
  flagged: { exact: ['invalid proposal, please contact support'] },
  privacy: { exact: ['not allowed to set privacy'] },
};

/** Our key for a voting-service refusal text, else `other`. Never returns the text. */
export function snapshotRefusalKey(text: unknown): string {
  if (typeof text !== 'string') return 'other';
  const t = text.trim().toLowerCase();
  if (t === '') return 'other';
  for (const [key, match] of Object.entries(SNAPSHOT_REFUSALS)) {
    if (match.exact?.includes(t)) return key;
    if (match.prefix?.some((p) => t.startsWith(p))) return key;
  }
  return 'other';
}

/** The site's code from a publish answer body, else `other`. */
export function publishCode(body: unknown): string {
  if (typeof body !== 'object' || body === null) return 'other';
  const error = (body as { error?: unknown; code?: unknown }).error;
  const code = (body as { code?: unknown }).code;
  for (const candidate of [code, error]) {
    if (typeof candidate === 'string' && (PUBLISH_CODES as readonly string[]).includes(candidate)) return candidate;
  }
  return 'other';
}

/**
 * The site's fixed refusal keys: ProposalPublisher's check keys, then ProposalHubRefusal::KEYS
 * (safe_governance hub branch sg-refusals, read 2026-11-16).
 */
export const PUBLISH_REASONS = [
  'envelope', 'address', 'domain_types', 'message', 'payload', 'space', 'period', 'age',
  'wrong_timestamp', 'invalid_signature', 'wrong_domain', 'wrong_types', 'wrong_format',
  'space_unknown', 'space_settings', 'space_hibernated', 'network_not_premium', 'not_author',
  'validation', 'proposal_limit', 'voting_delay', 'voting_period', 'voting_type', 'dates',
  'snapshot_block', 'choices', 'too_large', 'duplicate', 'flagged', 'privacy', 'transient', 'other',
] as const;

/** The reason token for a refusal answer, or null when none is printed. */
export function publishReason(code: string, body: unknown): string | null {
  const reason = typeof body === 'object' && body !== null ? (body as { reason?: unknown }).reason : undefined;
  if (typeof reason === 'string' && (PUBLISH_REASONS as readonly string[]).includes(reason)) return reason;
  if (code === 'rejected') return snapshotRefusalKey(reason);
  return reason === undefined ? null : 'other';
}

/** The value of `CAPTURE propose_publish`: "<status> <code>", plus "<reason>" when there is one. */
export function publishCaptureValue(status: unknown, body: unknown): string {
  const s = typeof status === 'number' && Number.isInteger(status) && status >= 100 && status <= 599 ? String(status) : '0';
  if (s === '200' && typeof body === 'object' && body !== null && typeof (body as { redirect?: unknown }).redirect === 'string') return '200 ok';
  const code = publishCode(body);
  const reason = publishReason(code, body);
  return reason === null ? `${s} ${code}` : `${s} ${code} ${reason}`;
}

/** True when every token of a capture value is on a fixed list. */
export function isFixedCapture(value: unknown): boolean {
  if (typeof value !== 'string') return false;
  if (value === '0 none' || value === '0 unread' || value === '200 ok') return true;
  const [status, code, reason, ...rest] = value.split(' ');
  if (rest.length > 0 || !/^(?:0|[1-5]\d\d)$/.test(status ?? '')) return false;
  if (code !== 'other' && !(PUBLISH_CODES as readonly string[]).includes(code ?? '')) return false;
  return reason === undefined || (PUBLISH_REASONS as readonly string[]).includes(reason);
}

/**
 * Writes the one public line. `answered` is what within() gave for the answer, or null when no
 * answer came; a value that is not all fixed tokens is written as `0 other`.
 */
export function printPublishCapture(
  answered: { value: unknown } | 'timeout' | null,
  write: (line: string) => void = (line) => console.log(line),
): void {
  const value = answered === null ? '0 none' : answered === 'timeout' ? '0 unread' : answered.value;
  write(`CAPTURE propose_publish ${isFixedCapture(value) ? value : '0 other'}`);
}

/** The parts of a Playwright request, response and page the watcher uses. */
export interface PublishRequest { method(): string; url(): string; postData(): string | null }
export interface PublishResponse { request(): PublishRequest; status(): number; json(): Promise<unknown> }
export interface PublishPage {
  on(event: 'request', listener: (r: PublishRequest) => void): unknown;
  on(event: 'response', listener: (r: PublishResponse) => void): unknown;
  off(event: 'request', listener: (r: PublishRequest) => void): unknown;
  off(event: 'response', listener: (r: PublishResponse) => void): unknown;
}

/**
 * Watches for the publish POST to exactly `origin` + `path`. Only the response to that request
 * is read; a later publish (a retry) replaces it. Call dispose() in `finally`.
 */
export function watchPublish(page: PublishPage, origin: string, path: string) {
  let intended: PublishRequest | null = null;
  let body = '';
  let answer: Promise<string> | null = null;
  const onRequest = (r: PublishRequest): void => {
    if (r.method() !== 'POST') return;
    let u: URL;
    try { u = new URL(r.url()); } catch { return; }
    if (u.origin !== origin || u.pathname !== path) return;
    intended = r;
    body = r.postData() ?? '';
    answer = null;
  };
  const onResponse = (r: PublishResponse): void => {
    if (intended === null || r.request() !== intended) return;
    answer = r.json().catch(() => null).then((b: unknown) => publishCaptureValue(r.status(), b));
  };
  page.on('request', onRequest);
  page.on('response', onResponse);
  return {
    /** The publish request's body, '' before it is sent. */
    body: (): string => body,
    /** The capture value of the answer, or null before one arrives. */
    answer: (): Promise<string> | null => answer,
    dispose: (): void => {
      page.off('request', onRequest);
      page.off('response', onResponse);
    },
  };
}
