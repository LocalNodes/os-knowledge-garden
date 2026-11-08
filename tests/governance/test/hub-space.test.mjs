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
    symbol: copy.symbol,
    members: copy.members,
    filters: { onlyMembers: copy.filters.onlyMembers },
    treasuries: copy.treasuries,
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

// --- safeSnap safes: the whole collection is compared (25-03 review round 2, fix 2) ---------------
//
// Rule under test: the hub's safes and the signed safes are compared as a multiset of normalized
// entries (addresses lower-cased, network as a string, every field the entry carries). Order does not
// matter. An extra entry, a duplicate, a missing entry and any field difference each fail, and the
// message says which.

const reality2 = '0x4444444444444444444444444444444444444444';
const safesOf = (hub) => hub.plugins.safeSnap.safes;

test('an extra, unsigned safeSnap entry (a mainnet one) is rejected and named', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub).push({ network: '1', realityAddress: reality });
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.ok(found[0].startsWith('plugins.safeSnap.safes'), found[0]);
  assert.match(found[0], /unexpected extra entry/);
  assert.match(found[0], /"network":"1"/);
  assert.equal(hubSpaceVerdict(s, hub).ok, false);
});

test('an extra entry on the same network with another reality address is rejected too', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub).push({ network: '11155111', realityAddress: reality2 });
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /unexpected extra entry/);
  assert.ok(found[0].includes(reality2));
});

test('a duplicated entry is rejected', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub).push({ ...safesOf(hub)[0] });
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /unexpected extra entry/);
});

test('a missing entry is rejected and named, whether the hub has none or another one', () => {
  const s = signed();
  s.plugins.safeSnap.safes.push({ network: 11155111, realityAddress: reality2 });
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes = [safesOf(hub)[0]];
  let found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /missing entry/);
  assert.ok(found[0].includes(reality2));
  hub.plugins.safeSnap.safes = [];
  found = compareHubSpace(s, hub);
  assert.equal(found.filter((m) => /missing entry/.test(m)).length, 2, JSON.stringify(found));
});

test('the same safes in another order are accepted (compared as a set, order ignored)', () => {
  const s = signed();
  s.plugins.safeSnap.safes.push({ network: 11155111, realityAddress: reality2 });
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes.reverse();
  assert.deepEqual(compareHubSpace(s, hub), []);
});

test('a duplicate cannot stand in for a missing entry', () => {
  const s = signed();
  s.plugins.safeSnap.safes.push({ network: 11155111, realityAddress: reality2 });
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes = [safesOf(hub)[0], { ...safesOf(hub)[0] }];
  // The signed reality2 entry is gone and a second copy of the first is in its place; the leftovers are
  // paired, so the report is one field difference that names the signed value.
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.ok(found[0].startsWith('plugins.safeSnap.safes[1].realityAddress'), found[0]);
  assert.ok(found[0].includes(reality2), found[0]);
});

test('a field difference inside an entry is named by field', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub)[0].network = '1';
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /safes\[0\]\.network: expected "11155111", got "1"/);
});

test('an extra field on a hub entry is a field mismatch, not ignored', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub)[0].hash = '0xabc';
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /safes\[0\]\.hash: expected missing, got "0xabc"/);
});

test('network as a number or a string, and addresses in any case, are the same entry', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes = [{ network: 11155111, realityAddress: reality.toUpperCase().replace('0X', '0x') }];
  assert.deepEqual(compareHubSpace(s, hub), []);
});

test('a safes value that is not an array is one mismatch', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.plugins.safeSnap.safes = { network: '11155111', realityAddress: reality };
  const found = compareHubSpace(s, hub);
  assert.ok(found.some((m) => m.startsWith('plugins.safeSnap.safes: expected an array')), JSON.stringify(found));
});

test('plugins may arrive as JSON text and the safes are still compared in full', () => {
  const s = signed();
  const hub = hubFrom(s);
  safesOf(hub).push({ network: '1', realityAddress: reality });
  hub.plugins = JSON.stringify(hub.plugins);
  assert.ok(compareHubSpace(s, hub).some((m) => /unexpected extra entry/.test(m)));
});

// --- members, filters, treasuries and symbol (25-03 review round 3) -------------------------------
//
// Members skip proposal validation, so an unsigned member could post proposals without the Hats
// score; onlyMembers:true would block every proposer. Rules under test:
// - members: a set of lower-cased addresses; order ignored; an extra, a duplicate or a missing
//   member is a named mismatch; an absent or null list on either side counts as [].
// - filters.onlyMembers: the signed value (false when unset) must equal the hub value (false when
//   unset).
// - treasuries and symbol: compared only when the signed settings set them; treasuries as a
//   multiset like the safeSnap safes (addresses lower-cased, network as a string).

