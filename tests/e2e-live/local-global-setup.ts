// Global setup for the local MetaMask recording (21-45 Task A).
//
// Refuses unless the base URL host passes 21-41's isAllowedTestHost and the base_sepolia suite
// chain passes assertSuiteFork (loopback, anvil, the recorded instanceId and chain id). Then it
// runs the wallet suite's own global setup (tests/e2e/global-setup.ts) on base_sepolia: the
// fixture-module refusal, the seeded e2e users and process.env.LN_E2E.
import { assertSuiteFork, isAllowedTestHost } from '../e2e/wallet/test-wallet';
import e2eGlobalSetup from '../e2e/global-setup';
import { BASE_URL } from './local.config';

export default async function localGlobalSetup(): Promise<void> {
  const host = new URL(BASE_URL).hostname;
  if (!isAllowedTestHost(host)) {
    throw new Error(`local recording refused: ${host} is not a .test or loopback host`);
  }
  if ((process.env.LN_E2E_NETWORK ?? 'base_sepolia') !== 'base_sepolia') {
    throw new Error('local recording refused: it runs on base_sepolia (chain id 84532) only');
  }
  process.env.LN_E2E_NETWORK = 'base_sepolia';
  const entry = await assertSuiteFork('base_sepolia');
  if (Number(entry.chainId) !== 84532) {
    throw new Error(`local recording refused: the base_sepolia suite chain has chain id ${entry.chainId}, not 84532`);
  }
  await e2eGlobalSetup();
}
