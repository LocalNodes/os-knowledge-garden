// Every screen of a Group's decision journey,
// checked for accessibility on a local site.
//
// seed-group-templates.php `states <key>` builds, through the modules' own
// services, a Group that is not set up (A), a members-only Group whose only
// member manages it (B), a set-up Group with its roster and a decision in
// each state plus a meeting report (C), and a set-up Group with an empty
// register (D). Each screen and state below is then:
//   - scanned by axe (WCAG 2.2 AA tags) with no violation of any impact,
//   - checked for plain words and, on decision screens, for the non-authority
//     line in the accessibility tree,
//   - laid out at 320 CSS pixels wide and at 200% zoom with no sideways
//     scroll and no control cut off, with reduced motion requested and
//     nothing animating.
// The set-up, roster, open, respond, replace and meeting flows are finished by
// keyboard alone, with the tab order, visible focus, error focus and
// announcements checked. A human screen-reader pass and a real organizer's
// session are separate gates this spec does not claim.
//
// Results: test-results/25.3-group-templates-a11y/ (axe JSON per state).
//
// Rerun from the repo root (a local site, Playwright's own Chromium):
//   LN_BASE_URL=https://your-site.ddev.site LN_BROWSER_CHANNEL=chromium \
//     npx --no-install playwright test -c tests/a11y/playwright.config.ts --project=member group-templates.a11y.spec.ts
import { test, expect, type Browser, type Page } from '@playwright/test';
import { checkA11y } from './helpers/axe';
import { focused, tabUntil, walkTabOrder } from './helpers/keyboard';
import { find, recordAnnouncements } from './helpers/live-regions';
import {
  CLOSE_ON_AGREE,
  CONTENT,
  NON_AUTHORITY,
  NOT_ADOPTED,
  PROVENANCE,
  RESULTS_DIR,
  assertPlainWords,
  choose,
  field,
  installedTemplates,
  isRefusal,
  runKey,
  seed,
  select,
  sessions,
  visit,
  type Seed,
  type Template,
} from './helpers/group-templates';

const KEY = runKey('a');
const PREFIX = 'gta';

let s: Seed;
let template: Template;
let auth: Record<string, string>;

const SCOPE = [CONTENT, 'h1'];

test.beforeAll(async () => {
  test.setTimeout(300_000);
  s = seed('states', KEY);
  expect(seed('states', KEY), 'a second seed run prints the same Groups, decisions and personas').toEqual(s);
  template = installedTemplates()[0];
  auth = await sessions(s, PREFIX);
});

interface State {
  screen: string;
  state: string;
  persona: string | null;
  path: () => string;
  act?: (page: Page) => Promise<void>;
  /** Text that must show before the checks, proving the state. */
  marker: string | RegExp;
  /** The page is a decision screen: the non-authority line must be there. */
  decision?: boolean;
  /** Expected HTTP status (default 200). */
  status?: number;
}

const r = (name: string) => s.rounds![name];
const g = (name: string) => s.groups[name];

async function preview(page: Page): Promise<void> {
  await choose(page, template.label);
  await page.getByRole('button', { name: 'Preview', exact: true }).click();
}

