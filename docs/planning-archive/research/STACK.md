# Technology Stack

**Project:** LocalNodes-as-a-Service Onboarding Frontend
**Researched:** 2026-03-02
**Overall Confidence:** HIGH

## Recommended Stack

### Core Framework: Nuxt 4 + Nuxt UI

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Nuxt | 4.3+ | Full-stack framework (SSR + server routes) | Zero-config Vercel deployment. Nitro server engine provides built-in API routes -- no separate backend needed. NuxtLabs acquired by Vercel in July 2025, ensuring first-class platform support. Vue ecosystem aligns with existing team expertise. |
| Nuxt UI | 4.3+ | Component library (125+ components) | Official Nuxt component library. Reka UI + Tailwind CSS v4 underneath. Includes pre-built SaaS template with landing, pricing, and dashboard layouts. Dark mode, i18n, WAI-ARIA accessibility built-in. Eliminates need for separate UI library. |
| Vue | 3.5+ | Reactive UI framework | Bundled with Nuxt 4. Composition API + `<script setup>` for clean component authoring. |
| TypeScript | 5.x | Type safety | First-class support in Nuxt 4. Type-safe server routes via Nitro. |
| Tailwind CSS | 4.x | Utility-first styling | Bundled with Nuxt UI. 5x faster builds than v3. CSS-first configuration. |

**Why Nuxt 4 over alternatives:**

Nuxt 4 became the stable major version on July 15, 2025. Nuxt 3 reaches EOL July 31, 2026 -- starting a new project on Nuxt 3 would mean immediate tech debt. Nuxt 4's `app/` directory structure, improved data fetching, and TypeScript support are designed for exactly this kind of project. The migration from v3 to v4 is smooth (automated codemods available), so community modules are rapidly catching up.

### Payment Processing

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Stripe Checkout | Latest (API 2025-06-30+) | Payment collection | Hosted checkout flow handles PCI compliance. Embeddable pricing table requires zero backend code for display. Stripe Billing Portal for self-service subscription management. |
| Stripe Pricing Table | (embed) | Pricing page display | Dashboard-configurable, no-code pricing display. Supports up to 4 products per interval, free trials, tiered pricing. Embeds via `<script>` tag. |
| stripe (npm) | ^17.0 | Server-side Stripe SDK | Used in Nuxt server routes for webhook verification, checkout session creation, and customer management. |
| @unlok-co/nuxt-stripe | ^4.0 | Nuxt Stripe integration | Provides `useServerStripe` (server) and `useClientStripe` (client) composables. Full TypeScript support. Supports both `nuxt.config.ts` and `runtimeConfig` for key management. |

**Stripe integration approach:**

Use Stripe's embeddable Pricing Table for the pricing page (zero backend code needed for display). When a user selects a plan, create a Checkout Session via a Nuxt server route (`server/api/stripe/checkout.post.ts`). Stripe handles the payment form, PCI compliance, and redirect. Webhook handler (`server/api/stripe/webhook.post.ts`) receives `checkout.session.completed` event to trigger provisioning. Stripe Billing Portal handles subscription management post-signup.

### Provisioning Integration

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| GitHub REST API | 2022-11-28 | Trigger `workflow_dispatch` | Direct HTTP calls from Nuxt server routes. As of Feb 2026, the dispatch API supports `return_run_details` parameter -- returns run ID directly (no more polling to find your run). |
| octokit (npm) | ^4.0 | GitHub API client | Official GitHub SDK with TypeScript. Used in server routes to dispatch workflows and poll run status. |

**Provisioning flow:**

1. Stripe webhook fires `checkout.session.completed`
2. Nuxt server route calls GitHub API `POST /repos/{owner}/{repo}/actions/workflows/provision-instance.yml/dispatches` with `return_run_details: true`
3. Response includes `run_id` -- store in KV/DB for status polling
4. Frontend polls a Nuxt server route that checks GitHub Actions run status via `GET /repos/{owner}/{repo}/actions/runs/{run_id}`
5. When run completes, Coolify health check confirms site is live

**Why `return_run_details` matters:** Before Feb 2026, dispatching a workflow returned only 204 No Content -- correlating dispatch to run required fragile timestamp-based polling. The new parameter returns the run ID directly, making the entire provisioning tracking flow reliable.

