# Architecture Patterns

**Domain:** Self-service SaaS onboarding frontend with async provisioning pipeline
**Researched:** 2026-03-02

## Recommended Architecture

### High-Level System Diagram

```
                                    FRONTEND (Vercel)
                                    ==================
                                    Full-stack framework (Nuxt 4 / Nitro)
                                    - Landing page (prerendered)
                                    - Onboarding form
                                    - Stripe Checkout redirect
                                    - Provisioning status UI (polling)

                                           |
                                           | Server routes (Vercel serverless)
                                           v

                                    BACKEND LAYER (Vercel Serverless)
                                    =================================
                                    Nitro server routes:
                                    - POST /api/provision     (validate + create Stripe session)
                                    - POST /api/webhooks/stripe  (payment confirmation + trigger)
                                    - GET  /api/status/[sessionId] (poll GH Actions status)
                                    - POST /api/notify           (send email on completion)

                                           |
                          +----------------+------------------+
                          |                |                  |
                          v                v                  v

                    GitHub Actions    Stripe API         Upstash Redis
                    ==============    ==========         =============
                    workflow_dispatch  Checkout Sessions   Provisioning state
                    return_run_details Payment webhooks    Idempotency keys
                    Run/Job status API                     Rate limiting

                          |
                          v

                    Coolify API (coolify.localnodes.xyz)
                    ====================================
                    Create application
                    Set env vars
                    Trigger deploy
                    Poll deployment status

                          |
                          v

                    Drupal Instance ({subdomain}.localnodes.xyz)
                    ============================================
                    Site install (automatic via entrypoint)
                    User creation (post-deploy step in GH Actions)
```

**Note on framework:** The STACK.md recommends Nuxt 4 with Nitro server routes. All architecture patterns below are framework-agnostic -- the integration points (Stripe webhooks, GitHub API, Redis, Resend) work identically whether the server routes are Nitro (Nuxt), Route Handlers (Next.js), or standalone functions. Code examples use TypeScript with standard `Request`/`Response` objects for clarity.

### Component Boundaries

| Component | Responsibility | Communicates With |
|-----------|---------------|-------------------|
| **Frontend App** | Landing page, onboarding form, Stripe redirect, status polling UI | Server routes (same app) |
| **Server Route: /api/provision** | Validate inputs, check subdomain availability via Coolify API, create Stripe Checkout Session, store pending provision in Redis | Stripe API, Coolify API, Upstash Redis |
| **Server Route: /api/webhooks/stripe** | Receive `checkout.session.completed`, verify signature, trigger GitHub Actions `workflow_dispatch` with `return_run_details`, store run ID in Redis | Stripe webhooks, GitHub Actions API, Upstash Redis |
| **Server Route: /api/status/[sessionId]** | Poll GitHub Actions run status + job steps, return structured progress to frontend | GitHub Actions API, Upstash Redis (cached status) |
| **Server Route: /api/notify** | Called by GH Actions workflow (final step) to send "instance ready" email | Resend API, Upstash Redis |
| **GitHub Actions Workflow** | Orchestrates Coolify provisioning: create app, set env vars, deploy, wait for healthy, create user, call notify endpoint | Coolify API, Drupal instance (SSH/drush), Notify endpoint |
| **Upstash Redis** | Provisioning state store: session-to-runId mapping, idempotency, rate limiting, cached status | All server routes |
| **Coolify API** | Application lifecycle: create, configure env, deploy, monitor | GitHub Actions, Drupal containers |
| **Drupal Instance** | The provisioned knowledge garden | End users |

### Data Flow

**Happy Path: User provisions a new instance**

