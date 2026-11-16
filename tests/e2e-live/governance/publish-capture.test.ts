import { EventEmitter } from 'node:events';
import { describe, expect, it } from 'vitest';
import {
  isFixedCapture, printPublishCapture, PUBLISH_CODES, PUBLISH_REASONS, publishCaptureValue, publishCode,
  SNAPSHOT_REFUSALS, snapshotRefusalKey, watchPublish, type PublishPage, type PublishRequest,
} from './publish-capture';

/** The codes and keys the site sends, written out here so a looser list in the module fails. */
const CODES = ['refused', 'wrong_account', 'not_proposer', 'rate_limited', 'hub', 'rejected', 'unavailable', 'mismatch', 'public_notice', 'no_wallet'];
const REASONS = [
  'envelope', 'address', 'domain_types', 'message', 'payload', 'space', 'period', 'age',
  'wrong_timestamp', 'invalid_signature', 'wrong_domain', 'wrong_types', 'wrong_format', 'space_unknown',
  'space_settings', 'space_hibernated', 'network_not_premium', 'not_author', 'validation', 'proposal_limit',
  'voting_delay', 'voting_period', 'voting_type', 'dates', 'snapshot_block', 'choices', 'too_large',
  'duplicate', 'flagged', 'privacy', 'transient', 'other',
];

/** Fails unless every token of a capture value is on the lists above. */
function expectFixed(value: string): void {
  if (['200 ok', '0 none', '0 unread'].includes(value)) return;
  const [status, code, reason, ...rest] = value.split(' ');
  expect(rest, value).toEqual([]);
  expect(status, value).toMatch(/^(?:0|[1-5]\d\d)$/);
  expect([...CODES, 'other'], value).toContain(code);
  if (reason !== undefined) expect(REASONS, value).toContain(reason);
}

describe('the publish code', () => {
  it('passes only the site’s fixed codes', () => {
    for (const code of PUBLISH_CODES) expect(publishCode({ error: code })).toBe(code);
    expect(publishCode({ error: 'Something the server said' })).toBe('other');
    expect(publishCode({ error: 'refused ' })).toBe('other');
    expect(publishCode(null)).toBe('other');
    expect(publishCode('refused')).toBe('other');
    expect(publishCode({})).toBe('other');
  });

  it('reads the notice refusal by its code and never its sentence', () => {
    expect(publishCaptureValue(422, { code: 'public_notice', error: 'Tick the box above to go on.' })).toBe('422 public_notice');
  });
});

describe('the capture value', () => {
  it('prints status and code, and the site’s key for every code', () => {
    expect(publishCaptureValue(200, { redirect: '/group/1/votes/p/1' })).toBe('200 ok');
    expect(publishCaptureValue(400, { error: 'refused' })).toBe('400 refused');
    expect(publishCaptureValue(502, { error: 'hub' })).toBe('502 hub');
    expect(publishCaptureValue(502, { error: 'rejected' })).toBe('502 rejected other');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'invalid voting period' })).toBe('502 rejected voting_period');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'a new sentence 0xabc' })).toBe('502 rejected other');
    expect(publishCaptureValue(500, null)).toBe('500 other');
    expect(publishCaptureValue(200, { error: 'refused' })).toBe('200 refused');
    expect(publishCaptureValue(Number.NaN, {})).toBe('0 other');
    expect(publishCaptureValue(400, { error: 'refused', reason: 'period' })).toBe('400 refused period');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'network_not_premium' })).toBe('502 rejected network_not_premium');
    expect(publishCaptureValue(503, { error: 'unavailable', reason: 'transient' })).toBe('503 unavailable transient');
    expect(publishCaptureValue(403, { error: 'not_proposer', reason: 'not_author' })).toBe('403 not_proposer not_author');
    expect(publishCaptureValue(400, { error: 'refused', reason: 'private_secret' })).toBe('400 refused other');
    expect(publishCaptureValue(400, { error: 'refused', reason: 7 })).toBe('400 refused other');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'transient ' })).toBe('502 rejected other');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'Invalid voting period' })).toBe('502 rejected voting_period');
    expect(publishCaptureValue('502', {})).toBe('0 other');
  });

  it('never prints anything but fixed tokens, whatever the body holds', () => {
    const secretish = 'pw-Aa1 0xc0eE95AE5a54b0AFa511c453459f4d29e22aA4c9 https://example.test/x';
    const bodies: unknown[] = [
      { error: secretish }, { code: secretish }, { error: 'rejected', reason: secretish }, { redirect: 5, error: secretish },
      secretish, [secretish], { error: ['rejected'] }, undefined,
      { error: 'refused', reason: 'private_secret' }, { error: 'private_secret', reason: 'period' },
      { error: 'unavailable', reason: secretish }, { error: 'rejected', reason: { key: 'period' } },
      { error: 'refused', reason: '__proto__' }, { error: 'refused', reason: 'constructor' },
    ];
    for (const body of bodies) for (const status of [200, 400, 422, 500, 502, 99, 600]) {
      const value = publishCaptureValue(status, body);
      expectFixed(value);
      expect(isFixedCapture(value)).toBe(true);
      expect(value).not.toContain('0x');
      expect(value).not.toContain('pw');
    }
  });
});