### Real-Time Status Updates

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Polling (short) | N/A | Provisioning progress | Simple, reliable, works perfectly within Vercel's serverless constraints. No SSE/WebSocket complications. |

**Why polling over SSE/WebSockets:**

SSE on Vercel has significant limitations: 10-second connection timeout on serverless (only Edge runtime allows long-lived connections), stateless functions cannot share connection state, and Fluid Compute billing charges for the entire streaming duration. For a ~4 minute provisioning wait, the frontend should poll a lightweight Nuxt server route (`/api/provision/status?runId=xxx`) every 5-10 seconds. This route checks GitHub Actions run status and Coolify deployment status, returning a progress object. Simple, cheap, zero infrastructure complexity.

The polling endpoint is ~50ms per call. Over a 4-minute provisioning window with 5-second intervals, that is ~48 calls totaling ~2.4 seconds of function time. SSE would hold a connection open for 240+ seconds -- 100x more expensive in GB-hours and requires Edge runtime workarounds.

### Email Service

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Resend | API v2 | Transactional email | Developer-first email API with official Vercel integration. React Email support for templated emails. Free tier: 3,000 emails/month (100/day) -- more than sufficient for early provisioning notifications. Pro: $20/month for 50k emails when needed. |
| @react-email/components | ^1.0 | Email templates | Build email templates as React components. Works with Resend's render pipeline. Industry standard for programmatic email design. |

**Email use cases:**

1. **Instance ready notification** -- Sent when provisioning completes. Contains: site URL, admin credentials, getting started link.
2. **Payment confirmation** -- Stripe handles this natively (no custom email needed).
3. **Future: Onboarding drip** -- Welcome sequence post-provisioning (deferred).

**Why Resend over alternatives:** Official Vercel integration (one-click setup). REST API (not SMTP) -- ideal for serverless. Free tier covers early growth. React Email for maintainable templates. No need for SendGrid's complexity or AWS SES's configuration burden.

### Data Persistence

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Vercel KV (Redis) | Latest | Provisioning state, session data | Vercel-native key-value store. Official Nuxt/Nitro integration via `useStorage()`. Used to store provisioning run state (run_id, status, subdomain, email) between webhook receipt and completion polling. No database server to manage. |

**Why Vercel KV over a database:**

The onboarding frontend has minimal persistence needs: track active provisioning jobs (TTL: 1 hour), store subdomain-to-runId mappings during provisioning, and cache Coolify health check results. This is key-value data with short TTLs -- a full database (Postgres, Supabase) is overkill. Vercel KV is Redis-backed, globally distributed, and accessible from Nuxt server routes via Nitro's built-in `useStorage()` driver. If future needs expand (instance dashboard, user accounts), upgrade to Supabase/Neon Postgres then.

### Hosting & Deployment

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Vercel | Pro plan | Hosting, CDN, serverless functions | Current landing page already on Vercel. Zero-config Nuxt deployment. Fluid Compute for cost-effective serverless. NuxtLabs now part of Vercel -- first-class support guaranteed. |
| Vercel Fluid Compute | Latest | Serverless execution model | Multi-request concurrency per instance reduces cold starts. Separate CPU/memory billing is more cost-effective than wall-clock billing. Up to 800s max duration on Pro (far more than needed). |

**Vercel Pro plan rationale:**

Hobby plan limits serverless functions to 60s (traditional) or 300s (Fluid Compute). Pro provides 800s max duration and 1,000 GB-hours included. For the webhook + provisioning polling pattern, function durations are sub-second -- the included quota will not be a concern. Pro also provides team collaboration, preview deployments per PR, and analytics.

## Architecture: Backend for Frontend (BFF)

Nuxt 4 with Nitro eliminates the need for a separate backend service. Server routes in `server/api/` deploy as Vercel serverless functions automatically:

```
server/
  api/
    stripe/
      checkout.post.ts      -- Create Checkout Session
      webhook.post.ts       -- Handle Stripe events
      portal.post.ts        -- Create Billing Portal session
    provision/
      trigger.post.ts       -- Dispatch GitHub Actions workflow
      status.get.ts         -- Poll provisioning progress
    health.get.ts            -- App health check
```

