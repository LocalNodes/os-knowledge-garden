// Global setup for the wallet e2e suite (21-41).
//
// 1. The suite chain must be the loopback anvil chain fork.sh started (assertSuiteFork).
// 2. Refuses while safe_smart_accounts_rpc_fixture is enabled on the lane site: canned reads
//    would make the e2e run prove nothing.
// 3. Seeds e2e-organizer, e2e-approver and e2e-member with anvil dev accounts 0, 1 and 2
//    (seed-e2e.php, through site.ts siteScript).
// 4. Sets process.env.LN_E2E, which the test workers inherit:
//    {runId, network, mode, users: {organizer|approver|member: {uid, name, address}}}.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { assertSuiteFork, devAccounts, TestWalletRefused } from './wallet/test-wallet';
import { rpcFixtureEnabled, siteScript } from './helpers/site';

const HERE = dirname(fileURLToPath(import.meta.url));

export default async function globalSetup(): Promise<void> {
  const network = process.env.LN_E2E_NETWORK || 'ethereum_sepolia';

  let entry;
  try {
    entry = await assertSuiteFork(network);
  } catch (err) {
    const reason = err instanceof TestWalletRefused ? err.reason : 'error';
    throw new Error(
      `start the chain first: bash tests/e2e/fork/fork.sh up ${network} (refused, ${reason}: ${(err as Error).message})`,
    );
  }

  // core.extension, not pml: pml hides this `hidden: true` module (corrected 2026-11-07).
  if (rpcFixtureEnabled()) {
    throw new Error('e2e refused: safe_smart_accounts_rpc_fixture is enabled on the lane site; its canned reads would fake the chain');
  }

  const accounts = await devAccounts(network);
  const seedOutput = siteScript(join(HERE, 'seed-e2e.php'), accounts.slice(0, 3));
  const match = seedOutput.match(/^LN_E2E_USERS=(\{.*\})\s*$/m);
  if (!match) {
    throw new Error(`seed-e2e.php did not print LN_E2E_USERS={json}. Output:\n${seedOutput}`);
  }

  const runId = process.env.LN_E2E_RUN_ID || new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');
  process.env.LN_E2E = JSON.stringify({
    runId,
    network,
    mode: entry.mode,
    users: JSON.parse(match[1]),
  });
}
