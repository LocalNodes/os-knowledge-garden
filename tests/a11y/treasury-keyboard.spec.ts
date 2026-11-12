// 21-29: scripted keyboard checks on the Phase 21 group fund screens
// (criterion 7; 22-UI-SPEC Part 5 items 3 and 4; 21-UI-SPEC T1 to T8 focus
// rules). Trace and video on; one test per rule, each naming its screen.
//
//   - Tab order follows the section order on T1, T3, T5, T6 and T7: Tab from
//     the top reaches every visible interactive element in document order
//     and comes back round; Shift+Tab walks the same stops in reverse.
//   - Focus is always visible, and never hidden off screen or under the chat
//     toggle or any other floating element (SC 2.4.11), at every stop.
//   - Focus after "Refresh balances" is the Balances heading; after linking,
//     the success alert; after a failed submit (T3 "0x123", T5 empty), the
//     error summary.
//   - The unlink modal opens on "Keep group fund linked", traps Tab and
//     Shift+Tab, closes on Escape and gives focus back to "Unlink group fund".
//   - Enter activates the focused primary button.
//   - "Copy ID" is reachable and announces "Copied" once (HTTPS, so the
//     clipboard API is allowed).
//   - 200% zoom (640 CSS px wide) and 320 px wide: no sideways scrolling, and
//     nothing in the content runs past the window's right edge.
//
// helpers/keyboard.ts (22-30) does the Tab walk and reads the focus
// indicator. Each walk is saved as focus-<screen>.json in the results dir.
// Runs as the steward, on 21-28's seed, scenario ok.
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { RESULTS_DIR } from './helpers/axe';
import { expectedTabStops, focused, walkTabOrder } from './helpers/keyboard';
import { drush, forget, openFund, seedTreasury, setScenario, type Treasury } from './helpers/treasury';

test.use({ trace: 'on', video: 'on', viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
test.describe.configure({ timeout: 240_000 });

let t: Treasury;

test.beforeAll(() => {
  setScenario('ok');
  t = seedTreasury();
  forget(t.safes.filled);
  drush(['sql:query', "DELETE FROM flood WHERE event = 'group_treasury.refresh'"]);
});

test.afterAll(() => {
  setScenario('ok');
});

test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'steward', 'runs as the steward');
});

/** Deletes a linked-then-unlinked test account and its approval settings. */
function deleteAccount(address: string): void {
  drush(['php:eval', `$etm = \\Drupal::entityTypeManager();
foreach ($etm->getStorage('safe_account')->loadByProperties([]) as $a) {
  if (strtolower((string) $a->getSafeAddress()) !== strtolower('${address}')) { continue; }
  $configs = $etm->getStorage('safe_configuration')->loadByProperties(['safe_account_id' => $a->id()]);
  $etm->getStorage('safe_configuration')->delete($configs);
  $a->delete();
  print "deleted " . $a->id() . "\\n";
}`]);
}

function save(name: string, data: unknown): void {
  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(join(RESULTS_DIR, name), JSON.stringify(data, null, 2) + '\n');
}

/** The label a keyboard user hears for the focused element. */
function activeLabel(page: Page): Promise<string> {
  return page.evaluate(() => {
    const a = document.activeElement as HTMLInputElement | null;
    return ((a?.getAttribute('aria-label') || a?.value || a?.textContent || '') as string).replace(/\s+/g, ' ').trim();
  });
}

/** Presses Tab from the top of the page until the focused label matches. */
async function tabTo(page: Page, label: RegExp, cap = 150): Promise<void> {
  await page.evaluate(() => {
    (document.activeElement as HTMLElement | null)?.blur();
    window.scrollTo(0, 0);
  });
  for (let i = 0; i < cap; i++) {
    await page.keyboard.press('Tab');
    if (label.test(await activeLabel(page))) {
      return;
    }
  }
  throw new Error(`Tab never reached ${label} in ${cap} presses`);
}