Each file becomes an isolated serverless function on Vercel. No Express, no Fastify, no separate API deployment. Nitro's `$fetch` enables direct server-to-server calls when rendering SSR pages, avoiding extra HTTP roundtrips.

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Framework | Nuxt 4 | Next.js 15 | React ecosystem is larger but project already has Vue landing page context. Nuxt's Nitro server routes provide cleaner BFF pattern than Next.js Route Handlers. NuxtLabs joining Vercel ensures parity. |
| Framework | Nuxt 4 | SvelteKit | Best raw performance and smallest bundles, but smallest ecosystem. Fewer SaaS modules, harder to find Svelte developers. Overkill optimization for a provisioning app that is not performance-critical. |
| Framework | Nuxt 4 | TanStack Start | Still RC (not v1.0 stable). Missing React Server Components support. Thin ecosystem, few plugins. Too risky for a production SaaS onboarding flow. |
| Framework | Nuxt 4 | Vue 3 + Quasar | Quasar excels at cross-platform (mobile/desktop) which is not needed here. Quasar's SSR story is weaker than Nuxt's. No zero-config Vercel deployment. Material Design aesthetic does not match a modern SaaS landing page. |
| UI Library | Nuxt UI | Vuetify 3 | Material Design-focused, heavier bundle. Nuxt UI is Tailwind-native and built by the Nuxt team. |
| UI Library | Nuxt UI | PrimeVue | Good component library but not Tailwind-native. Nuxt UI has official SaaS template. |
| Real-time | Polling | SSE | Vercel's 10s serverless timeout kills SSE connections. Edge runtime workaround adds complexity and cost (billed for entire stream duration). Polling is 100x cheaper for a 4-min wait. |
| Real-time | Polling | WebSockets | Vercel does not support persistent WebSocket connections in serverless functions. Would require a separate service (Pusher, Ably, etc.) -- unnecessary infrastructure for a polling use case. |
| Email | Resend | SendGrid | SendGrid is more complex to configure, designed for high-volume marketing email. Resend is purpose-built for developer transactional email with cleaner DX and Vercel integration. |
| Email | Resend | AWS SES | Cheapest at scale but complex DNS/IAM setup. Not worth the overhead for <100 emails/month initially. |
| Data | Vercel KV | Supabase | Full Postgres database is overkill for short-lived provisioning state. Adds auth complexity and another service to manage. Upgrade path exists if needed later. |
| Data | Vercel KV | Upstash Redis | Vercel KV is Upstash Redis underneath but with tighter Vercel integration and billing. Same thing, better DX. |

## What NOT to Add

| Technology | Why Not |
|------------|---------|
| Express / Fastify | Nitro server routes handle all API needs. Adding a Node.js framework defeats the purpose of Nuxt's integrated backend. |
| Separate backend service | The BFF pattern with Nuxt server routes eliminates the need for a standalone API. Stripe webhooks, GitHub API calls, and status polling all fit in serverless functions. |
| Database (Postgres/MySQL) | No relational data to model. Provisioning state is ephemeral KV data. If user accounts are needed later, add Supabase then. |
| Auth (NextAuth/Lucia) | v2.0 has no user accounts. Stripe Checkout handles customer identity. Provisioning is a one-shot flow. Auth is a future concern for the instance dashboard milestone. |
| WebSocket service (Pusher/Ably) | Polling every 5s is simpler, cheaper, and sufficient for a 4-minute wait. No bidirectional communication needed. |
| Docker | The onboarding frontend is a Vercel-deployed Nuxt app. Docker is for the Drupal platform, not the frontend. |
| Tailwind UI (paid) | Nuxt UI includes 125+ production components with Tailwind. No need for a separate paid component library. |
| Prisma / Drizzle ORM | No database means no ORM needed. If/when a DB is added, reassess. |

## Installation

```bash
# Initialize Nuxt 4 project
npx nuxi@latest init localnodes-onboarding
cd localnodes-onboarding

# Core dependencies
npx nuxi module add ui
npx nuxi module add @unlok-co/nuxt-stripe

# Server-side dependencies
npm install stripe octokit resend @react-email/components

# Dev dependencies
npm install -D @nuxt/test-utils vitest @vue/test-utils
```

### Environment Variables (Vercel Dashboard)