describe('the voting service map', () => {
  it('maps each published string to its key, exactly or by its fixed beginning', () => {
    expect(snapshotRefusalKey('wrong timestamp')).toBe('wrong_timestamp');
    expect(snapshotRefusalKey('  Space is using a non-premium network ')).toBe('network_not_premium');
    expect(snapshotRefusalKey('only space authors can propose')).toBe('not_author');
    expect(snapshotRefusalKey('proposal limit reached')).toBe('proposal_limit');
    expect(snapshotRefusalKey('invalid space settings: strategy x')).toBe('space_settings');
    expect(snapshotRefusalKey('number of choices can not exceed 20')).toBe('choices');
    expect(snapshotRefusalKey('proposal body length can not exceed 10000 characters')).toBe('too_large');
  });

  it('refuses near misses, non-strings and empty text', () => {
    expect(snapshotRefusalKey('wrong timestamp!')).toBe('other');
    expect(snapshotRefusalKey('the space is using a non-premium network')).toBe('other');
    expect(snapshotRefusalKey('')).toBe('other');
    expect(snapshotRefusalKey(undefined)).toBe('other');
    expect(snapshotRefusalKey({ message: 'wrong timestamp' })).toBe('other');
  });

  it('has fixed snake_case keys and no string under two keys', () => {
    const seen = new Set<string>();
    for (const [key, match] of Object.entries(SNAPSHOT_REFUSALS)) {
      expect(key).toMatch(/^[a-z][a-z_]*$/);
      for (const s of [...(match.exact ?? []), ...(match.prefix ?? [])]) {
        expect(s).toBe(s.trim().toLowerCase());
        expect(seen.has(s)).toBe(false);
        seen.add(s);
      }
    }
  });
});

describe('the fixed lists', () => {
  it('are exactly the site’s codes and keys', () => {
    expect([...PUBLISH_CODES]).toEqual(CODES);
    expect([...PUBLISH_REASONS]).toEqual(REASONS);
    for (const key of Object.keys(SNAPSHOT_REFUSALS)) expect(REASONS).toContain(key);
  });

  it('isFixedCapture refuses any token off the lists', () => {
    for (const ok of ['200 ok', '0 none', '0 unread', '400 refused', '400 refused period', '502 other other', '0 other']) expect(isFixedCapture(ok), ok).toBe(true);
    for (const bad of ['500 private_secret', '400 refused private_secret', '400 refused period extra', '99 refused', '600 refused', 'abc refused', '400  refused', '', 5, null]) {
      expect(isFixedCapture(bad), String(bad)).toBe(false);
    }
  });
});

