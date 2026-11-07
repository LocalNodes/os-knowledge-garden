// Harness smoke test: proves the config, persona seeding and axe helper run.
// The login page is socialblue's own, not a Phase 22 screen.
import { test, expect } from '@playwright/test';
import { checkA11y } from './helpers/axe';

test('login page: renders, and global setup seeded the a11y group', async ({ page }) => {
  await page.goto('/user/login');
  await expect(page.getByRole('heading', { level: 1, name: 'Log in' })).toBeVisible();
  await expect(page.locator('form#social-user-login-form')).toBeVisible();
  expect(process.env.LN_GROUP, 'global setup exports the a11y group id').toMatch(/^\d+$/);
});

test('login page: empty (axe)', async ({ page }) => {
  // Pre-existing, not a Phase 22 screen (recorded in 22-11-SUMMARY.md):
  // socialblue's login page has serious/critical axe violations on
  // 2026-11-04 (axe-core 4.13.0, Chrome 155): button-name (critical, DeepChat
  // .chat-dropdown-button), aria-command-name and nested-interactive
  // (DeepChat widget), color-contrast (.button--small, #edit-submit, intro
  // paragraphs) and listitem (main nav). Expected to fail until those are
  // fixed; if this starts passing, Playwright reports it and test.fail()
  // should be removed. The results JSON is written either way.
  test.fail();
  await page.goto('/user/login');
  await checkA11y(page, 'login', 'empty');
});