```
1. User fills onboarding form (community name, email)
      |
2. Frontend validates subdomain format (client-side)
      |
3. POST /api/provision
   - Server validates subdomain (alphanumeric + hyphens)
   - Checks subdomain availability via Coolify API (GET /applications, check domains)
   - Creates Stripe Checkout Session with metadata: {subdomain, siteName, email}
   - Stores pending provision in Redis: provision:{sessionId} = {subdomain, siteName, email, status: "awaiting_payment"}
   - Returns Stripe Checkout URL to frontend
      |
4. Frontend redirects to Stripe Checkout (hosted page)
      |
5. User completes payment on Stripe
      |
6. TWO THINGS HAPPEN IN PARALLEL:
   a. Stripe redirects user to success_url (e.g., /provision/status?session_id=cs_xxx)
      - Frontend shows "Setting up your knowledge garden..." with progress UI
      - Frontend begins polling GET /api/status/{sessionId}
   b. Stripe sends checkout.session.completed webhook to POST /api/webhooks/stripe
      - Verify Stripe signature
      - Check idempotency (has this session already been processed?)
      - Extract metadata: subdomain, siteName, email
      - Trigger GitHub Actions workflow_dispatch with return_run_details=true
        POST https://api.github.com/repos/LocalNodes/os-knowledge-garden/actions/workflows/provision-instance.yml/dispatches
        Body: {ref: "main", return_run_details: true, inputs: {subdomain, site_name, demo_module: "none", organizer_email, callback_url}}
      - Receive workflow_run_id in response (NEW Feb 2026 feature)
      - Update Redis: provision:{sessionId}.runId = workflow_run_id, status = "provisioning"
      - Return 200 to Stripe immediately (must respond within 20s)
      |
7. Frontend polls GET /api/status/{sessionId} every 5 seconds
   - Server route reads provision:{sessionId} from Redis to get runId
   - If runId exists, queries GitHub Actions:
     GET /repos/LocalNodes/os-knowledge-garden/actions/runs/{runId}
     GET /repos/LocalNodes/os-knowledge-garden/actions/runs/{runId}/jobs
   - Maps job steps to user-friendly progress stages:
     "Validate subdomain" -> "Validating your community name..."
     "Check subdomain availability" -> "Checking availability..."
     "Create Coolify application" -> "Creating your knowledge garden..."
     "Configure environment variables" -> "Configuring your instance..."
     "Deploy instance" -> "Deploying..."
     "Wait for deployment" -> "Building (this takes a few minutes)..."
     "Wait for site to become healthy" -> "Installing your platform..."
     "Create organizer account" -> "Setting up your account..."
   - Caches GitHub API result in Redis for 5-10s (avoid rate limits)
   - Returns: {status, currentStep, totalSteps, stepName, url?, error?}
   - If runId not yet in Redis (webhook hasn't fired), returns {status: "awaiting_payment"}
      |
8. GH Actions workflow completes successfully
   - "Create organizer account" step: SSH + docker exec + drush user:create
   - "Notify completion" step: POST to /api/notify with {email, subdomain, url, password, status: "complete"}
      |
9. POST /api/notify
   - Authenticates via NOTIFY_SECRET header
   - Sends email via Resend: "Your knowledge garden is ready!"
     - Contains: site URL, login credentials, getting-started links
   - Updates Redis: provision:{sessionId}.status = "complete", url = "https://{subdomain}.localnodes.xyz"
      |
10. Frontend polling detects "complete" status
    - Shows success UI with link to new instance + credentials
    - Stops polling (isPaused returns true)
```

**Race Condition Handling:** The Stripe success redirect (6a) can arrive at the frontend BEFORE the webhook (6b) fires. The status endpoint handles this gracefully: if `runId` is not yet in Redis, it returns `{status: "processing_payment"}` and the frontend shows "Processing your payment..." The next poll after the webhook fires will show provisioning progress.

## Architecture Decision: Vercel Serverless (NOT a Separate Server)

**Decision:** Use a full-stack framework on Vercel with serverless API routes as the thin backend layer. No separate server.

**Rationale:**
- The onboarding frontend is a low-traffic service (maybe a few provisions per day initially)
- The heavy lifting is done by GitHub Actions and Coolify, not the backend
- Vercel serverless functions handle webhook receipt, API polling, and email dispatch well within timeout limits
- The existing landing page is already on Vercel -- this replaces it
- Zero ops burden: no server to maintain, auto-scaling, edge CDN for the frontend
- Cost: free tier covers the expected traffic easily

**Why NOT a separate server (Express, Fastify, etc.):**
- Provisioning volume does not justify always-on infrastructure
- Would require separate deployment pipeline, monitoring, SSL management
- Coolify server already has enough containers; adding another service increases ops burden
- The backend is genuinely thin -- it is a pass-through to GitHub Actions + Stripe

