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
  const wantSafes = signedSettings.plugins?.safeSnap?.safes ?? [];
  const gotSafes = Array.isArray(safeSnap?.safes) ? safeSnap.safes : [];
  if (!safeSnap) {
    out.push(`plugins.safeSnap: expected ${show(signedSettings.plugins?.safeSnap)}, got missing`);
  } else {
    for (const safe of wantSafes) {
      const found = gotSafes.some(
        (g) =>
          String(g?.network) === String(safe.network) &&
          typeof g?.realityAddress === 'string' &&
          g.realityAddress.toLowerCase() === String(safe.realityAddress).toLowerCase(),
      );
      if (!found) {
        out.push(`plugins.safeSnap: expected a safe with realityAddress ${safe.realityAddress} on network ${safe.network}, got ${show(safeSnap.safes)}`);
      }
    }
  }
  return out;
}

/** { ok: true, line: null } or { ok: false, line: 'SMOKE FAIL hub-space <mismatches>' } (one line). */
export function hubSpaceVerdict(signedSettings, hubSpace) {
  const mismatches = compareHubSpace(signedSettings, hubSpace);
  if (mismatches.length === 0) return { ok: true, line: null };
  return { ok: false, line: `SMOKE FAIL hub-space ${mismatches.join('; ')}` };
}
