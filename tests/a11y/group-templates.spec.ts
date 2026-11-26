// A fictional Group's journey in a real
// browser on a local site, with funds off.
//
// A Group's manager sets the Group up from the installed template (preview,
// one changed rule, one confirm); twelve roster members agree a decision,
// which closes early on the last agree; the register and "Your notices" show
// it; a money decision is blocked with a reason and replaced with its amount
// kept; a meeting decision is recorded; the reading rule holds for every
// persona; a starter sample is replaced and keeps its audience; and a roster
// removal and a Group removal each do what the reading rule says.
//
// The template id, label and starter titles are read from the site's config,
// and the Group type from localnodes_group_setup.settings (in the seed), so
// this file names no template, kind or Group type. Each run seeds new Groups
// under its own key, so a rerun starts from a Group that is not set up.
//
// Rerun from the repo root (a local site, Playwright's own Chromium):
//   LN_BASE_URL=https://your-site.ddev.site LN_BROWSER_CHANNEL=chromium \
//     npx --no-install playwright test -c tests/a11y/playwright.config.ts --project=member group-templates.spec.ts
import { test, expect, type Browser } from '@playwright/test';
import { BANNED, drush } from './helpers/governance-states';
import {
  CLOSE_ON_AGREE,
  CONTENT,
  FUND_WORDS,
  NON_AUTHORITY,
  NOT_ADOPTED,
  PROVENANCE,
  assertPlainWords,
  choose,
  field,
  installedTemplates,
  isRefusal,
  knownOffSite,
  openTab,
  PAYMENT_WORDS,
  runKey,
  seed,
  select,
  serverAiCounts,
  sessions,
  visit,
  type Seed,
  type Template,
} from './helpers/group-templates';

const KEY = runKey('j');
const PREFIX = 'gt';

let s: Seed;
let template: Template;
let auth: Record<string, string>;
const ids: Record<string, number> = {};

test.describe.configure({ mode: 'serial' });

/** Opens a page as a persona; null is a signed-out visitor. */
const as = (browser: Browser, persona: string | null, path: string | null) =>
  visit(browser, persona === null ? null : auth[persona], path);

const display = (persona: string) => s.users[persona].display;