**Why NOT Cloudflare Workers:**
- The project is already on Vercel (existing landing page)
- Stripe SDK and GitHub API clients work well in Node.js serverless
- Vercel's framework integration is tighter than Cloudflare's

## Patterns to Follow

### Pattern 1: Webhook-First Provisioning (Payment Verification Before Action)

**What:** Never trigger provisioning from the frontend success redirect. Always trigger from the Stripe webhook.

**When:** Always. This is the critical security boundary.

**Why:** Users can manipulate the success URL redirect. The webhook comes directly from Stripe with a verified signature. If a user closes the browser after payment, the webhook still fires and provisioning still happens. Stripe's own documentation says: "You can't rely on triggering fulfillment only from your Checkout landing page."

**Example:**
```typescript
// server/api/webhooks/stripe.post.ts (Nitro) or app/api/webhooks/stripe/route.ts (Next.js)
export default defineEventHandler(async (event) => {
  const body = await readRawBody(event);
  const sig = getHeader(event, 'stripe-signature');

  let stripeEvent: Stripe.Event;
  try {
    stripeEvent = stripe.webhooks.constructEvent(body!, sig!, process.env.STRIPE_WEBHOOK_SECRET!);
  } catch (err) {
    throw createError({ statusCode: 400, message: 'Invalid signature' });
  }

  if (stripeEvent.type === 'checkout.session.completed') {
    const session = stripeEvent.data.object as Stripe.Checkout.Session;
    const { subdomain, siteName, email } = session.metadata!;

    // Idempotency check
    const existing = await redis.get(`provision:${session.id}`);
    if (existing?.status === 'provisioning' || existing?.status === 'complete') {
      return { received: true };
    }

    // Trigger GitHub Actions with return_run_details (Feb 2026 feature)
    const response = await $fetch(
      `https://api.github.com/repos/${owner}/${repo}/actions/workflows/provision-instance.yml/dispatches`,
      {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${process.env.GITHUB_PAT}`,
          'Accept': 'application/vnd.github+json',
        },
        body: {
          ref: 'main',
          return_run_details: true,
          inputs: {
            subdomain,
            site_name: siteName,
            demo_module: 'none',
            organizer_email: email,
            callback_url: `${process.env.APP_URL}/api/notify`,
          },
        },
      }
    );

    const { workflow_run_id } = response;

    await redis.set(`provision:${session.id}`, {
      subdomain, siteName, email,
      runId: workflow_run_id,
      status: 'provisioning',
      triggeredAt: Date.now(),
    }, { ex: 86400 }); // TTL: 24 hours
  }

  return { received: true };
});
```

### Pattern 2: Client Polling (NOT SSE/WebSocket)

**What:** Use client-side polling with a 5-second interval, not SSE or WebSocket.

**When:** For the provisioning status UI during the ~4 minute wait.

**Why:**
- SSE on Vercel serverless has timeout issues (10s Hobby, 60s Pro) -- provisioning takes ~4 minutes
- SSE requires the connection to stay open; if it drops, you need reconnect logic anyway
- Polling a lightweight status endpoint every 5 seconds is 48 requests over 4 minutes -- trivially cheap
- The status endpoint caches in Redis, so most polls do not hit GitHub's API
- Polling math: 48 calls x ~50ms each = ~2.4s of function time vs SSE holding connection for 240+ seconds

**Implementation note for Nuxt:** Use `useAsyncData` with `watch` and a `setInterval` ref, or `useFetch` with periodic refresh. For Vue-based polling, a composable like `usePolling` wrapping `$fetch` with `setInterval(5000)` is idiomatic. SWR-style behavior (stale-while-revalidate) can be achieved with `useLazyFetch` + `refresh()`.

### Pattern 3: GitHub Actions Run ID Direct Tracking (NEW Feb 2026)

**What:** Use the `return_run_details` parameter when triggering `workflow_dispatch` to get the run ID immediately, then poll that specific run.

**When:** Always when triggering provisioning from the webhook handler.

**Why:** Before Feb 2026, triggering a workflow_dispatch returned 204 No Content with no way to know which run was started. You had to poll the runs list and try to match by timestamp -- fragile and race-prone. Now you get `workflow_run_id` directly.

**Step-level progress tracking:**
```typescript
// Get workflow run status
const run = await $fetch(
  `https://api.github.com/repos/${owner}/${repo}/actions/runs/${runId}`,
  { headers: { Authorization: `Bearer ${token}` } }
);
// run.status: "queued" | "in_progress" | "completed"
// run.conclusion: "success" | "failure" | "cancelled" | null (while in_progress)

