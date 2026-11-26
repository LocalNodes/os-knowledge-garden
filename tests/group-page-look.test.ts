/**
 * A group page's current tab stays in view, and its left column draws no
 * empty card, in a real browser.
 *
 * Loads the real group-tabs.js and the theme and app styles into the markup
 * socialblue builds, as tests/group-tabs-more.test.ts does:
 * - from 900px wide, socialblue folds the tabs that don't fit behind "More",
 *   in their order, so a late tab (Decisions, Set up) was folded away on its
 *   own page; it now trades places with the last shown tab and nothing sits
 *   under the "More" button;
 * - below 900px the tab list scrolls sideways; it is scrolled to the
 *   current tab;
 * - the Decisions page's actions in the left column are full-width filled
 *   buttons, and an empty action list draws nothing.
 *
 * Uses the installed Google Chrome (LN_BROWSER_CHANNEL=chromium for
 * Playwright's own). Skips when the Open Social theme is not installed
 * (composer install).
 */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { chromium, type Browser, type Page } from '@playwright/test';

const ROOT = resolve(__dirname, '..');
const PLATFORM = resolve(ROOT, 'html/modules/custom/localnodes_platform');
const THEME = resolve(ROOT, 'html/profiles/contrib/social/themes/socialblue/assets/css');
const SKY = `${THEME}/navbar--sky.css`;
const CSS = [SKY, `${THEME}/cards--sky.css`, `${PLATFORM}/css/ln-tokens.css`, `${PLATFORM}/css/ln-overrides.css`, `${PLATFORM}/css/ln-app-look.css`];

const tab = (name: string, active = false) => active
  ? `<li class="active"><a href="#${name}" class="is-active">${name}</a></li>`
  : `<li><a href="#${name}">${name}</a></li>`;

async function load(page: Page, body: string): Promise<void> {
  const styles = '<style>.navbar { position: relative; }</style>'
    + CSS.map((file) => `<style>${readFileSync(file, 'utf8')}</style>`).join('');
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1">${styles}</head><body class="socialblue--sky">${body}</body></html>`);
  await page.addScriptTag({ content: `window.Drupal = {t: (s) => s, behaviors: {}};
    window.once = (id, selector, context) => Array.from(context.querySelectorAll(selector));` });
  await page.addScriptTag({ content: readFileSync(`${PLATFORM}/js/group-tabs.js`, 'utf8') });
  await page.evaluate(() => (window as any).Drupal.behaviors.localnodesGroupTabs.attach(document));
}

describe.skipIf(!existsSync(SKY))('a group page keeps its current tab in view', () => {
  let browser: Browser;

  beforeAll(async () => {
    browser = await chromium.launch({ channel: process.env.LN_BROWSER_CHANNEL ?? 'chrome' });
  });

  afterAll(async () => {
    await browser?.close();
  });

  it('wide: the current tab, folded behind "More", is shown and not covered', async () => {
    const page = await browser.newPage({ viewport: { width: 1280, height: 600 } });
    // As socialblue leaves it on the Decisions page: Decisions folded away.
    await load(page, `
<nav class="navbar navbar-secondary" role="navigation" style="width: 740px">
  <div class="navbar-scrollable">
    <ul class="nav navbar-nav">
      <div class="visible-list">${['Stream', 'About', 'Events', 'Quick polls', 'Fund'].map((t) => tab(t)).join('')}</div>
      <div class="hidden-list card">${[tab('Topics'), tab('Votes'), tab('Decisions', true), tab('Members')].join('')}</div>
      <span class="caret"></span>
    </ul>
  </div>
</nav>`);
    const state = await page.evaluate(() => {
      const current = document.querySelector('li.active')!;
      const button = document.querySelector('.ln-tabs-more')!.getBoundingClientRect();
      const covered = [...document.querySelectorAll('.visible-list > li')].filter((li) => {
        const box = li.getBoundingClientRect();
        return box.right > button.left && box.left < button.right;
      }).map((li) => li.textContent!.trim());
      return {
        shown: !!current.closest('.visible-list'),
        covered,
        folded: [...document.querySelectorAll('.hidden-list > li')].map((li) => li.textContent!.trim()),
      };
    });
    expect(state.shown, 'Decisions is among the shown tabs').toBe(true);
    expect(state.covered, 'no shown tab sits under the "More" button').toEqual([]);
    expect(state.folded).not.toContain('Decisions');
    expect(state.folded).toContain('Fund');
    await page.close();
  });

  it('narrow: the tab list is scrolled to the current tab', async () => {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true });
    const names = ['Stream', 'About', 'Events', 'Quick polls', 'Fund', 'Topics', 'Votes', 'Decisions', 'Import', 'Set up'];
    // As on the real page below 900px: the list scrolls, its tabs keep
    // their width.
    await load(page, `
<style>ul.navbar-nav > li { flex: 0 0 auto; }</style>
<nav class="navbar navbar-secondary" role="navigation">
  <div class="navbar-scrollable">
    <ul class="nav navbar-nav" style="display: flex; flex-wrap: nowrap; overflow-x: scroll; white-space: nowrap">
      ${names.map((t) => tab(t, t === 'Decisions')).join('')}
    </ul>
  </div>
</nav>`);
    const view = await page.evaluate(() => {
      const list = document.querySelector('ul.navbar-nav')!.getBoundingClientRect();
      const current = document.querySelector('li.active')!.getBoundingClientRect();
      return { left: current.left >= list.left - 1, right: current.right <= list.right + 1, scrolled: document.querySelector('ul.navbar-nav')!.scrollLeft };
    });
    expect(view.scrolled).toBeGreaterThan(0);
    expect(view.left && view.right, 'Decisions is inside the visible part of the bar').toBe(true);
    await page.close();
  });

  it('the left column: filled full-width action buttons, and no empty card', async () => {
    const page = await browser.newPage({ viewport: { width: 1280, height: 600 } });
    await load(page, `
<div class="content-merged--sky"><aside style="width: 360px"><div class="region--complementary-top">
  <section class="card" id="stats">8 members</section>
  <ul class="list-inline local-actions" id="empty"></ul>
</div></aside>
<aside style="width: 360px"><div class="region--complementary-top">
  <section class="card">8 members</section>
  <ul class="list-inline local-actions" id="actions"><li><a href="#new" class="btn btn-xs btn-default">Propose a decision</a></li></ul>
</div></aside></div>`);
    const look = await page.evaluate(() => {
      const empty = document.getElementById('empty')!;
      const button = document.querySelector<HTMLElement>('#actions .btn')!;
      const list = document.getElementById('actions')!.getBoundingClientRect();
      const cs = getComputedStyle(button);
      return {
        emptyDrawn: getComputedStyle(empty).display !== 'none' && empty.getBoundingClientRect().height > 0,
        background: cs.backgroundColor,
        color: cs.color,
        widthShare: button.getBoundingClientRect().width / list.width,
      };
    });
    expect(look.emptyDrawn, 'an empty action list draws nothing').toBe(false);
    expect(look.background).toBe('rgb(15, 118, 110)');
    expect(look.color).toBe('rgb(255, 255, 255)');
    expect(look.widthShare).toBeGreaterThan(0.7);
    await page.close();
  });
});