const STATES: State[] = [
  { screen: 'setup', state: 'preview', persona: 'organizer', path: () => `/group/${g('A')}/setup`, act: preview, marker: () => `${template.starter[0]} · ${NOT_ADOPTED}` } as unknown as State,
  {
    screen: 'setup', state: 'rules-error', persona: 'organizer', path: () => `/group/${g('A')}/setup`,
    act: async (page) => {
      await preview(page);
      await page.getByRole('spinbutton', { name: /^Days to respond to everyday and money decisions/ }).fill('0');
      await page.getByRole('button', { name: 'Confirm setup', exact: true }).click();
    },
    marker: 'Give at least 1 day to respond.',
  },
  { screen: 'roster', state: 'list', persona: 'organizer', path: () => `/group/${g('C')}/decisions/roster`, marker: /people are approved to respond/, decision: true },
  {
    screen: 'roster', state: 'reason-error', persona: 'organizer', path: () => `/group/${g('C')}/decisions/roster`, decision: true,
    act: async (page) => {
      await select(page, 'Who').selectOption({ label: s.users.reader.display });
      await choose(page, 'Approve to respond to decisions');
      await page.getByRole('button', { name: 'Record roster change', exact: true }).click();
    },
    marker: 'Say why this roster change is made.',
  },
  {
    screen: 'open', state: 'money', persona: 'roster01', path: () => `/group/${g('C')}/decisions/new`, decision: true,
    act: async (page) => {
      await choose(page, 'Money decision');
      await field(page, 'Up to $').fill('180');
    },
    marker: 'Money decisions only: the most this could spend, in dollars and cents.',
  },
  {
    screen: 'open', state: 'rule-change', persona: 'roster01', path: () => `/group/${g('C')}/decisions/new`, decision: true,
    act: async (page) => { await choose(page, 'Change the rules'); },
    marker: /^Now: /,
  },
  {
    screen: 'open', state: 'error', persona: 'roster01', path: () => `/group/${g('C')}/decisions/new`, decision: true,
    act: async (page) => { await page.getByRole('button', { name: 'Open decision', exact: true }).click(); },
    // The text field is required, so Drupal's own required message comes first.
    marker: 'The decision field is required.',
  },
  { screen: 'respond', state: 'form', persona: 'roster04', path: () => `/decision/${r('open_money')}/respond`, marker: 'Your response', decision: true },
  {
    screen: 'respond', state: 'block-error', persona: 'roster04', path: () => `/decision/${r('open_money')}/respond`, decision: true,
    act: async (page) => {
      await choose(page, 'Block');
      await page.getByRole('button', { name: 'Save response', exact: true }).click();
    },
    marker: 'A block needs a reason, so the group knows what to work on.',
  },
  { screen: 'respond', state: 'close-on-agree', persona: 'roster11', path: () => `/decision/${r('last_one')}/respond`, marker: CLOSE_ON_AGREE, decision: true },
  { screen: 'change', state: 'form', persona: 'roster01', path: () => `/decision/${r('open_money')}/change`, marker: 'What to do', decision: true },
  {
    screen: 'change', state: 'error', persona: 'roster01', path: () => `/decision/${r('open_money')}/change`, decision: true,
    act: async (page) => { await page.getByRole('button', { name: 'Save', exact: true }).click(); },
    marker: 'Say why.',
  },
  { screen: 'record', state: 'open', persona: 'roster02', path: () => `/decision/${r('open_money')}`, marker: /^Up to \$180(?:\.00)?$/, decision: true },
  { screen: 'record', state: 'blocked', persona: 'roster02', path: () => `/decision/${r('blocked')}`, marker: 'Blocked · still open', decision: true },
  { screen: 'record', state: 'ended', persona: 'roster02', path: () => `/decision/${r('ended')}`, marker: 'Ended · result being recorded', decision: true },
  { screen: 'record', state: 'agreed', persona: 'roster02', path: () => `/decision/${r('agreed')}`, marker: 'Everyone on the opening roster agreed, so it closed early.', decision: true },
  { screen: 'record', state: 'replaced', persona: 'roster02', path: () => `/decision/${r('replaced')}`, marker: 'A linked new round replaced this one.', decision: true },
  { screen: 'meeting', state: 'form', persona: 'organizer', path: () => `/group/${g('C')}/decisions/meeting`, marker: /^Record a decision the group made at a meeting\./, decision: true },
  {
    screen: 'meeting', state: 'error', persona: 'organizer', path: () => `/group/${g('C')}/decisions/meeting`, decision: true,
    act: async (page) => { await page.getByRole('button', { name: 'Save meeting report', exact: true }).click(); },
    marker: /field is required\./,
  },
  { screen: 'meeting', state: 'report', persona: 'roster02', path: () => `/decision/meeting/${s.report}`, marker: PROVENANCE, decision: true },
  { screen: 'register', state: 'empty', persona: 'roster01', path: () => `/group/${g('D')}/decisions`, marker: 'No decisions recorded yet', decision: true },
  { screen: 'register', state: 'filled', persona: 'roster01', path: () => `/group/${g('C')}/decisions`, marker: 'Recorded results', decision: true },
  { screen: 'notices', state: 'list', persona: 'roster02', path: () => '/decision-notices', marker: 'Open the decision' },
  { screen: 'refused', state: '403', persona: 'outsider', path: () => `/decision/${r('agreed')}`, marker: /./, status: 403 },
];
// The first entry's marker reads the template, which is only known after the seed.
const markerOf = (st: State): string | RegExp => (typeof st.marker === 'function' ? (st.marker as () => string)() : st.marker);

const VIEWS = [
  { name: '1280', viewport: { width: 1280, height: 900 }, deviceScaleFactor: 1 },
  { name: 'zoom200', viewport: { width: 640, height: 450 }, deviceScaleFactor: 2 },
  { name: '320', viewport: { width: 320, height: 640 }, deviceScaleFactor: 1 },
] as const;

