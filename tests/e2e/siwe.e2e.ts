// SIWE sign-in on fresh3.test with anvil dev account 0 (21-41, the tracer).
//
// The injected test wallet signs through the site's own "Sign in with Ethereum" button.
// The seeded e2e-organizer lands signed in, and the server records the login. The signed
// message names fresh3.test. Its chain line isn't checked: the site signs with chain 1
// whatever the wallet says until Phase 25.
import { expect, test } from '@playwright/test';
import { installTestWallet, signInWithEthereum } from './wallet/test-wallet';
import { siteDrush } from './helpers/site';

interface E2eUser { uid: number; name: string; address: string }
interface E2eRun { runId: string; network: string; mode: string; users: Record<'organizer' | 'approver' | 'member', E2eUser> }

const run = (): E2eRun => JSON.parse(process.env.LN_E2E ?? 'null') as E2eRun;

test('SIWE: the organizer signs in on fresh3.test with dev account 0', async ({ context, page }) => {
  const e2e = run();
  const organizer = e2e.users.organizer;
  const wallet = await installTestWallet(context, { network: e2e.network, account: 0 });
  expect(wallet.address).toBe(organizer.address);

  const start = Math.floor(Date.now() / 1000);
  await signInWithEthereum(page);

  await page.goto('/user');
  await expect(page).toHaveURL(new RegExp(`/user/${organizer.uid}(?:[/?#]|$)`));

  const login = Number(
    siteDrush(['sqlq', `SELECT login FROM users_field_data WHERE uid=${Number(organizer.uid)}`]).trim(),
  );
  expect(login).toBeGreaterThanOrEqual(start);

  expect(wallet.signedMessages).toHaveLength(1);
  const lines = wallet.signedMessages[0].split('\n');
  expect(lines[0]).toBe('fresh3.test wants you to sign in with your Ethereum account:');
  expect(lines[1]).toBe(organizer.address);
  expect(lines).toContain('URI: https://fresh3.test');
});
