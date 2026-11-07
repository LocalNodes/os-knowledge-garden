import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    // Phase-18 smoke only. Real per-module JS specs (siwe_login,
    // safe_smart_accounts) land upstream on drupal.org in Phases 19/23.
    include: ['tests/**/*.test.ts'],
    environment: 'node',
  },
});
