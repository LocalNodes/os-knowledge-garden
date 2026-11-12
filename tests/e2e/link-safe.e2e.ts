// Linking an existing Safe as a group fund, and unlinking it (21-42; 21-17's T3 form).
//
// Link (a), the no-key link proof: a Safe made on the local chain straight through the
// registry's factory, outside the app (as Safe{Wallet} would make it), links on
// "E2E Link <runId>-<network>" and shows the chain's owners and threshold. Then
// "Unlink group fund" empties the tab again.
//
// Link (b): the real pre-existing Safe exists only on a fork of the real chain, so it runs
// only in fork mode and is skipped otherwise (ruling 2026-11-06).
//
// No chain id or contract address is written here: they come from the run state, the
// site's active registry entry and tests/e2e/fixtures/known-safes.json.
import { readFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import { devAccounts, installTestWallet, toChecksumAddress } from './wallet/test-wallet';
import {
  createSafeViaFactory,
  e2eRun,
  ethBalance,
  mode,
  owners,
  record,
  registry,
  threshold,
  type E2eRun,
  type RegistryEntry,
} from './helpers/chain';
import {
  accountId,
  approverCount,
  balanceText,
  flow,
  formatUnits,
  linkExistingSafe,
  openFund,
  refreshBalances,
  safeAddressForGroup,
  seedRunGroups,
  thresholdSentence,
  unlinkFund,
  signIn,
  type RunGroups,
} from './helpers/treasury';

const HERE = dirname(fileURLToPath(import.meta.url));

interface KnownSafes { existing: string; safeL2Singleton141: string; fallbackHandler141: string }

function knownSafes(network: string): KnownSafes {
  const all = JSON.parse(readFileSync(join(HERE, 'fixtures', 'known-safes.json'), 'utf8')) as Record<string, KnownSafes>;
  const k = all[network];
  if (!k) throw new Error(`no known Safes for ${network} in fixtures/known-safes.json`);
  return k;
}

const FORK_ONLY = 'real pre-existing Safe exists only on a fork of the real chain (ruling 2026-11-06)';
const LINKED = 'The group fund is linked.';
const UNLINKED = 'The group fund was unlinked. Its account and money are unchanged.';
const EMPTY = "This group doesn't have a group fund yet";

let run: E2eRun;
let groups: RunGroups;
let net: RegistryEntry;

/** "Unlink group fund" and its modal; the tab is empty again. */
async function unlinkAndCheck(page: Page, gid: number, p: Record<string, unknown>): Promise<void> {
  await unlinkFund(page, gid);
  await expect(page.getByText(UNLINKED)).toBeVisible();
  await expect(page.getByRole('heading', { name: EMPTY })).toBeVisible();
  p.unlinked = UNLINKED;
}

test.describe.serial('link an existing Safe as the group fund', () => {
  test.beforeAll(async () => {
    run = e2eRun();
    net = await registry(run.network);
    groups = seedRunGroups(run.runId, run.network);
  });

  test('link (a) a Safe made outside the app', async ({ context, page }, info) => {
    await flow(run.network, 'link (a)', page, info, async (p, c) => {
      const known = knownSafes(run.network);
      const devs = await devAccounts(run.network);
      const salt = BigInt(`0x${randomBytes(16).toString('hex')}`);
      const safe = await createSafeViaFactory(run.network, [devs[4], devs[5]], 2, salt, {
        singleton: known.safeL2Singleton141,
        fallbackHandler: known.fallbackHandler141,
      });
      c.address = safe;
      c.salt = salt;
      c.owners = owners(run.network, safe);
      c.threshold = threshold(run.network, safe);
      expect(new Set(c.owners as string[])).toEqual(new Set([devs[4], devs[5]]));
      expect(c.threshold).toBe(2);

      await installTestWallet(context, { network: run.network, account: 0 });
      await signIn(page);
      p.group = groups.link;
      await linkExistingSafe(page, groups.link, { network: net, address: safe });
      await expect(page.getByText(LINKED)).toBeVisible({ timeout: 30_000 });
      p.linked = LINKED;
      await openFund(page, groups.link);
      p.approvers = await approverCount(page);
      p.thresholdSentence = await thresholdSentence(page);
      p.accountId = await accountId(page);
      expect(p.approvers).toBe((c.owners as string[]).length);
      expect(p.thresholdSentence).toBe(`Needs ${c.threshold} of ${(c.owners as string[]).length} organizers to approve a payment.`);
      expect(p.accountId).toBe(toChecksumAddress(safe));

      await unlinkAndCheck(page, groups.link, p);
    });
  });

  test('link (b) the real pre-existing Safe', async ({ context, page }, info) => {
    if (mode(run.network) !== 'fork') {
      record(run.network, 'link (b)', { result: 'skipped', finding: FORK_ONLY });
    }
    test.skip(mode(run.network) !== 'fork', FORK_ONLY);

    await flow(run.network, 'link (b)', page, info, async (p, c) => {
      const existing = toChecksumAddress(knownSafes(run.network).existing);
      c.address = existing;
      c.owners = owners(run.network, existing);
      c.threshold = threshold(run.network, existing);
      c.ethBalance = ethBalance(run.network, existing);

      await installTestWallet(context, { network: run.network, account: 0 });
      await signIn(page);
      p.group = groups.link;
      try {
        await linkExistingSafe(page, groups.link, { network: net, address: existing });
        await expect(page.getByText(LINKED)).toBeVisible({ timeout: 30_000 });
        await openFund(page, groups.link);
        await page.waitForTimeout(16_000);
        await refreshBalances(page);
        p.approvers = await approverCount(page);
        p.thresholdSentence = await thresholdSentence(page);
        p.eth = await balanceText(page, net.native_currency.name);
        const n = (c.owners as string[]).length;
        expect(p.approvers).toBe(n);
        expect(p.thresholdSentence).toBe(`Needs ${c.threshold} of ${n} organizers to approve a payment.`);
        expect(p.eth).toBe(formatUnits(c.ethBalance as bigint, net.native_currency.decimals, net.native_currency.symbol));
      } finally {
        // Only when the link went through, so a failed link keeps its own error.
        if (safeAddressForGroup(groups.link)) await unlinkAndCheck(page, groups.link, p);
      }
    });
  });
});