// Get step-level progress from jobs
const { jobs } = await $fetch(
  `https://api.github.com/repos/${owner}/${repo}/actions/runs/${runId}/jobs`,
  { headers: { Authorization: `Bearer ${token}` } }
);
const steps = jobs[0]?.steps || [];
// Each step: { name, status, conclusion, number, started_at, completed_at }
// status: "queued" | "in_progress" | "completed"

// Map to user-friendly progress
const STEP_LABELS: Record<string, string> = {
  'Validate subdomain': 'Validating your community name...',
  'Check subdomain availability': 'Checking availability...',
  'Create Coolify application': 'Creating your knowledge garden...',
  'Configure environment variables': 'Configuring your instance...',
  'Deploy instance': 'Deploying...',
  'Wait for deployment': 'Building (this takes a few minutes)...',
  'Wait for site to become healthy': 'Installing your platform...',
  'Create organizer account': 'Setting up your account...',
  'Notify completion': 'Finalizing...',
};

const currentStep = steps.findIndex(s => s.status === 'in_progress') + 1;
const totalSteps = steps.length;
const stepName = STEP_LABELS[steps[currentStep - 1]?.name] || 'Processing...';
```

### Pattern 4: User Creation via Extended GitHub Actions Workflow

**What:** Add steps to the existing `provision-instance.yml` that (a) create the organizer's user account on the new Drupal instance via SSH + docker exec + drush, and (b) POST a completion callback to the onboarding app.

**When:** After the "Wait for site to become healthy" step succeeds.

**Why:**
- The Coolify execute API endpoint is broken for Docker Compose apps (Issue #5387, filed March 2025, still open as of March 2026)
- Drush `user:create` is reliable and well-documented
- The GitHub Actions runner can SSH to the Coolify server (key already configured for CI)
- Adding inputs for `organizer_email` and `callback_url` keeps the workflow self-contained
- No need to expose Drupal REST APIs or manage per-instance auth tokens

**Changes to provision-instance.yml:**
```yaml
# NEW INPUTS (add to existing inputs section)
inputs:
  # ... existing: subdomain, site_name, demo_module ...
  organizer_email:
    description: 'Email for the community organizer account'
    required: false
    type: string
  callback_url:
    description: 'URL to POST completion notification to'
    required: false
    type: string

# NEW STEPS (add after "Wait for site to become healthy")

- name: Create organizer account
  if: inputs.organizer_email != ''
  env:
    SSH_PRIVATE_KEY: ${{ secrets.COOLIFY_SSH_KEY }}
  run: |
    ORGANIZER_PASS=$(openssl rand -base64 12)
    echo "ORGANIZER_PASS=$ORGANIZER_PASS" >> "$GITHUB_ENV"

    # Set up SSH
    mkdir -p ~/.ssh
    echo "$SSH_PRIVATE_KEY" > ~/.ssh/id_rsa
    chmod 600 ~/.ssh/id_rsa

    # Find the Drupal container for this instance
    CONTAINER=$(ssh -o StrictHostKeyChecking=no root@localnodes.xyz \
      "docker ps --filter 'label=coolify.applicationId' --format '{{.Names}}' \
       | grep '$SUBDOMAIN' | grep opensocial | head -1")

    if [ -z "$CONTAINER" ]; then
      echo "::warning::Could not find container for $SUBDOMAIN"
      exit 0
    fi

    # Create the organizer user with sitemanager role
    ssh root@localnodes.xyz \
      "docker exec $CONTAINER drush user:create organizer \
        --mail='${{ inputs.organizer_email }}' \
        --password='$ORGANIZER_PASS'"

    ssh root@localnodes.xyz \
      "docker exec $CONTAINER drush user:role:add 'sitemanager' organizer"

    echo "Created organizer account for ${{ inputs.organizer_email }}"