async function enter(browser: Browser, st: State, view: (typeof VIEWS)[number]) {
  const v = await visit(browser, st.persona === null ? null : auth[st.persona], st.path(), {
    viewport: view.viewport,
    deviceScaleFactor: view.deviceScaleFactor,
    reducedMotion: 'reduce',
  });
  expect(v.response?.status(), `${st.screen} ${st.state} answers ${st.status ?? 200}`).toBe(st.status ?? 200);
  if (st.act) await st.act(v.page);
  const marker = markerOf(st);
  await expect(v.page.locator(CONTENT).getByText(marker).first(), `${st.screen} ${st.state}: the state shows`).toBeVisible();
  return v;
}

for (const st of STATES) {
  test(`${st.screen} ${st.state}: axe, words, the accessibility tree, reflow and reduced motion`, async ({ browser }) => {
    test.setTimeout(180_000);
    for (const view of VIEWS) {
      const v = await enter(browser, st, view);
      const { page } = v;
      try {
        const results = await checkA11y(page, `25.3-${st.screen}`, `${st.state}-${view.name}`, { include: SCOPE, resultsDir: RESULTS_DIR });
        expect(results.violations.map((x) => `${x.id} (${x.impact}): ${x.nodes.map((n) => n.target.join(' ')).join(', ')}`), `${st.screen} ${st.state} at ${view.name}: no axe violation of any impact`).toEqual([]);
        if (view.name === '1280') {
          await assertPlainWords(page, `${st.screen} ${st.state}`);
          const tree = await page.locator(CONTENT).ariaSnapshot();
          if (st.decision) expect(tree, 'the non-authority line is in the accessibility tree').toContain(NON_AUTHORITY);
          if (st.marker === PROVENANCE) expect(tree, 'the provenance mark is in the accessibility tree').toContain(PROVENANCE);
          if (st.marker === CLOSE_ON_AGREE) expect(tree, 'the close-on-agree line is in the accessibility tree').toContain(CLOSE_ON_AGREE);
        }
        else {
          const layout = await page.evaluate((content) => {
            const width = document.documentElement.clientWidth;
            const root = document.querySelector(content);
            const cut = root ? Array.from(root.querySelectorAll('a, button, input, select, textarea, label, [role=button]'))
              .filter((el) => {
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                if (rect.width === 0 || rect.height === 0 || style.visibility === 'hidden') return false;
                if ((el as HTMLInputElement).type === 'radio' || (el as HTMLInputElement).type === 'checkbox') return false;
                return rect.left < -1 || rect.right > width + 1;
              })
              .map((el) => `${el.tagName.toLowerCase()} "${(el.textContent || (el as HTMLInputElement).name || '').trim().slice(0, 40)}" ${Math.round(el.getBoundingClientRect().left)}..${Math.round(el.getBoundingClientRect().right)}`) : ['no content block'];
            return { scrollWidth: document.documentElement.scrollWidth, clientWidth: width, cut };
          }, CONTENT);
          expect(layout.scrollWidth, `${st.screen} ${st.state} at ${view.name}: no sideways scroll`).toBeLessThanOrEqual(layout.clientWidth);
          expect(layout.cut, `${st.screen} ${st.state} at ${view.name}: no control cut off`).toEqual([]);
        }
        // Reduced motion is requested in every context: nothing animates.
        const running = await page.evaluate(() => document.getAnimations()
          .filter((a) => a.playState === 'running' && Number(a.effect?.getComputedTiming().duration ?? 0) > 1)
          .map((a) => (a.effect as KeyframeEffect | null)?.target?.toString() ?? 'animation'));
        expect(running, `${st.screen} ${st.state} at ${view.name}: nothing animates with reduced motion`).toEqual([]);
      }
      finally {
        await v.done();
      }
    }
  });
}

/**
 * Walks the page by Tab and checks the stops inside the content block: each
 * shows a focus indicator and is on screen, and they come in reading order
 * (top to bottom, then left to right).
 */