const member1 = '0x5555555555555555555555555555555555555555';
const member2 = '0x6666666666666666666666666666666666666666';

test('an extra, unsigned member on the hub is rejected and named', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.members = [member1];
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.ok(found[0].startsWith('members'), found[0]);
  assert.match(found[0], /unexpected extra member/);
  assert.ok(found[0].includes(member1), found[0]);
  assert.equal(hubSpaceVerdict(s, hub).ok, false);
});

test('a duplicated member on the hub is rejected', () => {
  const s = signed();
  s.members = [member1];
  const hub = hubFrom(s);
  hub.members = [member1, member1.toUpperCase().replace('0X', '0x')];
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^members: unexpected extra member/);
});

test('a signed member the hub lacks is rejected and named', () => {
  const s = signed();
  s.members = [member1, member2];
  const hub = hubFrom(s);
  hub.members = [member1];
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^members: missing member/);
  assert.ok(found[0].includes(member2), found[0]);
});

test('the same members in another order and letter case are accepted', () => {
  const s = signed();
  s.members = [member1, member2];
  const hub = hubFrom(s);
  hub.members = [member2.toUpperCase().replace('0X', '0x'), member1];
  assert.deepEqual(compareHubSpace(s, hub), []);
});

test('an absent or null members list counts as empty on either side', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.members = null;
  assert.deepEqual(compareHubSpace(s, hub), []);
  delete hub.members;
  assert.deepEqual(compareHubSpace(s, hub), []);
  delete s.members;
  hub.members = [];
  assert.deepEqual(compareHubSpace(s, hub), []);
  hub.members = [member1];
  assert.ok(compareHubSpace(s, hub).some((m) => /^members: unexpected extra member/.test(m)));
});

test('a members value that is not an array is one mismatch', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.members = member1;
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^members: expected an array/);
});

test('onlyMembers true on the hub against signed false is rejected', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.filters = { onlyMembers: true };
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^filters\.onlyMembers: expected false, got true/);
});

test('onlyMembers unset on either side counts as false', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.filters = null;
  assert.deepEqual(compareHubSpace(s, hub), []);
  hub.filters = {};
  assert.deepEqual(compareHubSpace(s, hub), []);
  delete hub.filters;
  delete s.filters;
  assert.deepEqual(compareHubSpace(s, hub), []);
  hub.filters = { onlyMembers: true };
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('filters.onlyMembers')));
});

test('onlyMembers as the text "false" is not the boolean false', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.filters = { onlyMembers: 'false' };
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('filters.onlyMembers')));
});

test('a treasuries mismatch is rejected when the treasuries were signed', () => {
  const s = signed();
  let hub = hubFrom(s);
  hub.treasuries[0].address = '0x7777777777777777777777777777777777777777';
  let found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^treasuries\[0\]\.address: expected/);

  hub = hubFrom(s);
  hub.treasuries.push({ name: 'Other fund', address: member1, network: '11155111' });
  found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^treasuries: unexpected extra entry/);

  hub = hubFrom(s);
  hub.treasuries = [];
  found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^treasuries: missing entry/);
});

test('treasuries compare with the address in any case and the network as a number or text', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.treasuries = [{ name: 'Group fund', address: safe.toUpperCase().replace('0X', '0x'), network: 11155111 }];
  assert.deepEqual(compareHubSpace(s, hub), []);
});

test('a symbol mismatch is rejected when the symbol was signed', () => {
  const s = signed();
  const hub = hubFrom(s);
  hub.symbol = 'OTHER';
  const found = compareHubSpace(s, hub);
  assert.equal(found.length, 1, JSON.stringify(found));
  assert.match(found[0], /^symbol: expected "VOTE", got "OTHER"/);
  delete hub.symbol;
  assert.ok(compareHubSpace(s, hub).some((m) => m.startsWith('symbol')));
});

test('treasuries and symbol that were not signed are ignored', () => {
  const s = signed();
  delete s.treasuries;
  delete s.symbol;
  const hub = hubFrom(signed());
  hub.symbol = 'OTHER';
  hub.treasuries = [{ name: 'Other fund', address: member1, network: '1' }];
  assert.deepEqual(compareHubSpace(s, hub), []);
});
