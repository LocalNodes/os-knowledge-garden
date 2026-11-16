import { describe, expect, it } from 'vitest';
import { PUBLISH_CODES, publishCaptureValue, publishCode, SNAPSHOT_REFUSALS, snapshotRefusalKey } from './publish-capture';

/** Every printed token is a fixed word or a number. */
const FIXED = /^(?:\d{1,3}) (?:ok|other|[a-z_]+)(?: [a-z_]+)?$/;

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
  it('prints status and code, and a reason only for rejected', () => {
    expect(publishCaptureValue(200, { redirect: '/group/1/votes/p/1' })).toBe('200 ok');
    expect(publishCaptureValue(400, { error: 'refused' })).toBe('400 refused');
    expect(publishCaptureValue(502, { error: 'hub' })).toBe('502 hub');
    expect(publishCaptureValue(502, { error: 'rejected' })).toBe('502 rejected other');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'invalid voting period' })).toBe('502 rejected voting_period');
    expect(publishCaptureValue(502, { error: 'rejected', reason: 'a new sentence 0xabc' })).toBe('502 rejected other');
    expect(publishCaptureValue(500, null)).toBe('500 other');
    expect(publishCaptureValue(200, { error: 'refused' })).toBe('200 refused');
    expect(publishCaptureValue(Number.NaN, {})).toBe('0 other');
    expect(publishCaptureValue('502', {})).toBe('0 other');
  });

  it('never prints anything but fixed tokens, whatever the body holds', () => {
    const secretish = 'pw-Aa1 0xc0eE95AE5a54b0AFa511c453459f4d29e22aA4c9 https://example.test/x';
    const bodies: unknown[] = [
      { error: secretish }, { code: secretish }, { error: 'rejected', reason: secretish }, { redirect: 5, error: secretish },
      secretish, [secretish], { error: ['rejected'] }, undefined,
    ];
    for (const body of bodies) for (const status of [200, 400, 422, 500, 502, 99, 600]) {
      const value = publishCaptureValue(status, body);
      expect(value).toMatch(FIXED);
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
