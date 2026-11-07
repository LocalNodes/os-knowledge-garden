// 21-29: the recorded walkthrough of every Phase 21 group fund state
// (criterion 7, ruling R-H: recorded evidence replaces the human pass).
//
// Every state 21-28 audits (helpers/treasury-states.ts) is replayed here
// with a Playwright trace and a video, a pause on the state's key content so
// the video can be read, and axe on each (checkA11y, 0 serious or critical).
// The plain member's states come first, as a11y-member; then the organizer's,
// as the steward. e2e-evidence/WALKTHROUGH.md indexes the recordings.
//
// Runs in the steward project only. The member block switches its session
// with test.use({ storageState }), so the steward block's hooks (the
// no-networks block deletes and restores the registry) never run twice.
//
// Run from the lane site's dir with --output <fresh3-work>/.planning/phases/
// 21-real-safe-reads-balances/e2e-evidence/runs/<RID>/walkthrough, which is
// gitignored.
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test, expect, type Page } from '@playwright/test';
import { focused } from './helpers/keyboard';
import { memberStates, stewardStates } from './helpers/treasury-states';
import { drush } from './helpers/treasury';

const AUTH_DIR = join(dirname(fileURLToPath(import.meta.url)), '.auth');
const PAUSE_MS = Number(process.env.LN_WALKTHROUGH_PAUSE_MS ?? 1500);

test.use({ trace: 'on', video: 'on', viewport: { width: 1280, height: 900 } });
test.describe.configure({ timeout: 120_000 });

/** Brings the state's key content into view and holds it for the video. */
async function show(page: Page, id: string): Promise<void> {
  // The index (e2e-evidence/WALKTHROUGH.md) finds the state's axe JSON by this.
  test.info().annotations.push({ type: 'axe', description: `axe-${id}.json` });
  // The first visible block that carries the state (a card, a dialog, a
  // panel, an alert, or the form).
  const key = page.locator('.group-fund-card, .ui-dialog, .safe-receipt-panel, [role="alert"], .alert, form').filter({ visible: true }).first();
  await key.scrollIntoViewIfNeeded({ timeout: 2_000 }).catch(() => null);
  await page.waitForTimeout(PAUSE_MS);
}

/** Clears the steward's and member's refresh flood, so a refresh is allowed. */
function clearRefreshFlood(): void {
  drush(['sql:query', "DELETE FROM flood WHERE event = 'group_treasury.refresh'"]);
}

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'steward', 'runs in the steward project (the member block switches session)');
});

test.describe('member', () => {
  test.use({ storageState: join(AUTH_DIR, 'member.json') });

  memberStates({
    show,
    after: {
      // Task 1 tracer: the balance and its stamp read in the video, then the
      // keyboard: Tab reaches "Refresh balances" in section order with a
      // visible focus, and after the refresh focus lands on "Balances".
      'treasury-member-filled': async (page) => {
        const stamp = page.locator('.group-fund-card__stamp');
        await stamp.scrollIntoViewIfNeeded();
        await expect(stamp).toHaveText(/^Updated/);
        await page.waitForTimeout(PAUSE_MS * 2);
        clearRefreshFlood();
        await page.evaluate(() => window.scrollTo(0, 0));
        // From the top: Tab passes the Balances section (the stamp row) and
        // reaches "Refresh balances" before anything in "Who approves
        // payments" (section order, T1 items 5 to 7).
        const activeLabel = () => page.evaluate(() => {
          const a = document.activeElement as HTMLInputElement | null;
          return ((a?.value || a?.textContent || '') as string).replace(/\s+/g, ' ').trim();
        });
        let reached = false;
        for (let i = 0; i < 120 && !reached; i++) {
          await page.keyboard.press('Tab');
          reached = /^Refresh balances$/.test(await activeLabel());
        }
        expect(reached, 'Tab reaches "Refresh balances"').toBe(true);
        const refresh = page.getByRole('button', { name: 'Refresh balances', exact: true }).first();
        await expect(refresh).toBeFocused();
        // Visible focus: an outline or a box shadow is drawn.
        await page.waitForTimeout(400);
        const sig = (el: Element) => {
          const s = getComputedStyle(el);
          return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.boxShadow, s.borderColor, s.backgroundColor, s.color, s.textDecorationLine].join('|');
        };
        const focusedStyle = await refresh.evaluate(sig);
        await page.evaluate(() => (document.activeElement as HTMLElement).blur());
        await page.waitForTimeout(400);
        const blurredStyle = await refresh.evaluate(sig);
        expect(focusedStyle, 'a focus indicator shows on "Refresh balances"').not.toBe(blurredStyle);
        await refresh.focus();
        // Section order: the button sits after "Balances" and before "Who
        // approves payments" in the document.
        const order = await page.evaluate(() => {
          const b = document.activeElement as Element;
          const h3 = [...document.querySelectorAll('h3')];
          const balances = h3.find((x) => x.textContent?.trim() === 'Balances');
          const approvers = h3.find((x) => x.textContent?.trim() === 'Who approves payments');
          return Boolean(balances && approvers
            && (balances.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
            && (b.compareDocumentPosition(approvers) & Node.DOCUMENT_POSITION_FOLLOWING));
        });
        expect(order, '"Refresh balances" sits between "Balances" and "Who approves payments"').toBe(true);
        const approvers = page.getByRole('heading', { level: 3, name: 'Who approves payments' });
        await expect(approvers).toBeVisible();
        await page.waitForTimeout(PAUSE_MS);
        await Promise.all([page.waitForURL(/refreshed=1/, { timeout: 30_000 }), page.keyboard.press('Enter')]);
        await expect(page.locator('#group-fund-balances')).toBeFocused({ timeout: 10_000 });
        const f = await focused(page);
        expect(f.id).toBe('group-fund-balances');
        await expect(page.locator('.group-fund-card__stamp')).toHaveText('Updated just now');
        await page.waitForTimeout(PAUSE_MS);
      },
    },
  });
});

test.describe('organizer', () => {
  stewardStates({ show });
});
