// The Phase 21 group fund states (21-UI-SPEC Part D, T1 to T8), shared by
// 21-28's axe specs and 21-29's recorded walkthrough.
//
// stewardStates() and memberStates() declare the tests. treasury.spec.ts and
// treasury-member.spec.ts call them with no options; treasury-walkthrough
// .spec.ts calls them with a trace and a video on, a pause on each state's
// key content (show), and extra checks after a state's audit (after).
//
// The steward's states and their order are 21-28's (see treasury.spec.ts's
// header). The member's states run as a11y-member.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test, expect, type Page } from '@playwright/test';
import { checkA11y, type CheckA11yOptions } from './axe';
import {
  REPO_ROOT,
  SCOPE,
  drush,
  expectSuccessfulRead,
  forget,
  openFund,
  seedTreasury,
  setScenario,
  type Treasury,
} from './treasury';

export interface StateOptions {
  /** Runs before each audit, with "<screen>-<state>" (the walkthrough's pause). */
  show?: (page: Page, id: string) => Promise<void>;
  /** Extra checks after an audit, by "<screen>-<state>". */
  after?: Record<string, (page: Page, t: Treasury) => Promise<void>>;
}

/** Runs one audit with the caller's pause and extra checks around it. */
function auditor(o: StateOptions, treasury: () => Treasury) {
  return async (page: Page, screen: string, state: string, scope: CheckA11yOptions) => {
    await o.show?.(page, `${screen}-${state}`);
    await checkA11y(page, screen, state, scope);
    await o.after?.[`${screen}-${state}`]?.(page, treasury());
  };
}

