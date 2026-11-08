// Compare the space the Snapshot hub serves with the settings that were signed and posted.
//
// The hub is the last step of the live smoke run (25-03). It accepted the sequencer POST, so the
// space exists; this proves the hub stored what both Safe owners signed, not just a space with the
// right network. Pure functions, no I/O, so `node --test` can feed them mocked hub responses.

const ADDRESS = /^0x[0-9a-fA-F]{40}$/;

/** Addresses compare case-insensitively (a hub may store them checksummed or lower-case). */
function normalize(value) {
  if (typeof value === 'string') return ADDRESS.test(value) ? value.toLowerCase() : value;
  if (Array.isArray(value)) return value.map(normalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, normalize(v)]));
  }
  return value;
}

/** The hub's `params` is a JSON scalar: an object, or the same object as text. */
function asJson(value) {
  if (typeof value !== 'string') return value;
  try {
    return JSON.parse(value);
  } catch {
    return value;
  }
}

const canon = (value) => JSON.stringify(sortKeys(normalize(asJson(value))));
function sortKeys(value) {
  if (Array.isArray(value)) return value.map(sortKeys);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((k) => [k, sortKeys(value[k])]));
  }
  return value;
}

const same = (a, b) => canon(a) === canon(b);
const show = (v) => (v === undefined ? 'missing' : JSON.stringify(v));

const isObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

/** One safeSnap or treasury entry in comparable form: addresses lower-cased, `network` always a string. */
function safeEntry(entry) {
  const value = normalize(entry);
  return isObject(value) && value.network !== undefined && value.network !== null ? { ...value, network: String(value.network) } : value;
}

/**
 * Compare a whole collection of entries (the safeSnap `safes`, the `treasuries`), not just the signed
 * entries' presence. `path` names the collection in every mismatch.
 *
 * Rule: the two collections are compared as a multiset of normalized entries (every field the entry
 * carries, addresses lower-cased, network as a string). Order is ignored. An unsigned extra entry
 * (the hub would let it act on a Safe nobody approved), a duplicate, a missing entry and any field
 * difference are each a mismatch, and the message says which. Entries that match exactly are removed
 * first; what is left on each side is paired in order to report field differences, and any leftover
 * is reported as missing (signed, not on the hub) or unexpected (on the hub, not signed).
 */
function compareEntries(path, wantRaw, gotRaw, out) {
  if (gotRaw !== undefined && gotRaw !== null && !Array.isArray(gotRaw)) {
    out.push(`${path}: expected an array, got ${show(gotRaw)}`);
    return;
  }
  const keyOf = (e) => JSON.stringify(sortKeys(e));
  const want = (wantRaw ?? []).map((e, index) => ({ index, entry: safeEntry(e) }));
  let spare = (gotRaw ?? []).map((e) => ({ entry: safeEntry(e) }));
  const unmatched = [];
  for (const w of want) {
    const at = spare.findIndex((g) => keyOf(g.entry) === keyOf(w.entry));
    if (at >= 0) spare = spare.filter((_, i) => i !== at);
    else unmatched.push(w);
  }
  while (unmatched.length > 0 && spare.length > 0) {
    const w = unmatched.shift();
    const g = spare.shift();
    if (!isObject(w.entry) || !isObject(g.entry)) {
      out.push(`${path}: missing entry ${keyOf(w.entry)}`, `${path}: unexpected extra entry ${keyOf(g.entry)}`);
      continue;
    }
    for (const field of [...new Set([...Object.keys(w.entry), ...Object.keys(g.entry)])].sort()) {
      if (JSON.stringify(sortKeys(w.entry[field])) !== JSON.stringify(sortKeys(g.entry[field]))) {
        out.push(`${path}[${w.index}].${field}: expected ${show(w.entry[field])}, got ${show(g.entry[field])}`);
      }
    }
  }
  for (const w of unmatched) out.push(`${path}: missing entry ${keyOf(w.entry)}`);
  for (const g of spare) out.push(`${path}: unexpected extra entry ${keyOf(g.entry)}`);
}

/**
 * Compare the space members as a set of lower-cased addresses.
 *
 * Members skip proposal validation, so an unsigned member could post proposals without the Hats score.
 * Order is ignored; an absent or null list on either side counts as []. A member the hub has and the
 * settings do not (including a second copy of a signed member) is an unexpected extra, a signed member
 * the hub lacks is missing, and a hub value that is not an array is one mismatch.
 */