async function checkTabOrder(page: Page, where: string): Promise<void> {
  const walk = await walkTabOrder(page);
  expect(walk.wrapped, `${where}: Tab walks the page and wraps (no trap)`).toBe(true);
  const inContent = await page.evaluate(({ content, ids }) => ids.map((id) => {
    const el = document.querySelector(`[data-kbd-id="${id}"]`);
    if (!el || !el.closest(content)) return null;
    const rect = el.getBoundingClientRect();
    return { id, top: rect.top + window.scrollY, left: rect.left };
  }), { content: CONTENT, ids: walk.forward.map((stop) => stop.path) });
  // One control can take several stops (a date field's month, day, year and
  // calendar button); they count as one stop, showing focus if any of them
  // changed the field's look, since the field's ring stays on between them.
  const stops: typeof walk.forward = [];
  const places: { id: string; top: number; left: number }[] = [];
  walk.forward.forEach((stop, i) => {
    if (inContent[i] === null) return;
    const last = stops[stops.length - 1];
    if (last && last.path === stop.path) {
      stops[stops.length - 1] = { ...last, indicator: last.indicator || stop.indicator };
      return;
    }
    stops.push(stop);
    places.push(inContent[i]!);
  });
  expect(stops.length, `${where}: the content block has tab stops`).toBeGreaterThan(0);
  expect(stops.filter((stop) => !stop.indicator).map((stop) => `${stop.tag} "${stop.name}"`), `${where}: every stop shows focus`).toEqual([]);
  expect(stops.filter((stop) => !stop.visibleInViewport).map((stop) => `${stop.tag} "${stop.name}" (${stop.coveredBy})`), `${where}: every stop is on screen when focused`).toEqual([]);
  const backwards: string[] = [];
  for (let i = 1; i < places.length; i++) {
    const [a, b] = [places[i - 1], places[i]];
    if (b.top < a.top - 4) backwards.push(`${stops[i - 1].name} -> ${stops[i].name}`);
  }
  expect(backwards, `${where}: the tab order follows the visual order`).toEqual([]);
}

/** Tabs to a control by its accessible name. */
async function tabTo(page: Page, name: RegExp, what: string): Promise<void> {
  await tabUntil(page, (f) => name.test(f.name) || name.test(f.id), { what });
}

/** Tabs to a form field by its label, through the label's for= id. */
async function tabToField(page: Page, label: RegExp, what: string): Promise<void> {
  const id = await page.locator('label').filter({ hasText: label }).first().getAttribute('for');
  expect(id, `${what} has a label`).toBeTruthy();
  await tabUntil(page, (f) => f.id === id, { what });
}

/** Moves within a focused radio group by arrow keys until the named one is checked. */
async function arrowTo(page: Page, label: string): Promise<void> {
  const target = page.getByLabel(label, { exact: true }).first();
  for (let i = 0; i < 8; i++) {
    if (await target.isChecked()) return;
    await page.keyboard.press('ArrowDown');
  }
  await expect(target, `${label} is chosen by arrow keys`).toBeChecked();
}

/** Puts focus at the top of the page, as after a load. */
async function fromTop(page: Page): Promise<void> {
  await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
}