```bash
# Stripe
STRIPE_SECRET_KEY=sk_live_...
STRIPE_PUBLISHABLE_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...

# GitHub (for provisioning dispatch)
GITHUB_TOKEN=ghp_...           # repo + workflow scope
GITHUB_OWNER=LocalNodes
GITHUB_REPO=os-knowledge-garden
GITHUB_WORKFLOW=provision-instance.yml

# Email
RESEND_API_KEY=re_...

# Vercel KV (auto-configured via Vercel integration)
KV_REST_API_URL=...
KV_REST_API_TOKEN=...
```

### nuxt.config.ts (Skeleton)

```typescript
export default defineNuxtConfig({
  modules: ['@nuxt/ui', '@unlok-co/nuxt-stripe'],

  stripe: {
    server: {
      key: process.env.STRIPE_SECRET_KEY,
    },
    client: {
      key: process.env.STRIPE_PUBLISHABLE_KEY,
    },
  },

  nitro: {
    preset: 'vercel',
    storage: {
      provision: {
        driver: 'vercelKV',
      },
    },
  },

  routeRules: {
    '/': { prerender: true },           // Static landing page
    '/pricing': { prerender: true },     // Static pricing page
    '/provision/**': { ssr: true },      // SSR for provisioning flow
    '/api/**': { cors: false },          // Server routes (no CORS needed)
  },
})
```

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Nuxt 4 on Vercel | HIGH | Zero-config deployment confirmed. NuxtLabs acquired by Vercel July 2025. Official docs verified. |
| Stripe integration | HIGH | @unlok-co/nuxt-stripe verified on npm/Nuxt modules. Stripe Pricing Table + Checkout is well-documented. |
| GitHub Actions dispatch | HIGH | `return_run_details` parameter confirmed Feb 2026 changelog. Eliminates historical pain point. |
| Polling over SSE | HIGH | Vercel SSE limitations well-documented (10s serverless timeout). Polling math confirms 100x cost advantage. |
| Resend | HIGH | Official Vercel integration. Free tier confirmed at 3,000/month. REST API verified. |
| Vercel KV | MEDIUM | Works for short-lived state. If provisioning volume exceeds KV limits, may need Upstash Redis direct. Nuxt/Nitro `useStorage()` driver confirmed. |
| Nuxt UI SaaS template | MEDIUM | Template exists but specific v4 compatibility not independently verified. Nuxt UI v4.3 targets Nuxt 4. |

## Sources

- [Nuxt 4 Announcement](https://nuxt.com/blog/v4)
- [Nuxt on Vercel (Official Docs)](https://vercel.com/docs/frameworks/full-stack/nuxt)
- [Nuxt UI Documentation](https://ui.nuxt.com/)
- [Nuxt Deploy to Vercel](https://nuxt.com/deploy/vercel)
- [@unlok-co/nuxt-stripe (Nuxt Modules)](https://nuxt.com/modules/stripe-next)
- [Stripe Embeddable Pricing Table](https://docs.stripe.com/payments/checkout/pricing-table)
- [Stripe SaaS Integration Guide](https://docs.stripe.com/saas)
- [GitHub Workflow Dispatch API Returns Run IDs (Feb 2026)](https://github.blog/changelog/2026-02-19-workflow-dispatch-api-now-returns-run-ids/)
- [GitHub REST API: Workflow Runs](https://docs.github.com/en/rest/actions/workflow-runs)
- [Resend Pricing](https://resend.com/pricing)
- [Resend + Vercel Functions](https://resend.com/docs/send-with-vercel-functions)
- [Vercel Function Duration Limits](https://vercel.com/docs/functions/configuring-functions/duration)
- [Vercel Fluid Compute Pricing](https://vercel.com/docs/functions/usage-and-pricing)
- [SSE vs WebSockets vs Polling (2025)](https://dev.to/haraf/server-sent-events-sse-vs-websockets-vs-long-polling-whats-best-in-2025-5ep8)
- [Vercel SSE Time Limits Discussion](https://community.vercel.com/t/sse-time-limits/5954)
- [Nuxt 3 EOL / Nuxt 4 Migration Guide](https://nuxt.com/docs/4.x/getting-started/upgrade)
- [TanStack Start Status (InfoQ)](https://www.infoq.com/news/2025/11/tanstack-start-v1/)
- [Quasar Framework](https://quasar.dev/)
- [Nitro Server Routes (Nuxt)](https://masteringnuxt.com/blog/building-api-routes-with-nuxt-3s-nitro-server)