/** The Tab walk of one screen state, with 22-30's checks. */
async function walk(page: Page, screen: string): Promise<void> {
  const expected = await expectedTabStops(page);
  const result = await walkTabOrder(page);
  const ours = <T extends { preexisting: string | null }>(list: T[]) => list.filter((s) => !s.preexisting);
  const forwardIds = ours(result.forward).map((s) => s.path);
  const expectedIds = ours(expected).map((s) => s.path);
  const backwardIds = ours(result.backward).map((s) => s.path).reverse();
  const missing = ours(expected).filter((e) => !forwardIds.includes(e.path));
  const extra = ours(result.forward).filter((s) => !expectedIds.includes(s.path));
  const noIndicator = ours(result.forward).filter((s) => !s.indicator);
  const hidden = [
    ...ours(result.forward).map((s) => ({ ...s, key: 'Tab' })),
    ...ours(result.backward).map((s) => ({ ...s, key: 'Shift+Tab' })),
  ].filter((s) => !s.visibleInViewport);
  save(`focus-${screen}.json`, {
    screen,
    url: page.url(),
    recorded: new Date().toISOString(),
    wrapped: result.wrapped,
    wrappedBack: result.wrappedBack,
    stops: forwardIds.length,
    expectedStops: expectedIds.length,
    preexistingComponents: [...new Set(result.forward.filter((s) => s.preexisting).map((s) => s.preexisting))],
    missing,
    extra,
    orderMatchesDom: JSON.stringify(forwardIds) === JSON.stringify(expectedIds),
    shiftTabMatches: JSON.stringify(backwardIds) === JSON.stringify(forwardIds),
    noIndicator,
    hiddenWhenFocused: hidden,
    forward: result.forward,
  });
  expect.soft(result.wrapped, `${screen}: Tab comes back round (no trap)`).toBe(true);
  expect.soft(result.wrappedBack, `${screen}: Shift+Tab comes back round`).toBe(true);
  expect.soft(missing.map((s) => s.name), `${screen}: every visible interactive element is reached`).toEqual([]);
  expect.soft(extra.map((s) => s.name), `${screen}: Tab stops only on visible interactive elements`).toEqual([]);
  expect.soft(forwardIds, `${screen}: Tab follows the section (document) order`).toEqual(expectedIds);
  expect.soft(backwardIds, `${screen}: Shift+Tab walks the same stops in reverse`).toEqual(forwardIds);
  expect.soft(noIndicator.map((s) => `${s.tag}#${s.id} "${s.name}"`), `${screen}: every stop shows focus`).toEqual([]);
  expect.soft(hidden.map((s) => `${s.key}: ${s.tag}#${s.id} "${s.name}" (under ${s.coveredBy})`), `${screen}: no stop is hidden or under the chat toggle`).toEqual([]);
}

