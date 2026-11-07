// Shared axe-core check for every Phase 22 screen state (UI-SPEC Part 5).
//
// checkA11y(page, screen, state, options?):
//   - runs axe with the WCAG 2.2 AA tag set, optionally scoped to the
//     screen's own region (options.include) so pre-existing theme and
//     DeepChat violations outside it don't mask the screen's result,
//   - saves the full results JSON to
//     .planning/phases/22-document-importer-cited-group-answers/a11y/axe-<screen>-<state>.json,
//   - fails the test on any serious or critical violation.
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

export const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

const HERE = dirname(fileURLToPath(import.meta.url));
// LN_A11Y_RESULTS_DIR (21-28) points another phase's run at its own folder;
// unset or empty keeps the Phase 22 folder.
export const RESULTS_DIR = process.env.LN_A11Y_RESULTS_DIR
  ? resolve(process.env.LN_A11Y_RESULTS_DIR)
  : join(
    HERE,
    '..',
    '..',
    '..',
    '.planning',
    'phases',
    '22-document-importer-cited-group-answers',
    'a11y',
  );

export interface CheckA11yOptions {
  include?: string[];
  exclude?: string[];
}

export async function checkA11y(page: Page, screen: string, state: string, options: CheckA11yOptions = {}) {
  let builder = new AxeBuilder({ page }).withTags(AXE_TAGS);
  for (const selector of options.include ?? []) {
    builder = builder.include(selector);
  }
  for (const selector of options.exclude ?? []) {
    builder = builder.exclude(selector);
  }
  const results = await builder.analyze();

  mkdirSync(RESULTS_DIR, { recursive: true });
  writeFileSync(
    join(RESULTS_DIR, `axe-${screen}-${state}.json`),
    JSON.stringify(results, null, 2) + '\n',
  );

  const blocking = results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => ({
      id: v.id,
      impact: v.impact,
      help: v.help,
      targets: v.nodes.map((n) => n.target.join(' ')),
    }));

  expect(blocking, `axe serious/critical violations on ${screen} (${state})`).toEqual([]);
  return results;
}