- name: Notify completion
  if: inputs.callback_url != ''
  run: |
    curl -s -X POST "${{ inputs.callback_url }}" \
      -H "Content-Type: application/json" \
      -H "Authorization: Bearer ${{ secrets.NOTIFY_SECRET }}" \
      -d "{
        \"subdomain\": \"$SUBDOMAIN\",
        \"url\": \"https://$FQDN\",
        \"email\": \"${{ inputs.organizer_email }}\",
        \"password\": \"$ORGANIZER_PASS\",
        \"status\": \"complete\"
      }"
```

**SSH key requirement:** The GitHub Actions runner needs an SSH private key that can access `root@localnodes.xyz`. Store this as `COOLIFY_SSH_KEY` in the GitHub repository secrets. This is the same key used by Coolify internally to manage its own server.

### Pattern 5: Redis as State Bridge Between Async Components

**What:** Use Upstash Redis as the shared state store connecting Stripe webhooks, GitHub Actions callbacks, status polling, and email notifications.

**When:** For all provisioning state that must survive across serverless function invocations.

**Why:**
- Serverless functions are stateless -- state must live externally
- Redis is fast enough for polling (sub-ms reads), cheap (Upstash free tier: 10k commands/day), and simple
- Provides natural TTL for cleanup (expire provisioning records after 24h)
- Enables idempotency checks (prevent double-provisioning from duplicate webhooks)
- Caches GitHub API responses (avoid rate limiting during polling)

**Data model:**
```
# Primary provisioning record (keyed by Stripe session ID)
provision:{stripeSessionId} = {
  subdomain: string,
  siteName: string,
  email: string,
  runId: number | null,           // Set when GH Actions triggered
  status: "awaiting_payment" | "processing_payment" | "provisioning" | "complete" | "failed",
  url: string | null,             // Set on completion
  password: string | null,        // Set on completion (from callback)
  error: string | null,           // Set on failure
  triggeredAt: number,
  completedAt: number | null,
}
TTL: 86400 (24 hours)

# Idempotency guard (prevent duplicate webhook processing)
processed:{stripeEventId} = "1"
TTL: 86400

# GitHub API response cache (avoid rate limiting during polling)
gh-status:{runId} = { status, conclusion, steps, cachedAt }
TTL: 10 (seconds)

