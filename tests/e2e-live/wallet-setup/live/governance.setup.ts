// MetaMask wallet setup for the live governance judge (25-27). CI only.
//
// The same throwaway CI test-operator wallet as live.setup.ts (D-02: never a personal wallet), with its
// first three accounts: account 1 is the organizer, account 2 the voting member (it never holds
// ETH, criterion 5) and account 3 the second approver. Its seed phrase and its MetaMask password
// exist only as GitHub Actions secrets (SYNPRESS_SEED_PHRASE, SYNPRESS_WALLET_PASSWORD).
//
// This is the only governance file that reads the seed variable, and it reads it only inside
// functions: the setup below, and publicAddresses(), which the cache build (cache-governance.ts)
// calls to write the three public addresses for the later steps. Loading this module (for example
// by `npm run judge -- --list`) reads no secret and throws nothing. Nothing here logs page text:
// the import screen holds the phrase.
//
// Runs under plain Node (cache-governance.ts, type stripping), so its relative imports name
// their .ts files, and the MetaMask steps it needs are written out here, as in live.setup.ts.
import { defineWalletSetup } from '@synthetixio/synpress';
import { MetaMask } from '@synthetixio/synpress/playwright';
import { mnemonicToAccount } from 'viem/accounts';

/** MetaMask's local unlock password in the CI browser ('' outside CI). */
export const GOVERNANCE_WALLET_PASSWORD = process.env.SYNPRESS_WALLET_PASSWORD ?? '';

/** MetaMask 13.13.1's names for the accounts the setup ends with. */
export const ACCOUNT_NAMES = ['Account 1', 'Account 2', 'Account 3'] as const;

/** The seed phrase, checked, inside a function only. Never logged: the word count at most. */
function phrase(): string {
  const words = (process.env.SYNPRESS_SEED_PHRASE ?? '').trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) throw new Error('governance wallet setup: SYNPRESS_SEED_PHRASE is empty');
  if (![12, 15, 18, 21, 24].includes(words.length)) {
    throw new Error(`governance wallet setup: the seed phrase has ${words.length} words, not 12 to 24`);
  }
  return words.join(' ');
}

/**
 * The public addresses of accounts 1 to 3: BIP-44 m/44'/60'/0'/0/0 to /2, the path MetaMask's
 * "Add account" follows. The judge checks each against what MetaMask shares with the site.
 */
export function publicAddresses(): [string, string, string] {
  const words = phrase();
  return [0, 1, 2].map((addressIndex) => mnemonicToAccount(words, { addressIndex }).address) as [string, string, string];
}

/** Adds the next account from MetaMask 13.13.1's full-page Accounts list (metamask-steps.ts addAccount). */
async function addAccount(walletPage: import('@playwright/test').Page, extensionId: string, name: string): Promise<void> {
  await walletPage.getByTestId('account-menu-icon').click();
  await walletPage.getByTestId('add-multichain-account-button').click();
  await walletPage.getByText(name, { exact: true }).first().waitFor({ state: 'visible', timeout: 30_000 });
  await walletPage.goto(`chrome-extension://${extensionId}/home.html#`);
  await walletPage.getByTestId('account-menu-icon').waitFor({ state: 'visible', timeout: 30_000 });
}

/** Selects account 1 again, so the judge starts from the organizer. */
async function selectAccount(walletPage: import('@playwright/test').Page, name: string): Promise<void> {
  await walletPage.getByTestId('account-menu-icon').click();
  await walletPage.getByText(name, { exact: true }).first().click();
  await walletPage.getByTestId('account-menu-icon').filter({ hasText: name }).waitFor({ state: 'visible', timeout: 30_000 });
}

export default defineWalletSetup(GOVERNANCE_WALLET_PASSWORD, async (context, walletPage) => {
  const password = process.env.SYNPRESS_WALLET_PASSWORD ?? '';
  if (password === '') throw new Error('governance wallet setup: SYNPRESS_WALLET_PASSWORD is empty');
  const metamask = new MetaMask(context, walletPage, password);
  await metamask.importWallet(phrase());
  // As live.setup.ts: MetaMask 13.13.1 ends onboarding on "Your wallet is ready!"; if "Open
  // wallet" does nothing, open the home page directly and unlock.
  await walletPage.waitForURL(/#onboarding\/completion/, { timeout: 60_000 }).catch(() => undefined);
  const openWallet = walletPage.getByRole('button', { name: 'Open wallet' });
  if (await openWallet.isVisible()) await openWallet.click();
  await walletPage.waitForURL((url) => !url.hash.startsWith('#onboarding'), { timeout: 15_000 }).catch(() => undefined);
  if (walletPage.url().includes('#onboarding')) {
    await walletPage.goto(walletPage.url().replace(/#.*$/, '#'));
    const unlock = walletPage.getByTestId('unlock-password');
    if (await unlock.waitFor({ state: 'visible', timeout: 10_000 }).then(() => true, () => false)) {
      await unlock.fill(password);
      await walletPage.getByTestId('unlock-submit').click();
    }
  }
  await walletPage.getByTestId('account-menu-icon').waitFor({ state: 'visible', timeout: 60_000 });
  const extensionId = new URL(walletPage.url()).host;
  // Accounts 2 (the voting member) and 3 (the second approver).
  await addAccount(walletPage, extensionId, ACCOUNT_NAMES[1]);
  await addAccount(walletPage, extensionId, ACCOUNT_NAMES[2]);
  await selectAccount(walletPage, ACCOUNT_NAMES[0]);
  // The switch to Ethereum Sepolia happens in the judge, from the site's page, as in 21-45.
});