test.describe('group templates journey', () => {
  test.beforeAll(async () => {
    test.setTimeout(300_000);
    s = seed('journey', KEY);
    // Idempotent: a second run creates nothing new and prints the same JSON.
    expect(seed('journey', KEY), 'a second seed run prints the same Groups and personas').toEqual(s);
    const templates = installedTemplates();
    expect(templates.length, 'at least one installed template').toBeGreaterThan(0);
    template = templates[0];
    auth = await sessions(s, PREFIX);
  });

  test('the word checks fire on planted text', () => {
    for (const planted of ['Connect your wallet', 'Pay the gas fee', 'Recorded on-chain', 'Send tokens', 'The group fund', 'Ask the signature']) {
      expect(BANNED.test(planted) || FUND_WORDS.test(planted), planted).toBe(true);
    }
    expect(PAYMENT_WORDS.test('Pay the plumber'), 'a payment word outside the line is caught').toBe(true);
    expect(FUND_WORDS.test(NON_AUTHORITY) || BANNED.test(NON_AUTHORITY), 'the non-authority line itself passes').toBe(false);
  });

  test('the manager previews the template, changes one rule and confirms once', async ({ browser }) => {
    test.setTimeout(180_000);
    const A = s.groups.A;
    const before = await as(browser, 'organizer', `/group/${A}/topics`);
    for (const title of template.starter) {
      await expect(before.page.locator(CONTENT).getByText(title)).toHaveCount(0);
    }
    await before.done();

    const v = await as(browser, 'organizer', `/group/${A}/about`);
    const { page } = v;
    await openTab(page, 'Set up');
    await expect(page).toHaveURL(new RegExp(`/group/${A}/setup$`));
    await expect(page.getByText('Nothing is set up until you confirm.')).toBeVisible();
    await choose(page, template.label);
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    const content = page.locator(CONTENT);
    await expect(content.getByRole('heading', { name: template.label, exact: true })).toBeVisible();
    for (const title of template.starter) {
      await expect(content.getByText(`${title} · ${NOT_ADOPTED}`, { exact: true })).toBeVisible();
    }
    await assertPlainWords(page, 'set-up preview');
    // Nothing is applied before the confirm.
    expect(drush(['php:eval', `print 'LN_APPLIED=' . (\\Drupal::service('localnodes_group_setup.application_manager')->forGroup(\\Drupal\\group\\Entity\\Group::load(${A})) === NULL ? 'none' : 'some') . "\\n";`])).toContain('LN_APPLIED=none');
    // Change one rule: the days for everyday and money decisions.
    const days = template.everyday_days + 3;
    ids.days = days;
    await page.getByRole('spinbutton', { name: /^Days to respond to everyday and money decisions/ }).fill(String(days));
    await page.getByRole('button', { name: 'Confirm setup', exact: true }).click();
    await expect(page.getByText(`${s.group_labels.A} is set up.`)).toBeVisible();
    await expect(page.locator('#block-socialblue-local-tasks').getByRole('link', { name: 'Decisions', exact: true })).toBeVisible();
    await v.done();

    const after = await as(browser, 'organizer', `/group/${A}/topics`);
    for (const title of template.starter) {
      await expect(after.page.locator(CONTENT).getByRole('link', { name: title, exact: true }).first()).toBeVisible();
    }
    await after.done();
    const again = await as(browser, 'organizer', `/group/${A}/setup`);
    await expect(again.page.getByText('This group is already set up.')).toBeVisible();
    await expect(again.page.getByRole('button', { name: 'Confirm setup' })).toHaveCount(0);
    await again.done();
  });

  test('the organizer approves twelve roster members, each with a reason', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await as(browser, 'organizer', `/group/${s.groups.A}/decisions/roster`);
    const { page } = v;
    for (const persona of s.roster) {
      await select(page, 'Who').selectOption({ label: display(persona) });
      await choose(page, 'Approve to respond to decisions');
      await field(page, 'Why').fill('Lives at the address and joined the agreements.');
      await page.getByRole('button', { name: 'Record roster change', exact: true }).click();
      await expect(page.getByText('The roster change is recorded.')).toBeVisible();
    }
    await expect(page.getByText(`${s.roster.length} people are approved to respond to this group's decisions.`)).toBeVisible();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(page, 'roster');
    await v.done();
  });

  test('an everyday decision closes early when the last roster member agrees', async ({ browser }) => {
    test.setTimeout(420_000);
    const A = s.groups.A;
    const open = await as(browser, 'roster01', `/group/${A}/decisions`);
    let page = open.page;
    await page.getByRole('link', { name: 'Propose a decision', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/group/${A}/decisions/new$`));
    // The changed rule shows on the open screen.
    await expect(page.locator(CONTENT).getByText(new RegExp(`within ${ids.days} days`)).first()).toBeVisible();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(page, 'open');
    await choose(page, 'Everyday decision');
    await field(page, 'The decision').fill('Fix the garden gate latch\nThe latch sticks in wet weather; a new one costs little.');
    await choose(page, 'This group only');
    await page.getByRole('button', { name: 'Open decision', exact: true }).click();
    await expect(page).toHaveURL(/\/decision\/\d+$/);
    ids.everyday = Number(new URL(page.url()).pathname.split('/').pop());
    await expect(page.getByText('Fix the garden gate latch')).toBeVisible();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await open.done();

    const responders = s.roster.slice(0, -1);
    const last = s.roster[s.roster.length - 1];
    for (const [i, persona] of responders.entries()) {
      const v = await as(browser, persona, `/decision/${ids.everyday}/respond`);
      page = v.page;
      await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
      // Only the last one left sees the close-on-agree line.
      await expect(page.getByText(CLOSE_ON_AGREE)).toHaveCount(0);
      if (i === 0) await assertPlainWords(page, 'respond');
      await choose(page, 'Agree');
      await page.getByRole('button', { name: 'Save response', exact: true }).click();
      await expect(page.getByText('Your response is saved.')).toBeVisible();
      await expect(page.locator(CONTENT).getByRole('heading', { name: 'Open', exact: true })).toBeVisible();
      await v.done();
    }
    const v = await as(browser, last, `/decision/${ids.everyday}/respond`);
    page = v.page;
    await expect(page.getByText(CLOSE_ON_AGREE)).toBeVisible();
    await choose(page, 'Agree');
    await page.getByRole('button', { name: 'Save response', exact: true }).click();
    const content = page.locator(CONTENT);
    await expect(content.getByRole('heading', { name: 'Agreed', exact: true })).toBeVisible();
    await expect(content.getByText('Everyone on the opening roster agreed, so it closed early.')).toBeVisible();
    const n = s.roster.length;
    await expect(content.getByText(new RegExp(`Responded: ${n} of ${n} · Needed: \\d+ · Agreed: ${n} · Standing aside: 0 · Blocking: 0 · No response: 0`))).toBeVisible();
    await expect(content.getByText('Responses are final.')).toBeVisible();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(page, 'agreed record');
    await v.done();
  });

  test('the register lists the result, and every roster member gets a link-only notice', async ({ browser }) => {
    test.setTimeout(300_000);
    const reg = await as(browser, 'roster01', `/group/${s.groups.A}/decisions`);
    const recorded = reg.page.locator('.decision-register__recorded li').filter({ has: reg.page.locator(`a[href$="/decision/${ids.everyday}"]`) });
    await expect(recorded).toHaveCount(1);
    await expect(recorded.getByText('Agreed', { exact: true })).toBeVisible();
    await expect(recorded.getByRole('link', { name: 'Fix the garden gate latch' })).toBeVisible();
    await expect(reg.page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(reg.page, 'register');
    await reg.done();

    drush(['cron']);
    for (const persona of s.roster) {
      const v = await as(browser, persona, '/decision-notices');
      const rows = v.page.locator('.consensus-notices__item').filter({ has: v.page.locator(`a[href$="/decision/${ids.everyday}"]`) });
      await expect(rows.first(), `${persona} has a notice linking to the decision`).toBeVisible();
      for (const text of await rows.allInnerTexts()) {
        expect(text, 'a notice names the Group').toContain(s.group_labels.A);
        expect(text, 'a notice holds no decision text').not.toContain('garden gate');
        expect(text, 'a notice holds no count').not.toMatch(/\d/);
      }
      await assertPlainWords(v.page, 'notices');
      await v.done();
    }
  });

  test('a money decision is blocked with a reason and replaced with its amount kept', async ({ browser }) => {
    test.setTimeout(240_000);
    const A = s.groups.A;
    const open = await as(browser, 'roster01', `/group/${A}/decisions/new`);
    let page = open.page;
    await choose(page, 'Money decision');
    await field(page, 'Up to $').fill('250');
    await field(page, 'The decision').fill('Buy a shared lawn mower\nA push mower for the front and back yards.');
    await choose(page, 'This group only');
    await page.getByRole('button', { name: 'Open decision', exact: true }).click();
    await expect(page).toHaveURL(/\/decision\/\d+$/);
    ids.money = Number(new URL(page.url()).pathname.split('/').pop());
    await expect(page.getByText(/^Up to \$250(?:\.00)?$/)).toBeVisible();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(page, 'money record');
    await open.done();

    const block = await as(browser, 'roster02', `/decision/${ids.money}/respond`);
    page = block.page;
    await choose(page, 'Block');
    await page.getByRole('button', { name: 'Save response', exact: true }).click();
    await expect(page.getByText('A block needs a reason, so the group knows what to work on.').first()).toBeVisible();
    await expect(page).toHaveURL(new RegExp(`/decision/${ids.money}/respond$`));
    await choose(page, 'Block');
    await field(page, 'Reason').fill('We can borrow one from next door first.');
    await page.getByRole('button', { name: 'Save response', exact: true }).click();
    await expect(page.getByText('Your response is saved.')).toBeVisible();
    const content = page.locator(CONTENT);
    await expect(content.getByRole('heading', { name: 'Blocked · still open', exact: true })).toBeVisible();
    await expect(content.locator('.consensus-record__responses')).toContainText('We can borrow one from next door first.');
    await block.done();

    const change = await as(browser, 'roster01', `/decision/${ids.money}`);
    page = change.page;
    await page.getByRole('link', { name: 'Withdraw or replace', exact: true }).click();
    await choose(page, 'Replace it with new text');
    await expect(field(page, 'Up to $')).toHaveValue('250');
    await field(page, 'New text').fill('Borrow a lawn mower first\nAsk next door before buying one.');
    await field(page, 'Why').fill('Trying the block\'s suggestion first.');
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('The decision is replaced by a new one.')).toBeVisible();
    ids.replacement = Number(new URL(page.url()).pathname.split('/').pop());
    expect(ids.replacement).not.toBe(ids.money);
    await expect(page.getByText(/^Up to \$250(?:\.00)?$/)).toBeVisible();
    await expect(page.getByText('This round replaces an earlier one.')).toBeVisible();
    await change.done();

    const old = await as(browser, 'roster03', `/decision/${ids.money}`);
    await expect(old.page.locator(CONTENT).getByRole('heading', { name: 'Replaced', exact: true })).toBeVisible();
    await expect(old.page.locator(`a[href$="/decision/${ids.replacement}"]`)).toHaveText('View linked round');
    await old.done();

    const reg = await as(browser, 'roster03', `/group/${A}/decisions`);
    await expect(reg.page.locator('.decision-register__recorded li').filter({ has: reg.page.locator(`a[href$="/decision/${ids.money}"]`) }).getByText('Replaced', { exact: true })).toBeVisible();
    await expect(reg.page.locator('.decision-register__open li').filter({ has: reg.page.locator(`a[href$="/decision/${ids.replacement}"]`) }).getByText('Open', { exact: true })).toBeVisible();
    await reg.done();
  });

  test('the organizer records a meeting decision with its four fields', async ({ browser }) => {
    test.setTimeout(120_000);
    const v = await as(browser, 'organizer', `/group/${s.groups.A}/decisions`);
    const { page } = v;
    await page.getByRole('link', { name: 'Record a meeting', exact: true }).click();
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await field(page, 'Decision title').fill('Hold a spring work day');
    await field(page, 'What was decided?').fill('Everyone who can comes on the second Saturday in April.');
    await page.getByLabel(/^Meeting date/).fill('2026-11-15');
    await field(page, 'Source or minutes').fill('Notes from the November meeting, kept in the library.');
    await field(page, 'Who made the decision?').fill('The monthly meeting');
    await field(page, 'Decision method').fill('Consensus at the meeting');
    await select(page, 'Confirmed by').selectOption({ label: display('roster03') });
    await choose(page, 'This group only');
    await choose(page, 'I confirm this is an accurate report of the meeting record.');
    await page.getByRole('button', { name: 'Save meeting report', exact: true }).click();
    await expect(page).toHaveURL(/\/decision\/meeting\/\d+$/);
    ids.report = Number(new URL(page.url()).pathname.split('/').pop());
    const content = page.locator(CONTENT);
    await expect(content.getByText(PROVENANCE)).toBeVisible();
    for (const [term, value] of [
      ['Who made the decision?', 'The monthly meeting'],
      ['Decision method', 'Consensus at the meeting'],
      ['Source or minutes', 'Notes from the November meeting, kept in the library.'],
      ['Confirmed by', display('roster03')],
    ]) {
      await expect(content.locator('dt', { hasText: term }).locator('xpath=following-sibling::dd[1]')).toHaveText(value);
    }
    await expect(page.getByText(NON_AUTHORITY)).toBeVisible();
    await assertPlainWords(page, 'meeting report');
    await v.done();
  });

  test('the reading rule: off-roster members read, others are refused, a public decision is open', async ({ browser }) => {
    test.setTimeout(240_000);
    const round = `/decision/${ids.replacement}`;
    for (const persona of ['reader', 'comanager']) {
      const v = await as(browser, persona, round);
      expect(v.response?.status(), `${persona} reads the group decision`).toBe(200);
      await expect(v.page.getByText('Borrow a lawn mower first').first()).toBeVisible();
      await expect(v.page.getByRole('link', { name: 'Respond', exact: true }), `${persona} has no Respond tab`).toHaveCount(0);
      await v.done();
      const r = await as(browser, persona, `${round}/respond`);
      expect(isRefusal(r.page, r.response), `${persona} gets no response screen`).toBe(true);
      await r.done();
    }
    for (const persona of ['bmember', 'outsider', 'sitemanager', null]) {
      for (const path of [round, `/decision/${ids.everyday}`, `/decision/meeting/${ids.report}`]) {
        const v = await as(browser, persona, path);
        expect(isRefusal(v.page, v.response), `${persona ?? 'signed-out visitor'} is refused ${path} (status ${v.response?.status()}, at ${v.page.url()})`).toBe(true);
        const text = await v.page.locator('body').innerText();
        expect(text).not.toContain('Borrow a lawn mower');
        expect(text).not.toContain('garden gate');
        expect(text).not.toContain('spring work day');
        await v.done();
      }
    }

    const open = await as(browser, 'roster04', `/group/${s.groups.A}/decisions/new`);
    await choose(open.page, 'Everyday decision');
    await field(open.page, 'The decision').fill('Hold an open garden afternoon\nNeighbours are welcome to see the herb beds.');
    await choose(open.page, 'Public');
    await open.page.getByRole('button', { name: 'Open decision', exact: true }).click();
    await expect(open.page).toHaveURL(/\/decision\/\d+$/);
    ids.public = Number(new URL(open.page.url()).pathname.split('/').pop());
    await open.done();
    const anon = await as(browser, null, `/decision/${ids.public}`);
    expect(anon.response?.status(), 'a signed-out visitor reads the public decision').toBe(200);
    await expect(anon.page.getByText('Hold an open garden afternoon').first()).toBeVisible();
    await expect(anon.page.getByRole('link', { name: 'Respond', exact: true })).toHaveCount(0);
    // A signed-out visitor sees where it was proposed, not who proposed it.
    await expect(anon.page.locator(CONTENT).getByText(`Proposed in ${s.group_labels.A}`)).toBeVisible();
    await expect(anon.page.locator(CONTENT).getByText('Members of this group can see who responded.')).toBeVisible();
    expect(await anon.page.locator(CONTENT).innerText(), 'no proposer name for a signed-out visitor').not.toContain(display('roster04'));
    await assertPlainWords(anon.page, 'public record, signed out');
    await anon.done();
  });

  test('a starter sample is replaced with the Group\'s own text and stays This group only', async ({ browser }) => {
    test.setTimeout(180_000);
    const title = template.starter[0];
    const v = await as(browser, 'organizer', `/group/${s.groups.A}/topics`);
    const { page } = v;
    await page.locator(CONTENT).getByRole('link', { name: title, exact: true }).first().click();
    await expect(page.getByText(NOT_ADOPTED).first()).toBeVisible();
    const node = new URL(page.url()).pathname;
    ids.starter = Number(node.split('/').pop());
    await page.goto(`/node/${ids.starter}/edit`);
    await expect(page.getByLabel('Group members', { exact: true })).toBeChecked();
    await page.getByRole('textbox', { name: /^Title/ }).fill('Our shared agreements');
    const editor = page.locator('.ck-editor__editable').first();
    if (await editor.count()) {
      await editor.click();
      await page.keyboard.press('ControlOrMeta+A');
      await page.keyboard.type('Quiet after ten on weeknights. Chores rotate every Sunday.');
    }
    else {
      await page.getByRole('textbox', { name: /^Description/ }).fill('Quiet after ten on weeknights. Chores rotate every Sunday.');
    }
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Quiet after ten on weeknights. Chores rotate every Sunday.')).toBeVisible();
    await expect(page.locator(CONTENT).getByText(NOT_ADOPTED)).toHaveCount(0);
    await page.goto(`/node/${ids.starter}/edit`);
    await expect(page.getByLabel('Group members', { exact: true }), 'the page stays This group only').toBeChecked();
    await v.done();

    for (const persona of ['outsider', null]) {
      const r = await as(browser, persona, `/node/${ids.starter}`);
      expect(isRefusal(r.page, r.response), `${persona ?? 'signed-out visitor'} is refused the replaced page`).toBe(true);
      expect(await r.page.locator('body').innerText()).not.toContain('Quiet after ten');
      await r.done();
    }
  });

  test('a roster removal ends responding; a Group removal ends reading and notices', async ({ browser }) => {
    test.setTimeout(240_000);
    const A = s.groups.A;
    const rosterOut = s.roster[s.roster.length - 1];
    const groupOut = s.roster[s.roster.length - 2];

    // Before: the Group's notices are listed for the member about to leave.
    const before = await as(browser, groupOut, '/decision-notices');
    await expect(before.page.locator('.consensus-notices__item').filter({ hasText: s.group_labels.A }).first()).toBeVisible();
    await before.done();

    // Roster removal (the roster screen): no longer responds or opens, still reads.
    const roster = await as(browser, 'organizer', `/group/${A}/decisions/roster`);
    await select(roster.page, 'Who').selectOption({ label: display(rosterOut) });
    await choose(roster.page, 'Remove from the roster');
    await field(roster.page, 'Why').fill('Moved away at the end of the month.');
    await roster.page.getByRole('button', { name: 'Record roster change', exact: true }).click();
    await expect(roster.page.getByText('The roster change is recorded.')).toBeVisible();
    await roster.done();
    const reg = await as(browser, rosterOut, `/group/${A}/decisions`);
    await expect(reg.page.getByRole('link', { name: 'Propose a decision', exact: true })).toHaveCount(0);
    await reg.done();
    const newer = await as(browser, rosterOut, `/group/${A}/decisions/new`);
    expect(isRefusal(newer.page, newer.response) || !newer.page.url().endsWith('/decisions/new'), 'no open screen after roster removal').toBe(true);
    await newer.done();
    const reads = await as(browser, rosterOut, `/decision/${ids.everyday}`);
    expect(reads.response?.status(), 'a member off the roster still reads').toBe(200);
    await reads.done();
    // Its open decisions are withdrawn, with every response kept.
    const withdrawn = await as(browser, 'roster01', `/decision/${ids.replacement}`);
    await expect(withdrawn.page.locator(CONTENT).getByRole('heading', { name: 'Withdrawn', exact: true })).toBeVisible();
    await withdrawn.done();

    // Group removal (the Group's member screen): reading and notices end at once.
    const members = await as(browser, 'organizer', `/group/${A}/membership`);
    const row = members.page.locator('tr').filter({ has: members.page.getByRole('link', { name: display(groupOut), exact: true }) });
    // The row's Actions dropdown opens a menu at the end of the page; its
    // Remove link is named "Delete <member>" for screen readers.
    await row.getByRole('button', { name: /^Actions/ }).click();
    await members.page.getByRole('menu').getByRole('link', { name: `Delete ${display(groupOut)}`, exact: true }).click();
    // The house departure screen (HouseDepartureForms) asks this.
    await expect(members.page.getByRole('heading', { name: 'Remove a member', exact: true })).toBeVisible();
    await expect(members.page.getByText(`${display(groupOut)} will still be able to read the pages they wrote here`)).toBeVisible();
    await members.page.getByRole('button', { name: 'Remove from this house', exact: true }).click();
    await members.done();
    const gone = await as(browser, groupOut, `/decision/${ids.everyday}`);
    expect(isRefusal(gone.page, gone.response), `a removed member is refused the group decision (status ${gone.response?.status()})`).toBe(true);
    await gone.done();
    const notices = await as(browser, groupOut, '/decision-notices');
    await expect(notices.page.locator('.consensus-notices__item').filter({ hasText: s.group_labels.A })).toHaveCount(0);
    await notices.done();
  });

  test('no live AI call reached a provider, and off-site attempts were aborted', () => {
    const counts = serverAiCounts();
    expect(counts.reached ?? 0, `server-side AI calls ${JSON.stringify(counts)}`).toBe(0);
    console.log(`server-side AI calls during the journey: ${JSON.stringify(counts)}`);
    console.log(`known off-site attempts, aborted: ${JSON.stringify([...new Set(knownOffSite)])}`);
  });
});
