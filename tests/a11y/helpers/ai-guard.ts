// No live AI calls from the a11y suite (22-28, orch ruling 2026-11-06).
//
// guardAi(page, handler) does two things on one page:
//   1. Routes every request whose path is an AI endpoint to `handler`, which
//      must fulfil it with a canned body (fixtures/canned-ai.ts) or abort it.
//      Requests to anything else continue untouched.
//   2. Counts, independently of the route, every request the page makes to
//      those endpoints (page 'request' event) and every one the handler
//      fulfilled. assertNoLiveAi() fails when the two differ, so a request
//      that slipped past the route (a changed URL, a new endpoint, a
//      continue() by mistake) fails the test.
//
// The AI endpoints:
//   - POST /api/deepchat        (ai_chatbot DeepChatApi::api; the assistant)
//   - GET  /api/ai/overview?q=  (AiOverviewController::overview; S8)
// Not AI, so not routed: /api/deepchat/session and
// /ajax/chatbot/message-skeleton/* (Drupal-only session and markup).
import { expect, type Page, type Request, type Route } from '@playwright/test';

export const AI_ENDPOINT_PATHS = ['/api/deepchat', '/api/ai/overview'] as const;

/** Whether a URL is one of the AI endpoints (exact path, any query). */
export function isAiEndpoint(url: string): boolean {
  const path = new URL(url).pathname.replace(/\/+$/, '');
  return (AI_ENDPOINT_PATHS as readonly string[]).includes(path);
}

export interface AiGuard {
  /** Requests the page made to an AI endpoint. */
  seen: string[];
  /** Of those, the ones the route handler fulfilled with a canned body. */
  fulfilled: string[];
}

export type AiHandler = (route: Route, request: Request, fulfil: (body: unknown, status?: number) => Promise<void>) => Promise<void>;

export async function guardAi(page: Page, handler: AiHandler): Promise<AiGuard> {
  const guard: AiGuard = { seen: [], fulfilled: [] };
  page.on('request', (request) => {
    if (isAiEndpoint(request.url())) {
      guard.seen.push(`${request.method()} ${request.url()}`);
    }
  });
  await page.route(
    (url) => isAiEndpoint(url.toString()),
    async (route, request) => {
      const fulfil = async (body: unknown, status = 200) => {
        await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
        guard.fulfilled.push(`${request.method()} ${request.url()}`);
      };
      await handler(route, request, fulfil);
    },
  );
  return guard;
}

/** Fails unless every AI-endpoint request was fulfilled by the route. */
export function assertNoLiveAi(guard: AiGuard): void {
  const unfulfilled = guard.seen.filter((entry) => !guard.fulfilled.includes(entry));
  expect(
    { seen: guard.seen.length, fulfilled: guard.fulfilled.length, unfulfilled },
    'every request to an AI endpoint was answered by the canned route, none went live',
  ).toEqual({ seen: guard.fulfilled.length, fulfilled: guard.fulfilled.length, unfulfilled: [] });
}