# Rate limiting for /api/provision endpoint
ratelimit:{ip} = count
TTL: 3600
```

**Nitro storage integration:** Nuxt 4's Nitro provides `useStorage()` which can be configured with an Upstash Redis driver. This means server routes can read/write Redis without importing a client library:

```typescript
// nitro.config: storage.provision.driver = 'upstash'
const state = await useStorage('provision').getItem(sessionId);
await useStorage('provision').setItem(sessionId, { ...state, status: 'provisioning' });
```

## Anti-Patterns to Avoid

### Anti-Pattern 1: Triggering Provisioning from Stripe Success Redirect

**What:** Calling the provision API from the success page that Stripe redirects to after payment.

**Why bad:** Users can manipulate the success URL, bookmark it, or refresh it. The redirect may never happen if the user closes the browser. You end up with either unpaid provisions or duplicate provisions.

**Instead:** Only trigger provisioning from the Stripe webhook (`checkout.session.completed`). The success redirect page should only display a "please wait" status UI that polls for progress.

### Anti-Pattern 2: SSE for Long-Running Status on Vercel Serverless

**What:** Using Server-Sent Events to stream provisioning progress over 4+ minutes.

**Why bad:** Vercel Hobby has a 10-second function timeout. Even Pro has 60 seconds (300s with Fluid Compute, 800s max). The provisioning takes ~4 minutes. SSE connections will drop, requiring reconnection logic that is more complex than polling. On Vercel, the SSE handler cannot maintain state across reconnections because it is a new function invocation each time. Billing is also worse: SSE charges for the entire stream duration.

**Instead:** Use client-side polling at a 5-second interval. The status endpoint is a simple GET that reads from Redis cache, executes in <100ms. 48 requests x 50ms = 2.4s total function time vs 240+ seconds for SSE.

### Anti-Pattern 3: Storing State in Serverless Function Memory

**What:** Using in-memory variables, Maps, or module-level caches to track provisioning state.

**Why bad:** Each serverless function invocation may run on a different container. There is no shared memory between invocations. State disappears after each request.

**Instead:** Use Upstash Redis for all state. Every function invocation reads from and writes to Redis.

### Anti-Pattern 4: Using Coolify Execute API for Docker Compose Apps

**What:** Calling `POST /api/v1/applications/{uuid}/execute` to run drush commands in the Drupal container.

**Why bad:** This endpoint returns 404 for Docker Compose applications (Coolify issue #5387, filed March 2025, still open). It only works for single-container (Dockerfile) deployments.

**Instead:** SSH to the Coolify server and use `docker exec` to run drush commands inside the specific container. This is the pattern already established in the existing provisioning workflow.

### Anti-Pattern 5: Polling GitHub API Without Caching

**What:** Having each frontend poll request directly hit the GitHub Actions API.

**Why bad:** GitHub API has rate limits (5,000 requests/hour for authenticated, 60/hour unauthenticated). If multiple users are watching provisioning status, or a single user's browser sends requests rapidly, you will hit rate limits. Each API call also adds ~200-400ms latency.

**Instead:** Cache GitHub API responses in Redis with a 5-10 second TTL. The status endpoint checks Redis first and only calls GitHub when the cache is stale.

### Anti-Pattern 6: Passing Sensitive Credentials in GitHub Actions Inputs

**What:** Passing the organizer's password as a workflow input.

**Why bad:** Workflow inputs are visible in the GitHub Actions UI and API responses. Anyone with read access to the repository can see the password.

**Instead:** Generate the password INSIDE the workflow (using `openssl rand -base64 12`) and pass it back via the callback URL over HTTPS. The callback endpoint is authenticated with `NOTIFY_SECRET`.

## Scalability Considerations

| Concern | At 10 provisions/week | At 100 provisions/week | At 1000 provisions/week |
|---------|----------------------|------------------------|-------------------------|
| **Vercel serverless** | Free tier, no issues | Free tier still sufficient | Pro tier, still fine |
| **GitHub Actions** | Free tier (2000 min/month), ~13 min each = 130 min | 1300 min, approaching limit | Need GitHub Team or self-hosted runners |
| **Stripe** | Standard fees (2.9% + 30c) | Same | Same, consider volume discount |
| **Upstash Redis** | Free tier (10k cmds/day) | Free tier borderline | Paid tier ($10/mo) |
| **Coolify server** | Current single server fine | May need resource monitoring | Need larger server or multi-server |
| **Email (Resend)** | Free tier (100/day) | Free tier sufficient | May need paid tier ($20/mo) |
| **Subdomain management** | Wildcard DNS, no issue | No issue | No issue (DNS is cheap) |
| **Concurrent provisions** | Rare, no issue | Possible, GH Actions queues naturally | Need parallel runners, potential Coolify contention |

The primary scaling bottleneck is the **Coolify server resources** -- each Drupal instance runs Solr + Qdrant + MariaDB + PHP. At scale, this requires either larger servers or a multi-server Coolify setup. This is an infrastructure concern, not an architecture concern for the onboarding frontend.

## New Components (to build)

| Component | Type | Location | Effort |
|-----------|------|----------|--------|
| Full-stack app (landing + onboarding) | New | Separate repo (`localnodes-web`) | Medium |
| `/api/provision` server route | New | App server routes | Low |
| `/api/webhooks/stripe` server route | New | App server routes | Medium |
| `/api/status/[sessionId]` server route | New | App server routes | Low |
| `/api/notify` server route | New | App server routes | Low |
| Provisioning status UI component | New | App components | Medium |
| Upstash Redis integration | New | App server routes | Low |
| Resend email integration + templates | New | App server routes | Low |

## Modified Components (existing)

| Component | Change | Effort |
|-----------|--------|--------|
| `provision-instance.yml` | Add `organizer_email`, `callback_url` inputs; add SSH key setup; add user creation step; add completion notification step | Low |
| GitHub repo secrets | Add `COOLIFY_SSH_KEY`, `NOTIFY_SECRET` | Trivial |
| Coolify DNS / wildcard | Already in place (*.localnodes.xyz) | None |

## Build Order (respects dependencies)

```
Phase 1: Foundation & Accounts
  - Set up project on Vercel (replaces static landing page)
  - Provision Upstash Redis (free tier)
  - Configure Stripe account + products/prices
  - Verify Resend domain (localnodes.xyz)
  - Add COOLIFY_SSH_KEY + NOTIFY_SECRET to GitHub secrets