/** The organizer's states (21-28's treasury.spec.ts), as the steward. */
export function stewardStates(o: StateOptions = {}): void {
  let t: Treasury;
  const audit = auditor(o, () => t);


  // The fixture Safe's first owner: the steward's linked wallet.
  const OWNER = '0x3dd578a97f758366e6932c0cd9a3e5478629b337';
  // A valid checksummed ID that no seeded fund uses (the fixture's second owner).
  const UNLINKED_ID = '0xC3F45C10610aA25534F38040Cc2a5DB2bFC65890';
  // ethereum_sepolia's chain id, as eth_chainId answers it.
  const SEPOLIA_CHAIN = '0xaa36a7';

  test.beforeAll(() => {
    setScenario('ok');
    t = seedTreasury();
  });

  test.afterAll(() => {
    setScenario('ok');
    if (t) {
      forget(t.safes.filled);
    }
  });

  /** Lets a form post with empty required fields, so the server validates. */
  async function skipBrowserValidation(page: Page): Promise<void> {
    await page.evaluate(() => document.querySelectorAll('form').forEach((form) => {
      form.noValidate = true;
    }));
  }

  /** Mounts a flow panel next to a button, as transaction-manager.js does, at step 1. */
  async function panelAtFirstStep(page: Page, buttonSelector: string, kind: 'approval' | 'receipt', heading: string): Promise<void> {
    await page.evaluate(({ buttonSelector, kind, heading }) => {
      const button = document.querySelector(buttonSelector) as HTMLElement;
      const actions = button.closest('.safe-payment-actions');
      const container = (actions && actions.querySelector('[data-safe-flow-panel]'))
        || document.querySelector('[data-safe-flow-panel]')
        || button.parentNode;
      (container as HTMLElement).hidden = false;
      // @ts-expect-error Drupal is the page's global.
      Drupal.safeSmartAccounts.flowPanel.mount(container, { kind, heading: Drupal.t(heading) }).setState(1);
    }, { buttonSelector, kind, heading });
  }

  test.describe('T1 group fund tab, T5, T6 and T7 with a successful read', () => {
    test('T1 filled', async ({ page }) => {
      setScenario('ok');
      forget(t.safes.filled);
      await openFund(page, t.groups.filled);
      await expectSuccessfulRead(page);
      await audit(page, 'treasury', 'filled', SCOPE);
    });

    test('T5 propose: empty', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/propose`);
      await expect(page.getByRole('heading', { level: 1, name: 'Propose a Payment', exact: true })).toBeVisible();
      await audit(page, 'propose', 'empty', SCOPE);
    });

    test('T5 propose: error after an empty submit', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/propose`);
      await skipBrowserValidation(page);
      await page.getByRole('button', { name: 'Propose payment' }).click();
      await expect(page.getByText('Fix these to continue')).toBeVisible();
      await expect(page.getByText('Choose who receives the payment.').first()).toBeVisible();
      await audit(page, 'propose', 'error', SCOPE);
    });

    test('T6 payment: the approver can approve', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.approve}`);
      await expect(page.getByRole('button', { name: 'Approve payment' })).toBeVisible();
      await audit(page, 'payment', 'approver', SCOPE);
    });

    test('T6 payment: ready, the organizer can send', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.ready}`);
      await expect(page.getByRole('button', { name: 'Send payment' })).toBeVisible();
      await expect(page.getByText('This step needs a small network fee, paid from your own wallet. Members never pay it.')).toBeVisible();
      await audit(page, 'payment', 'ready-organizer', SCOPE);
    });

    test('T6 payment: the approval panel at its first step', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.approve}`);
      await expect(page.getByRole('button', { name: 'Approve payment' })).toBeVisible();
      await panelAtFirstStep(page, '.safe-transaction-sign', 'approval', 'Approving this payment');
      const panel = page.locator('.safe-receipt-panel[data-safe-panel-kind="approval"]');
      await expect(panel).toHaveAttribute('data-safe-panel-state', '1');
      await expect(panel.locator('[aria-current="step"]')).toContainText('Waiting for your approval');
      await audit(page, 'payment', 'approval-panel-step-1', SCOPE);
    });

    test('T6 payment: the send receipt panel at its first step', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.ready}`);
      await expect(page.getByRole('button', { name: 'Send payment' })).toBeVisible();
      await panelAtFirstStep(page, '.safe-transaction-execute', 'receipt', 'Sending the payment');
      const panel = page.locator('.safe-receipt-panel[data-safe-panel-kind="receipt"]');
      await expect(panel).toHaveAttribute('data-safe-panel-state', '1');
      await expect(panel.locator('[aria-current="step"]')).toContainText('Waiting for your approval');
      await audit(page, 'payment', 'send-panel-step-1', SCOPE);
    });

    test('T6 payment: waiting for the public record, after a reload', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.sending}/treasury/transaction/${t.payments.waiting}`);
      await page.reload();
      await expect(page.getByText("Waiting for the public record to confirm this. We'll check again when you open this page.")).toBeVisible();
      await expect(page.getByRole('button', { name: 'Send payment' })).toHaveCount(0);
      await audit(page, 'payment', 'waiting-after-reload', SCOPE);
    });

    test('T7 approval settings', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}`);
      await expect(page.getByRole('button', { name: 'Save approval settings' })).toBeVisible();
      await expect(page.getByText('Right now: needs 2 of 2 organizers to approve a payment.')).toBeVisible();
      await audit(page, 'approval-settings', 'filled', SCOPE);
    });

    // 21-25 fix 14: the save is posted with its own button, with JavaScript
    // on. Before the fix, safe-configuration-manager.js disabled the button
    // inside the submit event: the post had no op for it, Drupal took the
    // first "Remove …" button, nothing was saved, no message showed and an
    // approver row went. An unchanged save reaches "Nothing changed" only when
    // Save is the triggering button, and it writes nothing.
    test('T7 approval settings: Save with JavaScript on posts the save (fix 14)', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}`);
      const save = page.getByRole('button', { name: 'Save approval settings' });
      await expect(save).toBeVisible();
      // The behaviour is attached (the form is marked once processed).
      await expect(page.locator('#safe-account-manage-form.safe-config-processed')).toHaveCount(1);
      const removes = page.getByRole('button', { name: /^Remove / });
      const rowsBefore = await removes.count();
      const posted = page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname === `/user/${t.steward}/safe-accounts/${t.safes.filled}`);
      await save.click();
      const body = new URLSearchParams((await posted).postData() ?? '');
      expect(body.getAll('op')).toContain('Save approval settings');
      expect([...body.keys()].filter((k) => /remove/i.test(k))).toEqual([]);
      await expect(page.getByText('Nothing changed, so nothing was proposed.')).toBeVisible();
      await expect(removes).toHaveCount(rowsBefore);
    });

    test('T1 unlink modal open', async ({ page }) => {
      setScenario('ok');
      await openFund(page, t.groups.filled);
      await page.getByRole('link', { name: 'Unlink group fund' }).click();
      const dialog = page.locator('.ui-dialog');
      await expect(dialog).toBeVisible();
      await expect(dialog.getByRole('button', { name: 'Keep group fund linked' })).toBeVisible();
      // Axe reads colours mid fade-in otherwise: wait for the dialog's
      // animations and transitions to finish.
      // (A cancelled animation rejects its finished promise, so poll instead.)
      await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'));
      await expect.poll(() => dialog.evaluate((el) => getComputedStyle(el).opacity)).toBe('1');
      // The dialog is appended to body, outside the content block.
      await audit(page, 'treasury', 'unlink-modal', { include: ['.ui-dialog'] });
      await page.keyboard.press('Escape');
      await expect(dialog).toHaveCount(0);
    });

    test('T1 loading skeleton', async ({ page }) => {
      setScenario('ok');
      // big_pipe is not enabled on this distribution, so the card's lazy
      // builder renders in the page response and its skeleton preview never
      // streams. The preview is rendered by the server here (the lazy
      // builder's #lazy_builder_preview) and put in place of the card on the
      // real page, which carries the card's CSS.
      const preview = drush(['php:eval', `$b = \\Drupal::service('group_treasury.view_builder');
  $g = \\Drupal::entityTypeManager()->getStorage('group')->load(${t.groups.filled});
  $s = \\Drupal::entityTypeManager()->getStorage('safe_account')->load(${t.safes.filled});
  $p = $b->placeholder($g, $s)['#lazy_builder_preview'];
  print \\Drupal::service('renderer')->renderInIsolation($p);`]).trim();
      expect(preview).toContain('group-fund-card--loading');
      await openFund(page, t.groups.filled);
      await page.evaluate((html) => {
        const card = document.querySelector('.group-fund-card') as HTMLElement;
        card.outerHTML = html;
      }, preview);
      const card = page.locator('.group-fund-card--loading');
      await expect(card).toHaveAttribute('aria-busy', 'true');
      // Nothing for 400 ms, then the skeleton.
      await expect(card.locator('.group-fund-skeleton')).toBeVisible();
      await expect(card.getByText('Loading balances')).toBeAttached();
      await audit(page, 'treasury', 'loading', SCOPE);
    });

    test('T1 refresh refused', async ({ page }) => {
      setScenario('ok');
      await openFund(page, t.groups.filled);
      const notice = page.getByText("You've refreshed a lot just now. Try again in a minute.");
      // 6 refreshes per 60 s per person; the 7th is refused.
      for (let i = 0; i < 8 && !(await notice.isVisible()); i++) {
        await page.getByRole('button', { name: 'Refresh balances' }).first().click();
        await page.waitForLoadState('load');
        await expect(page.locator('.group-fund-card--loading')).toHaveCount(0, { timeout: 30_000 });
      }
      await expect(notice).toBeVisible();
      await audit(page, 'treasury', 'refresh-refused', SCOPE);
    });
  });

  test.describe('T1 states with nothing to read', () => {
    test('T1 setup not finished (organizer)', async ({ page }) => {
      await page.goto(`/group/${t.groups.setup}/treasury`);
      await expect(page.getByText("This group fund isn't set up on the public record yet.")).toBeVisible();
      await expect(page.getByRole('link', { name: 'Finish setting up the group fund' })).toBeVisible();
      await audit(page, 'treasury', 'setup-not-finished', SCOPE);
    });

    test("T1 setup didn't finish", async ({ page }) => {
      await page.goto(`/group/${t.groups.failed}/treasury`);
      await expect(page.getByText("Setting up the group fund didn't finish.")).toBeVisible();
      await expect(page.getByText('No group fund was created on the public record.')).toBeVisible();
      await audit(page, 'treasury', 'setup-did-not-finish', SCOPE);
    });

    test('T1 network not set up', async ({ page }) => {
      await page.goto(`/group/${t.groups.retired}/treasury`);
      await expect(page.getByText("This group fund's network isn't set up on this site. An administrator can add it.")).toBeVisible();
      await audit(page, 'treasury', 'network-not-set-up', SCOPE);
    });

    test('T1 empty (organizer)', async ({ page }) => {
      await page.goto(`/group/${t.groups.empty}/treasury`);
      await expect(page.getByRole('heading', { level: 2, name: "This group doesn't have a group fund yet" })).toBeVisible();
      await expect(page.getByRole('link', { name: /Link an account you already have/ })).toBeVisible();
      await audit(page, 'treasury', 'empty-organizer', SCOPE);
    });
  });

  test.describe('T3 link and T4 set-up forms', () => {
    test('T3 link form: empty', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.empty}/treasury/link`);
      await expect(page.getByRole('heading', { level: 1, name: 'Link an Account You Already Have', exact: true })).toBeVisible();
      await audit(page, 'link', 'empty', SCOPE);
    });

    test('T3 link form: format error', async ({ page }) => {
      setScenario('ok');
      await page.goto(`/group/${t.groups.empty}/treasury/link`);
      await skipBrowserValidation(page);
      await page.getByLabel('Account ID').fill('0x123');
      await page.getByRole('button', { name: 'Link account' }).click();
      await expect(page.getByText('Fix these to continue')).toBeVisible();
      await expect(page.getByText("This isn't an account ID. It starts with 0x and has 42 characters.").first()).toBeVisible();
      await audit(page, 'link', 'format-error', SCOPE);
    });

    test('T4 group set-up form', async ({ page }) => {
      await page.goto(`/group/${t.groups.empty}/treasury/create`);
      await expect(page.getByRole('button', { name: 'Set up group fund' })).toBeVisible();
      await audit(page, 'setup', 'group-form', SCOPE);
    });

    test('T4 personal create form', async ({ page }) => {
      await page.goto(`/user/${t.steward}/safe-accounts/create`);
      await expect(page.getByRole('button', { name: 'Create shared account' })).toBeVisible();
      await audit(page, 'setup', 'personal-form', SCOPE);
    });

    test('T4 set-up receipt panel at its first step', async ({ page }) => {
      await page.goto(`/group/${t.groups.empty}/treasury/create`);
      await expect(page.getByRole('button', { name: 'Set up group fund' })).toBeVisible();
      await page.evaluate(() => {
        const container = document.getElementById('safe-deployment-status-container') as HTMLElement;
        container.hidden = false;
        // @ts-expect-error Drupal is the page's global.
        Drupal.safeSmartAccounts.flowPanel.mount(container, { kind: 'receipt', heading: Drupal.t('Setting up the group fund') }).setState(1);
      });
      const panel = page.locator('#safe-deployment-status-container .safe-receipt-panel');
      await expect(panel).toHaveAttribute('data-safe-panel-state', '1');
      await expect(panel.locator('[aria-current="step"]')).toContainText('Waiting for your approval');
      await expect(panel.getByText('Confirm it in your wallet.')).toBeVisible();
      await audit(page, 'setup', 'receipt-panel-step-1', SCOPE);
    });
  });

  test.describe('Wallet panel errors', () => {
    // "Finish setting up the group fund" on the pending fund's own page runs
    // the set-up flow (safe-deployment.js) without saving anything. ("Set up
    // group fund" first saves a pending fund by AJAX, fix 11.)
    const cases = [
      { state: 'no-wallet', provider: 'none', copy: "Your wallet isn't connected in this browser. Connect it, then come back to this page." },
      { state: 'refused', provider: 'refuse', copy: "You didn't confirm it in your wallet. Nothing changed." },
      { state: 'wrong-network', provider: 'wrong-chain', copy: /^Your wallet is on a different network\. Switch it to .+, then finish setting up\.$/ },
    ] as const;

    for (const c of cases) {
      test(`wallet error: ${c.state}`, async ({ page }) => {
        await page.addInitScript(({ provider, owner, chain }) => {
          if (provider === 'none') {
            return;
          }
          const error = (message: string, code: number) => Object.assign(new Error(message), { code });
          // @ts-expect-error A fake EIP-1193 provider.
          window.ethereum = {
            isMetaMask: true,
            request: async ({ method }: { method: string }) => {
              switch (method) {
                case 'eth_chainId':
                  return provider === 'wrong-chain' ? '0x1' : chain;
                case 'net_version':
                  return provider === 'wrong-chain' ? '1' : String(parseInt(chain, 16));
                case 'eth_accounts':
                  return [owner];
                case 'eth_requestAccounts':
                  throw error('User rejected the request.', 4001);
                default:
                  throw error(`Unsupported in this test: ${method}`, 4200);
              }
            },
            on() {},
            removeListener() {},
          };
        }, { provider: c.provider, owner: OWNER, chain: SEPOLIA_CHAIN });
        await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.setup}`);
        await page.getByRole('button', { name: 'Finish setting up the group fund' }).click();
        const alert = page.locator('.safe-receipt-panel__error, .safe-receipt-panel [role="alert"]').first();
        await expect(alert).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(c.copy)).toBeVisible();
        await audit(page, 'wallet', c.state, SCOPE);
      });
    }
  });

  test.describe('Read failures', () => {
    test('T1 last-known value (A11y Fund Old, scenario fail)', async ({ page }) => {
      setScenario('fail');
      await openFund(page, t.groups.old);
      await expect(page.getByText("Couldn't read this right now.").first()).toBeVisible();
      await expect(page.locator('table.group-fund-balances tbody tr').first().locator('td')).toContainText('Last known');
      await expect(page.locator('.group-fund-card__stamp')).toHaveText(/^Last known/);
      await audit(page, 'treasury', 'last-known', SCOPE);
    });

    test('T1 never read (A11y Fund New, scenario fail)', async ({ page }) => {
      setScenario('fail');
      forget(t.safes.never);
      await openFund(page, t.groups.never);
      await expect(page.getByText("We haven't been able to read this group fund yet.", { exact: false })).toBeVisible();
      await expect(page.locator('table.group-fund-balances')).toHaveCount(0);
      await audit(page, 'treasury', 'never-read', SCOPE);
    });

    test('T1 wrong network behind the RPC (scenario mismatch)', async ({ page }) => {
      setScenario('mismatch');
      forget(t.safes.filled);
      await openFund(page, t.groups.filled);
      await expect(page.getByText(/point to the wrong network, so the group fund can't be read/)).toBeVisible();
      await audit(page, 'treasury', 'wrong-network', SCOPE);
    });

    test('T1 one token failed (scenario token_fail)', async ({ page }) => {
      setScenario('token_fail');
      forget(t.safes.filled);
      await openFund(page, t.groups.filled);
      const rows = page.locator('table.group-fund-balances tbody tr');
      await expect(rows.first().locator('td')).toHaveText(/\d/);
      await expect(rows.nth(1).locator('td')).toContainText("Couldn't read this right now");
      await expect(page.locator('.group-fund-card__stamp')).toHaveText(/^Updated/);
      await audit(page, 'treasury', 'token-failed', SCOPE);
    });

    test('T3 link form: the read failed mid-check (scenario fail)', async ({ page }) => {
      setScenario('fail');
      await page.goto(`/group/${t.groups.empty}/treasury/link`);
      await page.getByLabel('Account ID').fill(UNLINKED_ID);
      await page.getByRole('button', { name: 'Link account' }).click();
      await expect(page.getByText("We couldn't check this account right now. Try linking it again in a minute.")).toBeVisible();
      // Nothing was linked: the typed ID is kept on the form.
      await expect(page.getByLabel('Account ID')).toHaveValue(UNLINKED_ID);
      await audit(page, 'link', 'read-failed', SCOPE);
    });

    test('T7 approval settings: failed save (scenario fail)', async ({ page }) => {
      setScenario('ok');
      forget(t.safes.filled);
      await page.goto(`/user/${t.steward}/safe-accounts/${t.safes.filled}`);
      await expect(page.getByRole('button', { name: 'Save approval settings' })).toBeVisible();
      forget(t.safes.filled);
      setScenario('fail');
      await page.getByLabel('How many organizers must approve a payment').fill('1');
      // With JavaScript on and the in-flight marker in place: the 21-28
      // workaround (dropping the marker) is gone since 21-25 fix 14.
      await page.getByRole('button', { name: 'Save approval settings' }).click();
      await expect(page.getByText("We couldn't read the group fund right now. Try saving again in a minute.")).toBeVisible();
      await audit(page, 'approval-settings', 'failed-save', SCOPE);
    });

    test('T8 shared accounts, one card after a failed read', async ({ page }) => {
      await page.goto(`/user/${t.steward}/safe-accounts`);
      await expect(page.getByText('Accounts that need one or more people to approve each payment.')).toBeVisible();
      await expect(page.getByText("We couldn't load this account right now. Reload this page in a minute.").first()).toBeVisible();
      await audit(page, 'shared-accounts', 'steward', SCOPE);
    });
  });

  test.describe.serial('No networks configured', () => {
    const BACKUP = 'tests/a11y/.auth/registry-backup.json';
    const IDS = ['ethereum_sepolia', 'base_sepolia'];

    // Recreates any missing entry from the backup, with its original UUID.
    const RESTORE = `$f = '/var/www/html/${BACKUP}';
  if (file_exists($f)) {
    $s = \\Drupal::entityTypeManager()->getStorage('safe_network');
    foreach (json_decode(file_get_contents($f), TRUE) as $id => $values) {
      if (!$s->load($id)) { $s->create($values)->save(); print "restored $id\\n"; }
    }
  }`;
    const BACKUP_CODE = `$s = \\Drupal::entityTypeManager()->getStorage('safe_network');
  $out = [];
  foreach (['ethereum_sepolia', 'base_sepolia'] as $id) { $e = $s->load($id); if (!$e) { throw new \\RuntimeException("missing $id"); } $out[$id] = $e->toArray(); }
  file_put_contents('/var/www/html/${BACKUP}', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  print "backed up\\n";`;

    test.beforeAll(() => {
      // A run that died mid-block left a backup and no registry: restore first.
      drush(['php:eval', RESTORE]);
      drush(['php:eval', BACKUP_CODE]);
      for (const id of IDS) {
        drush(['config:delete', `safe_smart_accounts.network.${id}`, '-y']);
      }
      drush(['cr']);
    });

    test.afterAll(() => {
      drush(['php:eval', RESTORE]);
      drush(['cr']);
      const backup = JSON.parse(readFileSync(join(REPO_ROOT, BACKUP), 'utf8'));
      for (const id of IDS) {
        const uuid = drush(['config:get', `safe_smart_accounts.network.${id}`, 'uuid', '--format=string']).trim();
        expect(uuid, `${id} is back with its original UUID`).toBe(backup[id].uuid);
      }
    });

    test('T3 link form, no networks', async ({ page }) => {
      await page.goto(`/group/${t.groups.empty}/treasury/link`);
      await expect(page.getByText("No network is set up on this site yet, so a group fund can't be linked.")).toBeVisible();
      await expect(page.getByRole('button', { name: 'Link account' })).toHaveCount(0);
      await audit(page, 'link', 'no-networks', SCOPE);
    });

    test('T4 group set-up, no networks', async ({ page }) => {
      await page.goto(`/group/${t.groups.empty}/treasury/create`);
      await expect(page.getByText("No network is set up on this site yet, so a group fund can't be set up.", { exact: false })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Set up group fund' })).toHaveCount(0);
      await audit(page, 'setup', 'group-no-networks', SCOPE);
    });

    test('T4 personal create, no networks', async ({ page }) => {
      await page.goto(`/user/${t.steward}/safe-accounts/create`);
      await expect(page.getByText("No network is set up on this site yet, so a shared account can't be created.", { exact: false })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Create shared account' })).toHaveCount(0);
      await audit(page, 'setup', 'personal-no-networks', SCOPE);
    });
  });
}

/** The plain member's states (21-28's treasury-member.spec.ts). */
export function memberStates(o: StateOptions = {}): void {
  let t: Treasury;
  const audit = auditor(o, () => t);


  test.beforeAll(() => {
    setScenario('ok');
    t = seedTreasury();
    forget(t.safes.filled);
  });

  test('T1 member empty', async ({ page }) => {
    await page.goto(`/group/${t.groups.empty}/treasury`);
    await expect(page.getByRole('heading', { level: 2, name: "This group doesn't have a group fund yet" })).toBeVisible();
    await expect(page.getByText('When an organizer sets one up, its balance shows here.')).toBeVisible();
    await audit(page, 'treasury', 'member-empty', SCOPE);
  });

  test('T1 member filled', async ({ page }) => {
    setScenario('ok');
    await openFund(page, t.groups.filled);
    await expectSuccessfulRead(page);
    await expect(page.getByRole('heading', { name: 'Manage the group fund' })).toHaveCount(0);
    await audit(page, 'treasury', 'member-filled', SCOPE);
  });

  test('T1 member setup not finished', async ({ page }) => {
    await page.goto(`/group/${t.groups.setup}/treasury`);
    await expect(page.getByText("An organizer is still setting up this group fund. Its balance shows here when it's ready.")).toBeVisible();
    await audit(page, 'treasury', 'member-setup-not-finished', SCOPE);
  });

  test('T5 member propose', async ({ page }) => {
    setScenario('ok');
    await page.goto(`/group/${t.groups.filled}/treasury/propose`);
    await expect(page.getByRole('heading', { level: 1, name: 'Propose a Payment', exact: true })).toBeVisible();
    await audit(page, 'propose', 'member', SCOPE);
  });

  test('T6 member payment', async ({ page }) => {
    setScenario('ok');
    await page.goto(`/group/${t.groups.filled}/treasury/transaction/${t.payments.ready}`);
    await expect(page.getByText('It has enough approvals. An organizer will send it.')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Send payment' })).toHaveCount(0);
    await audit(page, 'payment', 'member', SCOPE);
  });

  test('T8 member shared accounts, empty', async ({ page }) => {
    // The listing shows the member's own accounts and the funds they approve
    // for; a plain member has neither, so this is T8's empty state.
    await page.goto(`/user/${t.member}/safe-accounts`);
    await expect(page.getByRole('heading', { level: 2, name: 'No shared accounts yet' })).toBeVisible();
    await audit(page, 'shared-accounts', 'member-empty', SCOPE);
  });
}
