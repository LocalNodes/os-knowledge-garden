// Global setup for the live-Sepolia suite (21-45 Task B, T-21-120).
//
// Refuses before any browser starts unless:
//   - GITHUB_ACTIONS is 'true' (the suite runs only in the nightly workflow), and
//   - the base URL host is localhost or ends in .test (the job's own site, never a public one).
// This is a speed bump, not the protection: the real protection is that the throwaway seed
// exists only as a CI secret, so a run anywhere else has no key.
import { LIVE_BASE_URL } from './playwright.config';

/** Whether a host may serve the live suite: localhost or a .test name. */
export function isLiveSuiteHost(hostname: string): boolean {
  const h = hostname.toLowerCase().replace(/\.$/, '');
  return h === 'localhost' || h.endsWith('.test');
}

export default async function liveGlobalSetup(): Promise<void> {
  if (process.env.GITHUB_ACTIONS !== 'true') {
    throw new Error('live suite refused: CI only (GITHUB_ACTIONS is not "true"; this suite runs only in the nightly workflow)');
  }
  let host: string;
  try {
    host = new URL(LIVE_BASE_URL).hostname;
  } catch {
    throw new Error(`live suite refused: the base URL ${JSON.stringify(LIVE_BASE_URL)} is not a URL`);
  }
  if (!isLiveSuiteHost(host)) {
    throw new Error(`live suite refused: ${host} is not localhost or a .test host`);
  }
}