Phase 2: Payment Flow (frontend -> Stripe -> webhook)
  - Onboarding form UI (community name, email, subdomain preview)
  - /api/provision (subdomain validation via Coolify API + Stripe Checkout Session creation)
  - /api/webhooks/stripe (signature verification + idempotency + GH Actions dispatch)
  - Redis state management for provision records
  - Test: form -> Stripe -> webhook fires -> run ID stored in Redis

Phase 3: Provisioning Integration (webhook -> GH Actions -> Drupal)
  - Extend provision-instance.yml with new inputs + user creation + callback
  - SSH key configuration for GH Actions runner
  - Test: webhook -> GH Actions triggers -> Coolify creates app -> user created -> callback fires

Phase 4: Status & Notification (real-time feedback + email)
  - /api/status/[sessionId] endpoint (GitHub API polling + Redis caching)
  - Provisioning status UI component (polling with step labels)
  - /api/notify endpoint (Resend email + Redis status update)
  - React Email template for "instance ready" notification
  - Test: full flow end-to-end

Phase 5: Landing Page & Production Hardening
  - Replace static index.html with full landing page
  - Rate limiting on /api/provision
  - Error handling and retry flows
  - DNS cutover from static site to new app
```

## Key Integration Points Summary

| Integration | Method | Auth | Notes |
|-------------|--------|------|-------|
| Frontend -> Stripe | Redirect to Checkout Session | Stripe publishable key | Server creates session, client redirects |
| Stripe -> Backend | Webhook POST | Signature verification (`whsec_`) | Must return 200 within 20s |
| Backend -> Coolify (availability) | REST API GET `/applications` | Coolify API token | Check subdomain not already in use |
| Backend -> GitHub Actions | REST API POST (workflow_dispatch) | GitHub PAT (`repo` scope) | Use `return_run_details: true` |
| Backend -> GitHub Actions (status) | REST API GET (runs, jobs) | GitHub PAT | Cache in Redis, 5-10s TTL |
| GitHub Actions -> Coolify | REST API (create, env, deploy) | Coolify API token | Existing workflow pattern |
| GitHub Actions -> Drupal | SSH + docker exec + drush | SSH key (`COOLIFY_SSH_KEY`) | User creation post-deploy |
| GitHub Actions -> Backend | HTTP POST (callback) | Shared secret (`NOTIFY_SECRET`) | Completion notification with credentials |
| Backend -> Resend | REST API | Resend API key | Email notification |
| Backend -> Upstash Redis | HTTP (REST API) | Upstash token | State store, caching, idempotency |

## Sources

- [GitHub: Workflow dispatch API now returns run IDs (Feb 2026)](https://github.blog/changelog/2026-02-19-workflow-dispatch-api-now-returns-run-ids/) - HIGH confidence
- [GitHub Docs: REST API for workflow runs](https://docs.github.com/en/rest/actions/workflow-runs) - HIGH confidence
- [GitHub Docs: REST API for workflow jobs (steps array)](https://docs.github.com/en/rest/actions/workflow-jobs) - HIGH confidence
- [Stripe Docs: Fulfill orders with Checkout](https://docs.stripe.com/checkout/fulfillment) - HIGH confidence
- [Stripe Docs: Build a Checkout page](https://docs.stripe.com/checkout/quickstart?client=next) - HIGH confidence
- [Stripe Docs: Webhooks](https://docs.stripe.com/webhooks) - HIGH confidence
- [Coolify Issue #5387: 404 on execute command for Docker Compose apps](https://github.com/coollabsio/coolify/issues/5387) - HIGH confidence
- [Coolify Docs: Commands](https://coolify.io/docs/knowledge-base/commands) - MEDIUM confidence
- [Resend: Send emails with Next.js / Vercel](https://resend.com/docs/send-with-nextjs) - HIGH confidence
- [Upstash Redis: Serverless HTTP client](https://github.com/upstash/redis-js) - HIGH confidence
- [Upstash: Job Processing with Redis](https://upstash.com/docs/redis/tutorials/job_processing) - MEDIUM confidence
- [Vercel Community: SSE limitations on serverless](https://github.com/vercel/next.js/discussions/48427) - MEDIUM confidence
- [Drush: user:create command](https://www.drush.org/11.x/commands/user_create/) - HIGH confidence