function compareMembers(wantRaw, gotRaw, out) {
  if (gotRaw !== undefined && gotRaw !== null && !Array.isArray(gotRaw)) {
    out.push(`members: expected an array, got ${show(gotRaw)}`);
    return;
  }
  const lower = (m) => (typeof m === 'string' ? m.toLowerCase() : m);
  let spare = (gotRaw ?? []).map(lower);
  const missing = [];
  for (const w of (Array.isArray(wantRaw) ? wantRaw : []).map(lower)) {
    const at = spare.indexOf(w);
    if (at >= 0) spare = spare.filter((_, i) => i !== at);
    else missing.push(w);
  }
  for (const m of missing) out.push(`members: missing member ${show(m)}`);
  for (const m of spare) out.push(`members: unexpected extra member ${show(m)}`);
}

/**
 * Returns a list of mismatch strings; empty means the hub space matches the signed settings.
 * signedSettings is the object that was JSON-stringified into the signed message.
 */
export function compareHubSpace(signedSettings, hubSpace) {
  if (!hubSpace || typeof hubSpace !== 'object') return ['space: the hub returned no space'];
  const out = [];
  const expect = (path, want, got, eq = (a, b) => String(a) === String(b)) => {
    if (got === undefined || got === null || !eq(want, got)) out.push(`${path}: expected ${show(want)}, got ${show(got ?? undefined)}`);
  };

  expect('network', signedSettings.network, hubSpace.network);

  // D-08: nobody but the Safe's own signatures may ever act on the space, so the lists are empty on
  // the hub as well as in the settings.
  for (const key of ['admins', 'moderators']) {
    const got = hubSpace[key];
    if (!Array.isArray(got) || got.length !== 0 || (signedSettings[key] ?? []).length !== 0) {
      out.push(`${key}: expected [], got ${show(got ?? undefined)}`);
    }
  }

  const want = signedSettings.strategies ?? [];
  const got = hubSpace.strategies;
  if (!Array.isArray(got) || got.length !== want.length) {
    out.push(`strategies: expected ${want.length} entries, got ${show(got ?? undefined)}`);
  } else {
    want.forEach((s, i) => {
      if (got[i]?.name !== s.name) out.push(`strategies[${i}].name: expected ${show(s.name)}, got ${show(got[i]?.name)}`);
      if (got[i]?.network !== undefined && String(got[i].network) !== String(s.network)) {
        out.push(`strategies[${i}].network: expected ${show(s.network)}, got ${show(got[i].network)}`);
      }
      if (got[i]?.params === undefined || !same(s.params, got[i].params)) {
        out.push(`strategies[${i}].params: expected ${show(s.params)}, got ${show(asJson(got[i]?.params))}`);
      }
    });
  }

  const v = hubSpace.validation;
  const wantV = signedSettings.validation;
  if (!v || typeof v !== 'object') {
    out.push(`validation: expected ${show(wantV)}, got missing`);
  } else {
    if (v.name !== wantV.name) out.push(`validation.name: expected ${show(wantV.name)}, got ${show(v.name)}`);
    if (v.params === undefined || !same(wantV.params, v.params)) {
      out.push(`validation.params: expected ${show(wantV.params)}, got ${show(asJson(v.params))}`);
    }
  }

  const voting = hubSpace.voting ?? {};
  expect('voting.quorum', signedSettings.voting.quorum, voting.quorum, (a, b) => Number(a) === Number(b));
  expect('voting.period', signedSettings.voting.period, voting.period, (a, b) => Number(a) === Number(b));

  const safeSnap = asJson(hubSpace.plugins)?.safeSnap;
  if (!safeSnap) {
    out.push(`plugins.safeSnap: expected ${show(signedSettings.plugins?.safeSnap)}, got missing`);
  } else {
    compareEntries('plugins.safeSnap.safes', signedSettings.plugins?.safeSnap?.safes ?? [], safeSnap.safes, out);
  }

  // Members skip proposal validation, and onlyMembers:true would block every proposer, so both must be
  // exactly what was signed. Unset onlyMembers is false on either side; text "false" is not false.
  compareMembers(signedSettings.members, hubSpace.members, out);
  const wantOnly = signedSettings.filters?.onlyMembers ?? false;
  const gotOnly = hubSpace.filters?.onlyMembers ?? false;
  if (wantOnly !== gotOnly) out.push(`filters.onlyMembers: expected ${show(wantOnly)}, got ${show(gotOnly)}`);

  // Treasuries and symbol are compared only when the signed settings set them.
  if (signedSettings.treasuries !== undefined) compareEntries('treasuries', signedSettings.treasuries, hubSpace.treasuries, out);
  if (signedSettings.symbol !== undefined) expect('symbol', signedSettings.symbol, hubSpace.symbol);
  return out;
}

/** { ok: true, line: null } or { ok: false, line: 'SMOKE FAIL hub-space <mismatches>' } (one line). */
export function hubSpaceVerdict(signedSettings, hubSpace) {
  const mismatches = compareHubSpace(signedSettings, hubSpace);
  if (mismatches.length === 0) return { ok: true, line: null };
  return { ok: false, line: `SMOKE FAIL hub-space ${mismatches.join('; ')}` };
}