describe('the printed line', () => {
  const printed = (answered: Parameters<typeof printPublishCapture>[0]): string[] => {
    const lines: string[] = [];
    printPublishCapture(answered, (line) => lines.push(line));
    return lines;
  };

  it('writes one line for each outcome', () => {
    expect(printed({ value: '502 rejected voting_period' })).toEqual(['CAPTURE propose_publish 502 rejected voting_period']);
    expect(printed({ value: '200 ok' })).toEqual(['CAPTURE propose_publish 200 ok']);
    expect(printed('timeout')).toEqual(['CAPTURE propose_publish 0 unread']);
    expect(printed(null)).toEqual(['CAPTURE propose_publish 0 none']);
  });

  it('never writes a token off the lists, whatever it is handed', () => {
    for (const value of ['500 private_secret', '400 refused 0xc0eE95AE5a54b0AFa511c453459f4d29e22aA4c9', 'pw-Aa1', 42, { a: 1 }, undefined]) {
      expect(printed({ value })).toEqual(['CAPTURE propose_publish 0 other']);
    }
  });

  it('writes to the console by default', () => {
    const lines: unknown[] = [];
    const log = console.log;
    console.log = (...args: unknown[]) => { lines.push(args.join(' ')); };
    try { printPublishCapture({ value: '400 refused age' }); } finally { console.log = log; }
    expect(lines).toEqual(['CAPTURE propose_publish 400 refused age']);
  });
});

describe('the publish watcher', () => {
  const ORIGIN = 'https://site.test';
  const PATH = '/group/7/votes/publish';
  const request = (method: string, url: string, body = '{"chainId":1}'): PublishRequest => ({ method: () => method, url: () => url, postData: () => body });
  const respond = (page: EventEmitter, req: PublishRequest, status: number, json: unknown): void => {
    page.emit('response', { request: () => req, status: () => status, json: async () => json });
  };
  const setUp = () => {
    const page = new EventEmitter();
    return { page, watch: watchPublish(page as unknown as PublishPage, ORIGIN, PATH) };
  };

  it('reads only the answer to the publish request it saw', async () => {
    const { page, watch } = setUp();
    const intended = request('POST', `${ORIGIN}${PATH}`);
    page.emit('request', intended);
    expect(watch.body()).toBe('{"chainId":1}');
    respond(page, request('POST', `${ORIGIN}${PATH}`), 500, { error: 'hub' });
    expect(watch.answer()).toBeNull();
    respond(page, intended, 502, { error: 'rejected', reason: 'voting_period' });
    expect(await watch.answer()).toBe('502 rejected voting_period');
    watch.dispose();
  });

  it('ignores other methods, origins, groups and paths', () => {
    const { page, watch } = setUp();
    for (const r of [
      request('GET', `${ORIGIN}${PATH}`), request('POST', `https://other.test${PATH}`),
      request('POST', `${ORIGIN}/group/8/votes/publish`), request('POST', `${ORIGIN}${PATH}/message`), request('POST', 'not a url'),
    ]) {
      page.emit('request', r);
      respond(page, r, 500, { error: 'hub' });
    }
    expect(watch.body()).toBe('');
    expect(watch.answer()).toBeNull();
    watch.dispose();
  });

  it('a retry replaces the earlier request and its answer', async () => {
    const { page, watch } = setUp();
    const first = request('POST', `${ORIGIN}${PATH}?x=1`, 'first');
    page.emit('request', first);
    respond(page, first, 503, { error: 'unavailable', reason: 'transient' });
    const second = request('POST', `${ORIGIN}${PATH}`, 'second');
    page.emit('request', second);
    expect(watch.answer()).toBeNull();
    respond(page, first, 503, { error: 'unavailable' });
    expect(watch.answer()).toBeNull();
    respond(page, second, 200, { redirect: '/group/7/votes/p/1' });
    expect(await watch.answer()).toBe('200 ok');
    expect(watch.body()).toBe('second');
    watch.dispose();
  });

  it('dispose removes both listeners', () => {
    const { page, watch } = setUp();
    expect(page.listenerCount('request')).toBe(1);
    expect(page.listenerCount('response')).toBe(1);
    watch.dispose();
    expect(page.listenerCount('request')).toBe(0);
    expect(page.listenerCount('response')).toBe(0);
    const late = request('POST', `${ORIGIN}${PATH}`);
    page.emit('request', late);
    expect(watch.body()).toBe('');
  });

  it('an unreadable body is `other`, not an error', async () => {
    const { page, watch } = setUp();
    const r = request('POST', `${ORIGIN}${PATH}`);
    page.emit('request', r);
    page.emit('response', { request: () => r, status: () => 502, json: async () => { throw new Error('not json'); } });
    expect(await watch.answer()).toBe('502 other');
    watch.dispose();
  });
});

