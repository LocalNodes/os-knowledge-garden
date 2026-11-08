// MetaMask wallet setup for the live-Sepolia suite (21-45 Task B). CI only.
//
// The wallet is a throwaway Sepolia test wallet. Its seed phrase and its MetaMask password exist
// only as GitHub Actions secrets (SYNPRESS_SEED_PHRASE, SYNPRESS_WALLET_PASSWORD). The workflow
// passes the seed to the cache-build step only, and the password to the cache build and the test
// run (the spec unlocks MetaMask with LIVE_WALLET_PASSWORD). This is the only file
// that reads the seed variable, and it reads it only inside the setup function, so loading this
// module (for example by `npm run list`) reads no secret and throws nothing.
//
// The password is read at load time because Synpress's defineWalletSetup takes it as an
// argument; an empty value is allowed there and refused inside the function.
//
// Nothing here logs page text: the import screen holds the phrase. The cache build
// (cache-live.ts) doesn't either.
import { defineWalletSetup } from '@synthetixio/synpress';
import { MetaMask } from '@synthetixio/synpress/playwright';

/** MetaMask's local unlock password in the CI browser ('' outside CI). */
export const LIVE_WALLET_PASSWORD = process.env.SYNPRESS_WALLET_PASSWORD ?? '';

export default defineWalletSetup(LIVE_WALLET_PASSWORD, async (context, walletPage) => {
  const password = process.env.SYNPRESS_WALLET_PASSWORD ?? '';
  const phrase = (process.env.SYNPRESS_SEED_PHRASE ?? '').trim().split(/\s+/).join(' ');
  if (password === '') throw new Error('live wallet setup: SYNPRESS_WALLET_PASSWORD is empty');
  if (phrase === '') throw new Error('live wallet setup: SYNPRESS_SEED_PHRASE is empty');
  if (![12, 15, 18, 21, 24].includes(phrase.split(' ').length)) {
    // The word count only; never the words.
    throw new Error(`live wallet setup: the seed phrase has ${phrase.split(' ').length} words, not 12 to 24`);
  }
  const metamask = new MetaMask(context, walletPage, password);
  await metamask.importWallet(phrase);
  // As in the local setup: MetaMask 13.13.1 ends onboarding on "Your wallet is ready!". Its
  // "Open wallet" button first signs in to MetaMask's backend for backup and sync. In CI that
  // backend is reachable, but if the button does nothing, open the home page directly and
  // unlock.
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
  // The switch to Ethereum Sepolia happens in the spec, from the site's page, with the standard
  // wallet_switchEthereumChain request (MetaMask 13 keeps a network per site, and Synpress
  // 4.1.2's network selectors don't match MetaMask 13.13.1's screens).
});
