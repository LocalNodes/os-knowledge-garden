// The browser-wallet sign-in choice on /user/login, shared by the live tests (live-sepolia.e2e.ts,
// governance/judge-wallet.ts) and proven on a local fixture site by governance/labels.spec.ts.
//
// The sign-in page keeps siwe_login's own wallet button (#siwe-login-button) in the page but
// hidden (localnodes_web3 css/signin.css). It offers a visible "Use a browser wallet" choice
// ([data-ln-wallet], SignIn::walletChoice()) instead, and js/signin.js hands that choice's click
// to the hidden button once siwe_login's script and ethers have loaded. When they have not, the
// choice stays hidden and the note under it ([data-ln-wallet-note]) says wallet sign-in is not
// available. Runs 43709180949 (nightly) and 43793452239 (judge) waited for the hidden button to
// be visible and never got past sign-in.
import type { Page } from '@playwright/test';

/** The site wrote no wallet button at all (siwe_login's wallet sign-in is off). */
export const NO_WALLET_BUTTON = 'sign-in: the site\'s wallet button (#siwe-login-button) is not on the page within 30 s';
/** The site wrote the button but its scripts did not load: a product finding, not a test one. */
export const WALLET_UNAVAILABLE = 'sign-in: the site says browser-wallet sign-in is not available (its wallet scripts did not load)';
/** The button is there but disabled: js/signin.js would drop the click without a word. */
export const WALLET_BUTTON_DISABLED = 'sign-in: the site\'s wallet button (#siwe-login-button) stayed disabled for 30 s';
/** The choice showed but the click did not land (Playwright's own error would quote the page). */
export const WALLET_CHOICE_CLICK = 'sign-in: the "Use a browser wallet" choice could not be clicked within 30 s';
/** Neither the choice nor the note showed. */
export const NO_WALLET_CHOICE = 'sign-in: the "Use a browser wallet" choice did not show within 30 s';

/** The note's own words when the wallet scripts are missing (localnodes_web3 js/signin.js). */
const UNAVAILABLE_TEXT = /not available right now/;

/**
 * Presses "Use a browser wallet" on the current page, which hands the click to siwe_login's
 * hidden button. The caller has opened the sign-in page and handles the wallet prompts that
 * follow. Errors carry fixed text only.
 */
export async function pressWalletChoice(page: Page, timeoutMs = 30_000): Promise<void> {
  // The button is server-rendered: it is there at load or not at all.
  await page.locator('#siwe-login-button').waitFor({ state: 'attached', timeout: timeoutMs }).catch(() => {
    throw new Error(NO_WALLET_BUTTON);
  });
  await page.locator('#siwe-login-button:not([disabled])').waitFor({ state: 'attached', timeout: timeoutMs }).catch(() => {
    throw new Error(WALLET_BUTTON_DISABLED);
  });
  const choice = page.locator('[data-ln-wallet]').filter({ visible: true }).first();
  const unavailable = page.locator('[data-ln-wallet-note]').filter({ hasText: UNAVAILABLE_TEXT, visible: true }).first();
  const shown = await Promise.race([
    choice.waitFor({ state: 'visible', timeout: timeoutMs }).then(() => 'choice' as const),
    unavailable.waitFor({ state: 'visible', timeout: timeoutMs }).then(() => 'unavailable' as const),
  ]).catch(() => 'none' as const);
  if (shown === 'unavailable') {
    throw new Error(WALLET_UNAVAILABLE);
  }
  if (shown === 'none') {
    throw new Error(NO_WALLET_CHOICE);
  }
  await choice.click({ timeout: timeoutMs }).catch(() => {
    throw new Error(WALLET_CHOICE_CLICK);
  });
}