test.describe('keyboard alone', () => {
  test('set-up: preview and confirm by keyboard', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.bmember, `/group/${g('B')}/setup`);
    const { page } = v;
    await checkTabOrder(page, 'set-up');
    await fromTop(page);
    await tabToField(page, new RegExp(`^\\s*${template.label}\\s*$`), 'the template choice');
    await page.keyboard.press('Space');
    await tabTo(page, /^Preview$/, 'Preview');
    await page.keyboard.press('Enter');
    await expect(page.getByText(`${template.starter[0]} · ${NOT_ADOPTED}`)).toBeVisible();
    await checkTabOrder(page, 'set-up preview');
    await fromTop(page);
    await tabTo(page, /^Confirm setup$/, 'Confirm setup');
    await page.keyboard.press('Enter');
    // Confirming creates the starter pages, which can take a while on a busy machine.
    await expect(page.getByText(`${s.group_labels.B} is set up.`)).toBeVisible({ timeout: 30_000 });
    await v.done();
  });

  test('roster: approve a member by keyboard', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.organizer, `/group/${g('C')}/decisions/roster`);
    const { page } = v;
    await checkTabOrder(page, 'roster');
    await fromTop(page);
    await tabToField(page, /^\s*Who\s*\*?\s*$/, 'Who');
    await page.keyboard.type(s.users.reader.display);
    await tabToField(page, /^\s*Approve to respond to decisions\s*$/, 'the change');
    await page.keyboard.press('Space');
    await tabToField(page, /^\s*Why\s*\*?\s*$/, 'Why');
    await page.keyboard.type('Joined the agreements this week.');
    await tabTo(page, /^Record roster change$/, 'Record roster change');
    await page.keyboard.press('Enter');
    await expect(page.getByText('The roster change is recorded.')).toBeVisible();
    await expect(page.locator(CONTENT).getByRole('list').filter({ hasText: s.users.reader.display }).first()).toBeVisible();
    await v.done();
  });

  test('open: a money decision by keyboard', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.roster05, `/group/${g('C')}/decisions/new`);
    const { page } = v;
    await checkTabOrder(page, 'open');
    await fromTop(page);
    await tabToField(page, /^\s*Everyday decision\s*$/, 'the type');
    await arrowTo(page, 'Money decision');
    await tabToField(page, /^\s*The decision\s*\*?\s*$/, 'The decision');
    await page.keyboard.type('Buy a rain barrel\nFor the herb bed by the fence.');
    await tabToField(page, /^\s*Up to \$\s*$/, 'Up to $');
    await page.keyboard.type('90');
    await tabTo(page, /^Open decision$/, 'Open decision');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/decision\/\d+$/);
    await expect(page.getByText(/^Up to \$90(?:\.00)?$/)).toBeVisible();
    await v.done();
  });

  test('respond: agree by keyboard, the choices are one fieldset with a legend', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.roster05, `/decision/${r('open_money')}/respond`);
    const { page } = v;
    // A required radio group is a radiogroup, still a fieldset with a legend.
    const group = page.getByRole('radiogroup', { name: /^Your response/ });
    await expect(group).toHaveCount(1);
    await expect(group.getByRole('radio')).toHaveCount(3);
    expect(await group.evaluate((el) => el.tagName === 'FIELDSET' && !!el.querySelector(':scope > legend')), 'a fieldset with a legend').toBe(true);
    await checkTabOrder(page, 'respond');
    await fromTop(page);
    await tabToField(page, /^\s*Agree\s*$/, 'the response');
    await page.keyboard.press('Space');
    await expect(page.getByLabel('Agree', { exact: true })).toBeChecked();
    await tabTo(page, /^Save response$/, 'Save response');
    await page.keyboard.press('Enter');
    await expect(page.getByText('Your response is saved.')).toBeVisible();
    await v.done();
  });

  test('replace: new text by keyboard', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.roster01, `/decision/${r('blocked')}/change`);
    const { page } = v;
    await checkTabOrder(page, 'withdraw or replace');
    await fromTop(page);
    await tabToField(page, /^\s*Withdraw this decision\s*$/, 'What to do');
    await arrowTo(page, 'Replace it with new text');
    await tabToField(page, /^\s*New text\s*$/, 'New text');
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.type('Move the bike rack\nTo the front porch, where it stays dry.');
    await tabToField(page, /^\s*Why\s*\*?\s*$/, 'Why');
    await page.keyboard.type('The block said the side yard floods.');
    await tabTo(page, /^Save$/, 'Save');
    await page.keyboard.press('Enter');
    await expect(page.getByText('The decision is replaced by a new one.')).toBeVisible();
    await v.done();
  });

  test('meeting: a report by keyboard', async ({ browser }) => {
    test.setTimeout(240_000);
    const v = await visit(browser, auth.organizer, `/group/${g('C')}/decisions/meeting`);
    const { page } = v;
    await checkTabOrder(page, 'meeting');
    await fromTop(page);
    const typeInto = async (label: RegExp, text: string, what: string) => {
      await tabToField(page, label, what);
      await page.keyboard.type(text);
    };
    await typeInto(/^\s*Decision title\s*\*?\s*$/, 'Mend the fence', 'Decision title');
    await typeInto(/^\s*What was decided\?\s*\*?\s*$/, 'Two members mend the north fence in May.', 'What was decided?');
    await tabToField(page, /^\s*Meeting date\s*\*?\s*$/, 'Meeting date');
    await page.keyboard.type('11152026');
    await typeInto(/^\s*Source or minutes\s*\*?\s*$/, 'November meeting notes.', 'Source or minutes');
    await typeInto(/^\s*Who made the decision\?\s*\*?\s*$/, 'The monthly meeting', 'Who made the decision?');
    await typeInto(/^\s*Decision method\s*\*?\s*$/, 'Consensus at the meeting', 'Decision method');
    await tabToField(page, /^\s*Confirmed by\s*\*?\s*$/, 'Confirmed by');
    await page.keyboard.type(s.users.roster03.display);
    await tabToField(page, /^\s*I confirm this is an accurate report of the meeting record\.\s*\*?\s*$/, 'the confirmation');
    await page.keyboard.press('Space');
    await tabTo(page, /^Save meeting report$/, 'Save meeting report');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/decision\/meeting\/\d+$/);
    await expect(page.getByText(PROVENANCE)).toBeVisible();
    await v.done();
  });
});