test.describe('Tab order, visible focus, nothing under the chat toggle', () => {
  test('T1 group fund tab (organizer, filled)', async ({ page }) => {
    await openFund(page, t.groups.filled);
    await walk(page, 'T1-filled');
  });

  test('T3 link form', async ({ page }) => {
    await page.goto(`/group/${t.groups.empty}/treasury/link`);
    await walk(page, 'T3-link');
  });

  test('T5 propose form', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/propose`);
    await walk(page, 'T5-propose');
  });

  test('T6 payment details (approver)', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.approve}`);
    await expect(page.getByRole('button', { name: 'Approve payment' })).toBeVisible();
    await walk(page, 'T6-approver');
  });

  test('T7 approval settings', async ({ page }) => {
    await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}`);
    await expect(page.getByRole('button', { name: 'Propose this change' })).toBeVisible();
    await walk(page, 'T7-settings');
  });
});

test.describe('Focus after an action', () => {
  test('T1: after "Refresh balances", focus is on the Balances heading', async ({ page }) => {
    drush(['sql:query', "DELETE FROM flood WHERE event = 'group_treasury.refresh'"]);
    await openFund(page, t.groups.filled);
    await tabTo(page, /^Refresh balances$/);
    await Promise.all([page.waitForURL(/refreshed=1/, { timeout: 30_000 }), page.keyboard.press('Enter')]);
    await expect(page.locator('#group-fund-balances')).toBeFocused({ timeout: 10_000 });
    expect((await focused(page)).id).toBe('group-fund-balances');
  });

  test('T3: after linking, focus is on the success alert (then unlinked again)', async ({ page }) => {
    // A fresh, valid ID each run: the fixture answers every address as a
    // shared account, so linking it succeeds; the test unlinks it at the end.
    const hex = [...Array(40)].map(() => '0123456789abcdef'[Math.floor(Math.random() * 16)]).join('');
    await page.goto(`/group/${t.groups.empty}/treasury/link`);
    await page.getByLabel('Account ID').fill(`0x${hex}`);
    await tabTo(page, /^Link account$/);
    await Promise.all([page.waitForURL(/[?&]linked=1/, { timeout: 30_000 }), page.keyboard.press('Enter')]);
    const alert = page.locator('[data-group-treasury-focus]');
    await expect(alert).toContainText('The group fund is linked.');
    await expect(alert).toBeFocused({ timeout: 10_000 });
    // Unlink, by keyboard, so the seeded "empty" group is empty again.
    await tabTo(page, /^Unlink group fund$/);
    await page.keyboard.press('Enter');
    const dialog = page.locator('.ui-dialog');
    await expect(dialog).toBeVisible();
    await dialog.locator('.ui-dialog-buttonpane').getByRole('button', { name: 'Unlink group fund' }).focus();
    await Promise.all([page.waitForURL(/[?&]unlinked=1/, { timeout: 30_000 }), page.keyboard.press('Enter')]);
    await expect(page.getByRole('heading', { level: 2, name: "This group doesn't have a group fund yet" })).toBeVisible();
    // Unlinking keeps the account, which then counts as the steward's
    // personal one (deferred-items: an unlinked group fund counts as
    // personal again) and would hide the T4 personal create form. Delete it.
    deleteAccount(`0x${hex}`);
  });

  test('T3: after a failed submit ("0x123"), focus is on the error summary', async ({ page }) => {
    await page.goto(`/group/${t.groups.empty}/treasury/link`);
    await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
    await page.getByLabel('Account ID').fill('0x123');
    await tabTo(page, /^Link account$/);
    await Promise.all([page.waitForLoadState('load'), page.keyboard.press('Enter')]);
    const summary = page.locator('.safe-error-summary');
    await expect(summary).toContainText('Fix these to continue');
    await expect(summary).toBeFocused({ timeout: 10_000 });
  });

  test('T5: after an empty submit, focus is on the error summary', async ({ page }) => {
    await page.goto(`/group/${t.groups.filled}/treasury/propose`);
    await page.evaluate(() => document.querySelectorAll('form').forEach((f) => { f.noValidate = true; }));
    await tabTo(page, /^Propose payment$/);
    await Promise.all([page.waitForLoadState('load'), page.keyboard.press('Enter')]);
    const summary = page.locator('.safe-error-summary');
    await expect(summary).toContainText('Fix these to continue');
    await expect(summary).toBeFocused({ timeout: 10_000 });
  });
});

test.describe('Modal, Enter and Copy ID', () => {
  test('T1 unlink modal: opens on Keep, traps Tab, Escape closes, focus returns', async ({ page }) => {
    await openFund(page, t.groups.filled);
    await tabTo(page, /^Unlink group fund$/);
    await page.keyboard.press('Enter');
    const dialog = page.locator('.ui-dialog');
    await expect(dialog).toBeVisible();
    // Initial focus on the safe choice (T1 Focus).
    await expect.poll(() => activeLabel(page)).toBe('Keep group fund linked');
    const inside = () => page.evaluate(() => Boolean(document.activeElement?.closest('.ui-dialog')));
    for (const key of ['Tab', 'Shift+Tab']) {
      for (let i = 0; i < 8; i++) {
        await page.keyboard.press(key);
        expect(await inside(), `${key} ${i + 1} stays in the modal`).toBe(true);
      }
    }
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect.poll(() => activeLabel(page)).toBe('Unlink group fund');
  });

  test('T1: Enter activates the focused primary button ("Propose a payment")', async ({ page }) => {
    await openFund(page, t.groups.filled);
    await tabTo(page, /^Propose a payment$/);
    await expect(page.locator(':focus')).toHaveClass(/btn-primary/);
    await Promise.all([page.waitForURL(/\/treasury\/propose$/), page.keyboard.press('Enter')]);
    await expect(page.getByRole('heading', { level: 1, name: 'Propose a Payment', exact: true })).toBeVisible();
  });

  test('T1: "Copy ID" is reachable and announces "Copied" once (HTTPS)', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await openFund(page, t.groups.filled);
    await page.evaluate(() => {
      (window as unknown as { __said: string[] }).__said = [];
      const region = document.getElementById('drupal-live-announce');
      const watch = (el: Element) => new MutationObserver(() => {
        (window as unknown as { __said: string[] }).__said.push((el.textContent ?? '').trim());
      }).observe(el, { childList: true, characterData: true, subtree: true });
      if (region) {
        watch(region);
      }
      else {
        new MutationObserver(() => {
          const r = document.getElementById('drupal-live-announce');
          if (r && !(r as HTMLElement).dataset.watched) {
            (r as HTMLElement).dataset.watched = '1';
            watch(r);
            (window as unknown as { __said: string[] }).__said.push((r.textContent ?? '').trim());
          }
        }).observe(document.body, { childList: true });
      }
    });
    await tabTo(page, /^Copy ID/);
    const name = await activeLabel(page);
    expect(name).toMatch(/^Copy ID for /);
    await page.keyboard.press('Enter');
    await expect.poll(() => page.evaluate(() => (window as unknown as { __said: string[] }).__said.filter((s) => s === 'Copied').length)).toBe(1);
    await page.waitForTimeout(1_500);
    expect(await page.evaluate(() => (window as unknown as { __said: string[] }).__said.filter((s) => s === 'Copied').length), '"Copied" is announced once').toBe(1);
    save('copy-id.json', { button: name, announcements: await page.evaluate(() => (window as unknown as { __said: string[] }).__said) });
  });
});

test.describe('Reflow: 200% zoom and 320 px', () => {
  const screens = (t: Treasury) => [
    ['T1', `/group/${t.groups.filled}/treasury`],
    ['T3', `/group/${t.groups.empty}/treasury/link`],
    ['T5', `/group/${t.groups.filled}/treasury/propose`],
    ['T6', `/group/${t.groups.filled}/treasury/transaction/${t.payments.approve}`],
    ['T7', `/user/${t.steward}/safe-accounts/${t.safes.filled}`],
    ['T8', `/user/${t.steward}/safe-accounts`],
  ] as const;

  for (const [label, width] of [['200% zoom (640 CSS px)', 640], ['320 px', 320]] as const) {
    test(`T1, T3, T5, T6, T7, T8 at ${label}: no sideways scrolling`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      const rows: { screen: string; scrollWidth: number; innerWidth: number; overflow: string[] }[] = [];
      for (const [screen, url] of screens(t)) {
        await page.goto(url);
        await page.waitForLoadState('load');
        const r = await page.evaluate(() => {
          const content = document.querySelector('#block-socialblue-content') ?? document.body;
          const overflow = [...content.querySelectorAll('*')]
            .filter((el) => el.getBoundingClientRect().right > window.innerWidth + 1 && getComputedStyle(el).position !== 'fixed')
            .filter((el) => !el.closest('.visually-hidden, .sr-only, [hidden]'))
            // Skip what sits inside a visually hidden box (1px, overflow
            // hidden), such as a stacked table's header row.
            .filter((el) => {
              for (let a = el.parentElement; a && a !== content; a = a.parentElement) {
                if (getComputedStyle(a).overflow === 'hidden' && a.getBoundingClientRect().width <= 1) return false;
              }
              return true;
            })
            .slice(0, 5)
            .map((el) => `${el.tagName.toLowerCase()}.${(el.className && typeof el.className === 'string' ? el.className : '').split(' ').join('.')}`);
          return { scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth, overflow };
        });
        rows.push({ screen, ...r });
        await page.screenshot({ path: test.info().outputPath(`${screen}-${width}.png`), fullPage: true });
      }
      save(`reflow-${width}.json`, rows);
      for (const r of rows) {
        expect.soft(r.scrollWidth, `${r.screen} at ${width} px scrolls sideways (${r.overflow.join(', ')})`).toBeLessThanOrEqual(r.innerWidth + 1);
        // Nothing in the content is cut off at the right edge either (a
        // clipped table column scrolls nothing but hides its content).
        expect.soft(r.overflow, `${r.screen} at ${width} px: content wider than the window`).toEqual([]);
      }
    });
  }
});
