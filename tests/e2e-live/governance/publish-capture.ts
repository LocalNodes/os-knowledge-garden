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
// - <reason> is printed only for `rejected` (the voting service refused the proposal). It is a
//   key of SNAPSHOT_REFUSALS, our own fixed map of the voting service's published refusal
//   strings, else `other`. The service's text itself never reaches the log.
//
// safe_governance 1.0.0-alpha1 sends only {error: <code>}; the voting service's text stays in the
// HubException (HubClient::hubError: "For the exception only, never for a log"). So at alpha1 the
// reason is always `other`; the map reads a string `reason` field if a later release sends one.

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

/** The value of `CAPTURE propose_publish`: "<status> <code>" plus "<reason>" for `rejected`. */
export function publishCaptureValue(status: unknown, body: unknown): string {
  const s = typeof status === 'number' && Number.isInteger(status) && status >= 100 && status <= 599 ? String(status) : '0';
  if (s === '200' && typeof body === 'object' && body !== null && typeof (body as { redirect?: unknown }).redirect === 'string') return '200 ok';
  const code = publishCode(body);
  if (code !== 'rejected') return `${s} ${code}`;
  return `${s} rejected ${snapshotRefusalKey((body as { reason?: unknown }).reason)}`;
}