// After a failed submit, focus moves to the error summary or the first field
// in error, and the error reaches a screen reader: through that focus move, a
// live region, or the field's own description.
const ERROR_FORMS = STATES.filter((st) => /error$/.test(st.state));

test.describe('errors: focus and announcement', () => {
  for (const st of ERROR_FORMS) {
    test(`${st.screen} ${st.state}: focus moves to the error, and the error is announced or tied to its field`, async ({ browser }) => {
      test.setTimeout(120_000);
      const v = await visit(browser, st.persona === null ? null : auth[st.persona], null);
      const { page } = v;
      const log = await recordAnnouncements(page);
      await page.goto(st.path());
      // The state's act submits by pointer; the flow tests above prove the keyboard path.
      await st.act!(page);
      await expect(page.locator(CONTENT).getByText(markerOf(st)).first()).toBeVisible();
      await page.waitForTimeout(500);
      const now = await focused(page);
      const firstInvalid = await page.locator(`${CONTENT} [aria-invalid="true"]`).first().getAttribute('id').catch(() => null);
      const onSummary = /error/i.test(now.name) && now.tag !== 'INPUT' && now.tag !== 'TEXTAREA';
      expect(onSummary || (firstInvalid !== null && now.id === firstInvalid), `${st.screen} ${st.state}: focus is on the error summary or the first field in error (focus is on ${now.tag}#${now.id} "${now.name.slice(0, 60)}")`).toBe(true);
      const message = typeof st.marker === 'string' ? st.marker : '';
      const heard = find(log, ['live', 'focus'], /error|required|Say why|reason|Give at least/i);
      const tied = await page.locator(`${CONTENT} [aria-invalid="true"]`).evaluateAll((els) => els.map((el) => {
        const ids = (el.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
        const said = ids.map((id) => document.getElementById(id)?.textContent ?? '').join(' ');
        const item = el.closest('.form-item, .form-group, fieldset');
        const error = item?.querySelector('.form-item--error-message, .invalid-feedback')?.textContent?.trim() ?? '';
        return { name: (el as HTMLInputElement).name, tied: error !== '' && said.includes(error.replace(/\s*×\s*$/, '').trim()) };
      }));
      expect(heard.length > 0 || (tied.length > 0 && tied.every((t) => t.tied)), `${st.screen} ${st.state}: the error "${message}" is announced (heard ${JSON.stringify(heard.map((h) => h.text.slice(0, 60)))}) or tied to its field (${JSON.stringify(tied)})`).toBe(true);
      await v.done();
    });
  }

  test('a status message after a save is announced', async ({ browser }) => {
    test.setTimeout(120_000);
    const v = await visit(browser, auth.organizer, null);
    const { page } = v;
    const log = await recordAnnouncements(page);
    await page.goto(`/group/${g('C')}/decisions/roster`);
    await select(page, 'Who').selectOption({ label: s.users.roster09.display });
    await choose(page, 'Record an eligibility dispute');
    await field(page, 'Why').fill('Asked to check whether they live here yet.');
    await page.getByRole('button', { name: 'Record roster change', exact: true }).click();
    await expect(page.getByText('The roster change is recorded.')).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(500);
    // Drupal prints its messages above the content block, so look on the whole page.
    const status = page.locator('xpath=//*[contains(normalize-space(.), "The roster change is recorded.")][not(*[contains(normalize-space(.), "The roster change is recorded.")])]').first();
    const inRegion = await status.evaluate((el) => !!el.closest('[role="status"], [role="alert"], [aria-live="polite"], [aria-live="assertive"]'));
    const heard = find(log, ['live', 'focus'], 'The roster change is recorded.');
    expect(inRegion || heard.length > 0, `the saved status is in a live region or takes focus (heard ${JSON.stringify(heard)})`).toBe(true);
    await v.done();
  });
});

test('refused personas see no decision content', async ({ browser }) => {
  test.setTimeout(120_000);
  for (const persona of ['outsider', 'sitemanager', 'bmember', null]) {
    const v = await visit(browser, persona === null ? null : auth[persona], `/decision/${r('agreed')}`);
    expect(isRefusal(v.page, v.response), `${persona ?? 'signed-out visitor'} is refused`).toBe(true);
    expect(await v.page.locator('body').innerText()).not.toContain('herb bed');
    await v.done();
  }
});
