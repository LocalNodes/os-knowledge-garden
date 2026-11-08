// Unit tests for the hub read-back comparison (25-03 review fix 2). Run with `node --test`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NETWORKS, spaceSettings } from '../lib/governance.mjs';
import { compareHubSpace, hubSpaceVerdict } from '../lib/hub-space.mjs';

const net = NETWORKS['11155111'];
const safe = '0x1111111111111111111111111111111111111111';
const reality = '0x2222222222222222222222222222222222222222';

function signed(overrides = {}) {
  return spaceSettings({
    name: 'ln-smoke-abc123',
    network: 11155111,
    hats: net.hats,
    memberHatId: '3758.1.1',
    proposerHatId: '3758.1.1',
    period: 600,
    quorum: 1,
    safe,
    realityAddress: reality,
    ...overrides,
  });
}

/** What the hub's GraphQL returns for a space created from these settings. */
function hubFrom(settings) {
  // Deep copy: a test that edits the hub response must never edit the signed settings too.
  const copy = structuredClone(settings);
  return {
    id: 'ln-smoke-abc123.eth',
    network: copy.network,
    admins: [],
    moderators: [],
    strategies: copy.strategies.map((s) => ({ name: s.name, network: s.network, params: s.params })),
    validation: { name: copy.validation.name, params: copy.validation.params },
    voting: { quorum: copy.voting.quorum, period: copy.voting.period },
    plugins: copy.plugins,
  };
}

test('a hub response that matches the signed settings has no mismatches', () => {
  const s = signed();
  assert.deepEqual(compareHubSpace(s, hubFrom(s)), []);
  assert.deepEqual(hubSpaceVerdict(s, hubFrom(s)), { ok: true, line: null });
});

test('params may arrive as JSON text and addresses in another letter case', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.strategies[0].params = JSON.stringify(hub.strategies[0].params).toLowerCase().replace('"hatid"', '"hatId"');
  hub.plugins.safeSnap.safes[0].realityAddress = reality.toLowerCase();
  assert.deepEqual(compareHubSpace(s, hub), []);
});

test('a missing space is one mismatch', () => {
  assert.deepEqual(compareHubSpace(signed(), null), ['space: the hub returned no space']);
});

test('a response that lacks the compared fields yields one mismatch per field', () => {
  const s = signed();
  const hub = { id: 'ln-smoke-abc123.eth', network: '11155111', admins: [] };
  const found = compareHubSpace(s, hub);
  for (const needle of ['moderators', 'strategies', 'validation', 'voting.quorum', 'voting.period', 'plugins.safeSnap']) {
    assert.ok(found.some((m) => m.startsWith(needle)), `expected a mismatch for ${needle}, got ${JSON.stringify(found)}`);
  }
  assert.ok(!found.some((m) => m.startsWith('network')), 'network matches and must not be reported');
  assert.ok(!found.some((m) => m.startsWith('admins')), 'admins match and must not be reported');
});

test('the failure line names the step and joins every mismatch', () => {
  const s = signed();
  const v = hubSpaceVerdict(s, { network: '11155111', admins: [] });
  assert.equal(v.ok, false);
  assert.ok(v.line.startsWith('SMOKE FAIL hub-space '));
  assert.ok(v.line.includes('voting.quorum'));
  assert.ok(v.line.includes('plugins.safeSnap'));
  assert.ok(!v.line.includes('\n'));
});

test('wrong network, hat id, quorum, period and reality address are each reported', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.network = '1';
  hub.strategies[0].params.hatId = '3758.1.2';
  hub.validation.params.strategies[0].params.hatId = '3758.1.3';
  hub.voting.quorum = 2;
  hub.voting.period = 3600;
  hub.plugins.safeSnap.safes[0].realityAddress = '0x3333333333333333333333333333333333333333';
  const found = compareHubSpace(s, hub);
  for (const needle of ['network', 'strategies[0].params', 'validation.params', 'voting.quorum', 'voting.period', 'plugins.safeSnap']) {
    assert.ok(found.some((m) => m.startsWith(needle)), `expected a mismatch for ${needle}, got ${JSON.stringify(found)}`);
  }
});

test('the safeSnap safe must carry network 11155111 as well as the reality address', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes[0].network = '1';
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('plugins.safeSnap')));
});

test('an admin or a moderator on the hub is a mismatch, never tolerated', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.admins = [safe];
  hub.moderators = [safe];
  const found = compareHubSpace(s, hub);
  assert.ok(found.some((m) => m.startsWith('admins')));
  assert.ok(found.some((m) => m.startsWith('moderators')));
});

test('an extra or missing strategy is reported', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.strategies = [];
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('strategies')));
  hub.strategies = [...hubFrom(s).strategies, ...hubFrom(s).strategies];
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('strategies')));
});

test('quorum and period are compared with the signed settings, not with literals', () => {
  const s = signed({ quorum: 5, period: 7200 });
  assert.deepEqual(compareHubSpace(s, hubFrom(s)), []);
  const hub = hubFrom(s);
  hub.voting.quorum = 1;
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('voting.quorum')));
});
