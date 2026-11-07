// MetaMask wallet setup for the local recording only (21-45 Task A).
//
// The phrase below is anvil's well-known public dev mnemonic. It holds nothing, and it is only
// ever used against the loopback anvil chain on the .test host. It is the only seed phrase in
// this repo. The live suite (Task B) reads its own seed in its own setup file, from CI secrets;
// this file never reads any environment variable.
//
// The password is a local-only MetaMask unlock password for this throwaway profile.
import { defineWalletSetup } from '@synthetixio/synpress';
import { MetaMask } from '@synthetixio/synpress/playwright';
import { ACCOUNT_DEV0, ACCOUNT_DEV1, LOCAL_NETWORK } from '../../browser-args.ts';
import { addAccount, addNetwork, switchAccount } from '../../metamask-steps.ts';

export const LOCAL_WALLET_PASSWORD = 'local-anvil-only';

export default defineWalletSetup(LOCAL_WALLET_PASSWORD, async (context, walletPage) => {
  const metamask = new MetaMask(context, walletPage, LOCAL_WALLET_PASSWORD);
  await metamask.importWallet('test test test test test test test test test test test junk');
  // MetaMask 13.13.1 ends onboarding on "Your wallet is ready!". Its "Open wallet" button
  // first signs in to MetaMask's backend for backup and sync, which the host block stops
  // ("failed to generate nonce: Failed to fetch"), so the button does nothing here. The wallet
  // itself is ready: open its home page directly, unlocking if MetaMask asks.
  await walletPage.waitForURL(/#onboarding\/completion/, { timeout: 60_000 }).catch(() => undefined);
  const openWallet = walletPage.getByRole('button', { name: 'Open wallet' });
  if (await openWallet.isVisible()) await openWallet.click();
  await walletPage.waitForURL((url) => !url.hash.startsWith('#onboarding'), { timeout: 15_000 }).catch(() => undefined);
  if (walletPage.url().includes('#onboarding')) {
    await walletPage.goto(walletPage.url().replace(/#.*$/, '#'));
    const unlock = walletPage.getByTestId('unlock-password');
    if (await unlock.waitFor({ state: 'visible', timeout: 10_000 }).then(() => true, () => false)) {
      await unlock.fill(LOCAL_WALLET_PASSWORD);
      await walletPage.getByTestId('unlock-submit').click();
    }
  }
  await walletPage.getByTestId('account-menu-icon').waitFor({ state: 'visible', timeout: 60_000 });
  const extensionId = new URL(walletPage.url()).host;
  // Synpress 4.1.2's account and network helpers don't match MetaMask 13.13.1's screens, so
  // these are our steps (metamask-steps.ts). Dev account 1 (the approver) is "Account 2".
  await addAccount(walletPage, extensionId, ACCOUNT_DEV1);
  await switchAccount(walletPage, ACCOUNT_DEV0);
  await addNetwork(walletPage, extensionId, { ...LOCAL_NETWORK });
});
