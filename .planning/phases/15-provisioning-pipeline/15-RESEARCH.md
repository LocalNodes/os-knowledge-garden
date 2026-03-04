# Phase 15: Provisioning Pipeline - Research

**Researched:** 2026-03-04
**Domain:** GitHub Actions workflow_dispatch orchestration, Upstash Redis state tracking, Drupal user provisioning via SSH + drush, Resend transactional email
**Confidence:** HIGH

## Summary

Phase 15 wires the existing Stripe webhook handler (Phase 14) to trigger the existing `provision-instance.yml` GitHub Actions workflow, tracks provisioning state in Upstash Redis for frontend polling, creates the organizer's admin account on the provisioned Drupal instance, generates a one-time login link, and sends a welcome email via Resend. The entire pipeline must be idempotent -- retrying the same webhook event must not create duplicate instances.

The key technical challenge is the multi-system orchestration: the Vercel-hosted webhook handler must (1) trigger a GitHub Actions workflow via the REST API with the new `return_run_details` parameter (available since February 19, 2026) to get a reliable run ID, (2) store provisioning state in Upstash Redis keyed by Stripe session ID, and (3) after the ~4 minute GitHub Actions workflow completes and the Drupal site is healthy, SSH into the Coolify server to run drush commands that create the admin user and generate a one-time login URL. The Coolify API's execute command endpoint (`POST /applications/{uuid}/execute`) is **commented out and returns 404** in the current version (v4.0.0-beta.463), so SSH + `docker exec` is the only option for running drush commands on provisioned instances.

The architecture splits into two execution contexts: (1) the Nitro server route on Vercel that handles the webhook and dispatches the GitHub Actions workflow, and (2) the GitHub Actions workflow itself that handles Coolify provisioning, waits for the site to become healthy, then SSHs back to create the user, generate the login URL, and trigger the welcome email. This two-context split keeps the webhook response fast (under Stripe's 20-second timeout) while the heavy provisioning runs asynchronously in GitHub Actions.

**Primary recommendation:** Extend the existing `provision-instance.yml` workflow with new inputs (`email`, `stripe_session_id`) and new post-provisioning steps (user creation via SSH + drush, one-time login URL generation, welcome email via Resend API). Use Upstash Redis hashes keyed by `provision:{stripe_session_id}` with a 24-hour TTL for state tracking. Use the `return_run_details: true` parameter on the workflow_dispatch API call to get a reliable run ID for status polling.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| PROV-01 | Provisioning triggers automatically after successful Stripe payment via webhook | Webhook handler calls GitHub API `workflow_dispatch` with `return_run_details: true`; stores run ID in Redis for tracking |
| PROV-02 | Admin user is created on the provisioned instance with the organizer's email | GitHub Actions workflow SSHs into Coolify server, runs `docker exec` + `drush user:create` with `--mail` and `--password` flags |
| PROV-03 | Unique password is auto-generated and organizer receives a one-time login link | `openssl rand -hex 16` for password in workflow; `drush uli --name=USER --uri=DOMAIN` for login link; Resend API sends welcome email |
| PROV-04 | Provisioning is idempotent -- retrying does not create duplicate instances | Redis `setnx` on `provision:{session_id}:lock` key guards against duplicate triggers; GitHub Actions workflow checks subdomain availability before creating app |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| @upstash/redis | 1.36.x | Redis state bridge between webhook, GitHub Actions, and frontend polling | HTTP-based (no TCP), works in Vercel serverless. Already a transitive dependency via unstorage. |
| resend | 6.9.x | Welcome email with one-time login link | Already decided in project architecture. Simple API, verified domain from Phase 12 DNS setup. |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| stripe | 20.4.0 (already installed) | Retrieve Checkout Session metadata in webhook | Already installed from Phase 14. Used to extract subdomain, email, community name from webhook event. |
| h3 | bundled with Nitro | readRawBody, getHeader, createError | Already used in webhook handler from Phase 14. |

### External APIs (no npm packages needed)
| API | Purpose | Auth |
|-----|---------|------|
| GitHub REST API (workflow_dispatch) | Trigger provisioning workflow | Fine-grained PAT with `actions:write` + `metadata:read` permissions |
| GitHub REST API (workflow runs) | Poll provisioning status | Same PAT |
| Coolify server via SSH | Run drush commands in provisioned containers | SSH key (already configured as GitHub secret) |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| @upstash/redis (direct) | Nuxt unstorage with upstash driver | unstorage abstracts Redis but adds indirection; direct @upstash/redis is clearer for hash operations with TTL and atomic setnx |
| SSH + docker exec for drush | Coolify API execute endpoint | Coolify execute endpoint is commented out (returns 404 in beta.463). SSH is the only working option. |
| GitHub PAT for workflow_dispatch | GitHub App installation token | PAT is simpler for a single-repo use case. GitHub App adds complexity for no gain here. |
| Resend Node.js SDK | Direct HTTP fetch to Resend API | SDK provides typed responses, error handling, and is 3 lines of code. No reason to hand-roll. |

**Installation:**
```bash
cd /Users/proofoftom/Code/os-decoupled/localnodes-onboarding
npm install @upstash/redis resend
```

## Architecture Patterns

### Recommended Project Structure (Phase 15 additions)
```
localnodes-onboarding/
├── server/
│   ├── api/
│   │   ├── stripe-webhook.post.ts   # MODIFIED: add provisioning trigger
│   │   ├── provision-status.get.ts   # NEW: polling endpoint for frontend
│   │   └── ...
│   └── utils/
│       ├── stripe.ts                 # Existing (Phase 14)
│       ├── redis.ts                  # NEW: Upstash Redis singleton
│       ├── github.ts                 # NEW: GitHub API helper (workflow dispatch + status)
│       └── resend.ts                 # NEW: Resend email singleton
├── tests/unit/
│   ├── provision-trigger.test.ts     # NEW: webhook provisioning logic tests
│   ├── provision-status.test.ts      # NEW: status polling tests
│   ├── idempotency.test.ts           # NEW: duplicate prevention tests
│   └── ...
└── nuxt.config.ts                    # MODIFIED: add Redis + GitHub + Resend env vars

# In the Drupal repo (fresh3):
.github/workflows/
└── provision-instance.yml            # MODIFIED: add email, session_id inputs + post-provision steps
```

### Pattern 1: Webhook-to-GitHub Actions Dispatch
**What:** The Stripe webhook handler extracts metadata from the checkout session and dispatches the GitHub Actions provisioning workflow via the REST API.
**When to use:** When `checkout.session.completed` event is received.
**Example:**
```typescript
// server/utils/github.ts
export async function dispatchProvisioningWorkflow(params: {
  subdomain: string
  siteName: string
  email: string
  stripeSessionId: string
}): Promise<{ runId: number; runUrl: string }> {
  const config = useRuntimeConfig()

  const response = await fetch(
    `https://api.github.com/repos/${config.githubRepo}/actions/workflows/provision-instance.yml/dispatches`,
    {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${config.githubToken}`,
        'Accept': 'application/vnd.github+json',
        'X-GitHub-Api-Version': '2022-11-28'
      },
      body: JSON.stringify({
        ref: 'main',
        return_run_details: true,
        inputs: {
          subdomain: params.subdomain,
          site_name: params.siteName,
          email: params.email,
          stripe_session_id: params.stripeSessionId,
          demo_module: 'localnodes_demo'
        }
      })
    }
  )

  if (!response.ok) {
    const error = await response.text()
    throw new Error(`GitHub API error (${response.status}): ${error}`)
  }

  // return_run_details: true returns 200 with run details
  const data = await response.json()
  return {
    runId: data.workflow_run_id,
    runUrl: data.html_url
  }
}
```
**Source:** [GitHub Changelog - Workflow dispatch API now returns run IDs](https://github.blog/changelog/2026-02-19-workflow-dispatch-api-now-returns-run-ids/), [GitHub REST API - Workflows](https://docs.github.com/en/rest/actions/workflows)

### Pattern 2: Redis State Tracking with Hash + TTL
**What:** Store structured provisioning state as a Redis hash keyed by Stripe session ID, with a 24-hour TTL for auto-cleanup.
**When to use:** Every state transition in the provisioning pipeline.
**Example:**
```typescript
// server/utils/redis.ts
import { Redis } from '@upstash/redis'

let _redis: Redis | null = null

export function useRedis(): Redis {
  if (!_redis) {
    const config = useRuntimeConfig()
    if (!config.upstashRedisUrl || !config.upstashRedisToken) {
      throw new Error('Upstash Redis credentials not configured')
    }
    _redis = new Redis({
      url: config.upstashRedisUrl,
      token: config.upstashRedisToken
    })
  }
  return _redis
}

// State shape stored in Redis hash
export interface ProvisioningState {
  status: 'triggered' | 'provisioning' | 'installing' | 'creating_user' | 'sending_email' | 'complete' | 'failed'
  subdomain: string
  email: string
  siteName: string
  stripeSessionId: string
  githubRunId?: string
  githubRunUrl?: string
  siteUrl?: string
  loginUrl?: string
  error?: string
  startedAt: string
  updatedAt: string
}

const PROVISION_TTL = 86400 // 24 hours

export async function setProvisioningState(
  sessionId: string,
  state: Partial<ProvisioningState>
): Promise<void> {
  const redis = useRedis()
  const key = `provision:${sessionId}`
  await redis.hset(key, { ...state, updatedAt: new Date().toISOString() })
  await redis.expire(key, PROVISION_TTL)
}

export async function getProvisioningState(
  sessionId: string
): Promise<ProvisioningState | null> {
  const redis = useRedis()
  const data = await redis.hgetall<ProvisioningState>(`provision:${sessionId}`)
  return data && Object.keys(data).length > 0 ? data : null
}
```

### Pattern 3: Idempotency Guard with Redis SETNX
**What:** Use Redis `set` with `nx: true` (SET if Not eXists) to prevent duplicate provisioning for the same Stripe session.
**When to use:** At the start of the webhook handler's provisioning logic.
**Example:**
```typescript
// In the webhook handler (stripe-webhook.post.ts)
export async function triggerProvisioning(session: {
  id: string
  metadata: { subdomain: string; communityName: string; email: string }
  customer_email: string
}): Promise<{ alreadyProcessing: boolean }> {
  const redis = useRedis()
  const lockKey = `provision:${session.id}:lock`

  // Atomic set-if-not-exists with 1-hour TTL
  const acquired = await redis.set(lockKey, 'locked', { nx: true, ex: 3600 })
  if (!acquired) {
    // Another invocation already processing this session
    return { alreadyProcessing: true }
  }

  // Set initial state
  await setProvisioningState(session.id, {
    status: 'triggered',
    subdomain: session.metadata.subdomain,
    email: session.customer_email || session.metadata.email,
    siteName: session.metadata.communityName,
    stripeSessionId: session.id,
    startedAt: new Date().toISOString()
  })

  // Dispatch GitHub Actions workflow
  const { runId, runUrl } = await dispatchProvisioningWorkflow({
    subdomain: session.metadata.subdomain,
    siteName: session.metadata.communityName,
    email: session.customer_email || session.metadata.email,
    stripeSessionId: session.id
  })

  // Update state with run details
  await setProvisioningState(session.id, {
    status: 'provisioning',
    githubRunId: String(runId),
    githubRunUrl: runUrl
  })

  return { alreadyProcessing: false }
}
```

### Pattern 4: Post-Provisioning User Creation (GitHub Actions)
**What:** After the Drupal site is healthy, SSH into the Coolify server to create the admin user, assign the sitemanager role, and generate a one-time login URL.
**When to use:** As a post-provisioning step in the GitHub Actions workflow, after the health check succeeds.
**Example (GitHub Actions YAML):**
```yaml
- name: Create organizer admin account
  if: env.HTTP_CODE == '200'
  uses: appleboy/ssh-action@v1
  with:
    host: localnodes.xyz
    username: root
    key: ${{ secrets.SSH_PRIVATE_KEY }}
    script: |
      # Find the opensocial container for this instance
      CONTAINER=$(docker ps --filter "name=${{ inputs.subdomain }}" --filter "name=opensocial" --format '{{.Names}}' | head -1)
      if [ -z "$CONTAINER" ]; then
        echo "ERROR: Could not find opensocial container for ${{ inputs.subdomain }}"
        exit 1
      fi

      DRUSH="docker exec $CONTAINER /var/www/html/vendor/bin/drush -r /var/www/html/html --uri=https://${{ env.FQDN }}"

      # Generate secure password
      PASSWORD=$(openssl rand -hex 16)

      # Create user with community name as username, organizer's email
      USERNAME="${{ inputs.site_name }} Admin"
      $DRUSH user:create "$USERNAME" \
        --mail="${{ inputs.email }}" \
        --password="$PASSWORD"

      # Assign sitemanager role (Open Social's admin role)
      $DRUSH user:role:add sitemanager "$USERNAME"

      # Generate one-time login URL
      LOGIN_URL=$($DRUSH uli --name="$USERNAME" --uri=https://${{ env.FQDN }} 2>/dev/null)

      echo "LOGIN_URL=$LOGIN_URL" >> "$GITHUB_ENV"
      echo "Organizer account created: ${{ inputs.email }}"
```

### Pattern 5: Status Polling Endpoint
**What:** A server route that returns the current provisioning state from Redis for frontend polling.
**When to use:** Called by the success page every few seconds during provisioning.
**Example:**
```typescript
// server/api/provision-status.get.ts
import * as v from 'valibot'

const querySchema = v.object({
  session_id: v.pipe(v.string(), v.minLength(1))
})

export default defineEventHandler(async (event) => {
  const query = await getValidatedQuery(event, input => v.parse(querySchema, input))
  const state = await getProvisioningState(query.session_id)

  if (!state) {
    return { status: 'unknown' }
  }

  return {
    status: state.status,
    siteUrl: state.siteUrl || null,
    loginUrl: state.loginUrl || null,
    error: state.error || null,
    startedAt: state.startedAt
  }
})
```

### Pattern 6: Welcome Email via Resend
**What:** Send the welcome email from the GitHub Actions workflow using a direct Resend API call (curl), since the workflow runs in Ubuntu, not in the Nuxt app.
**When to use:** After user creation and login URL generation succeed.
**Example (GitHub Actions YAML):**
```yaml
- name: Send welcome email
  if: env.LOGIN_URL != ''
  run: |
    curl -s -X POST "https://api.resend.com/emails" \
      -H "Authorization: Bearer ${{ secrets.RESEND_API_KEY }}" \
      -H "Content-Type: application/json" \
      -d "{
        \"from\": \"LocalNodes <hello@localnodes.xyz>\",
        \"to\": [\"${{ inputs.email }}\"],
        \"subject\": \"Your knowledge garden is ready!\",
        \"html\": \"<h1>Welcome to LocalNodes!</h1><p>Your knowledge garden at <a href='https://${{ env.FQDN }}'>https://${{ env.FQDN }}</a> is ready.</p><p><a href='${{ env.LOGIN_URL }}'>Click here to set your password and log in</a></p><p>This link is single-use and will expire after first use.</p>\"
      }"
```

### Pattern 7: GitHub Actions Callback to Update Redis
**What:** The GitHub Actions workflow calls back to the Nitro server to update provisioning state in Redis as it progresses through stages.
**When to use:** At each milestone in the provisioning workflow (deploying, installing, user creation, email sent, complete).
**Example (GitHub Actions YAML):**
```yaml
- name: Update provisioning status
  run: |
    curl -s -X POST "${{ inputs.callback_url }}/api/provision-callback" \
      -H "Content-Type: application/json" \
      -H "Authorization: Bearer ${{ secrets.PROVISION_CALLBACK_SECRET }}" \
      -d "{
        \"session_id\": \"${{ inputs.stripe_session_id }}\",
        \"status\": \"installing\",
        \"site_url\": \"https://${{ env.FQDN }}\"
      }"
```

### Anti-Patterns to Avoid
- **Waiting for provisioning inside the webhook handler:** Stripe has a 20-second timeout for webhook responses. The ~4 minute provisioning must be fully async. Return `{ received: true }` immediately after dispatching the workflow.
- **Using Coolify API execute endpoint:** It returns 404 (commented out in beta.463). Use SSH + docker exec instead.
- **Storing provisioning state in Vercel serverless memory:** Serverless functions are stateless. State is lost between invocations. Use Upstash Redis.
- **Hardcoding the admin password:** Generate a random password per instance with `openssl rand -hex 16`. The user never sees it -- they use the one-time login link to set their own.
- **Creating users before the site is healthy:** The drush commands will fail if the Drupal site hasn't finished installing. Wait for the HTTP 200 health check first.
- **Using `docker exec -it` in CI:** GitHub Actions is non-interactive. Use `docker exec` (no `-it` flag) or the ssh-action which handles this.
- **Skipping the subdomain availability check:** Even though Phase 13 checked availability, there's a race window between payment and provisioning. The existing workflow already checks availability.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Workflow dispatch + run tracking | Custom GitHub API wrapper with polling for run ID | `return_run_details: true` parameter | Native since Feb 2026. Returns run ID directly. No more race conditions. |
| Idempotent webhook processing | Custom dedup logic with timestamps | Redis `set` with `nx: true` (SETNX) | Atomic, race-condition-free. If key exists, another handler is already processing. |
| Provisioning state storage | Database table, file storage, in-memory | Upstash Redis hash with TTL | HTTP-based (works in serverless), auto-expiring, structured via hset/hgetall. |
| Secure password generation | Custom random string functions | `openssl rand -hex 16` in workflow | Cryptographically secure, available everywhere, no dependencies. |
| One-time login URL | Custom token + email verification | `drush uli --name=USER --uri=DOMAIN` | Drupal's native mechanism. Handles token generation, expiration, and single-use enforcement. |
| Welcome email delivery | SMTP library, custom mail server | Resend API (curl in CI, SDK in Nuxt) | Handles deliverability, SPF/DKIM (configured in Phase 12), retries, bounce processing. |
| SSH command execution in CI | Custom SSH scripts with key management | `appleboy/ssh-action@v1` | Battle-tested GitHub Action. Handles key injection, connection management, error codes. |

**Key insight:** The provisioning pipeline orchestrates existing systems (GitHub Actions, Coolify, Drupal/drush, Resend) rather than building new infrastructure. Every step uses an established tool's native capability. The only new code is the glue logic that connects them.

## Common Pitfalls

### Pitfall 1: Stripe Webhook Timeout During Provisioning
**What goes wrong:** Webhook handler tries to wait for provisioning before responding, exceeding Stripe's 20-second timeout. Stripe retries the webhook, triggering duplicate provisioning.
**Why it happens:** Provisioning takes ~4 minutes. Stripe expects a response within 20 seconds.
**How to avoid:** Return `{ received: true }` immediately after dispatching the GitHub Actions workflow. All provisioning is async.
**Warning signs:** Stripe Dashboard shows webhook delivery failures with timeout errors.

### Pitfall 2: Duplicate Provisioning from Webhook Retries
**What goes wrong:** Stripe retries failed webhook deliveries for up to 3 days. Each retry triggers a new provisioning, creating duplicate instances.
**Why it happens:** No deduplication guard. Stripe uses at-least-once delivery.
**How to avoid:** Use Redis SETNX (`set` with `nx: true`) keyed by Stripe session ID. If the key already exists, skip provisioning and return success. The GitHub Actions workflow also checks subdomain availability as a second guard.
**Warning signs:** Multiple Coolify apps for the same subdomain, multiple workflow runs for the same session.

### Pitfall 3: Coolify Execute API Returns 404
**What goes wrong:** Attempting to use `POST /api/v1/applications/{uuid}/execute` to run drush commands fails with 404.
**Why it happens:** The execute command endpoint is commented out in Coolify's routing (beta.463). See [GitHub Issue #4544](https://github.com/coollabsio/coolify/issues/4544) and [Issue #5387](https://github.com/coollabsio/coolify/issues/5387).
**How to avoid:** Use SSH + `docker exec` via `appleboy/ssh-action`. The Coolify server is accessible at `localnodes.xyz` as `root` on port 22.
**Warning signs:** 404 responses when calling the execute endpoint.

### Pitfall 4: Container Name Mismatch in Docker Exec
**What goes wrong:** `docker exec` fails because the container name doesn't match the expected pattern.
**Why it happens:** Coolify generates container names dynamically based on the app UUID and service name. The exact format may change across versions.
**How to avoid:** Use `docker ps --filter "name=SUBDOMAIN" --filter "name=opensocial" --format '{{.Names}}' | head -1` to dynamically find the correct container name.
**Warning signs:** "Error: No such container" in SSH action output.

### Pitfall 5: Drush Login URL Without --uri Returns Localhost
**What goes wrong:** `drush uli` generates a URL like `http://default/user/reset/...` instead of `https://subdomain.localnodes.xyz/user/reset/...`.
**Why it happens:** Drush doesn't know the site's public URL unless told via `--uri`.
**How to avoid:** Always pass `--uri=https://FQDN` to drush commands. The existing entrypoint.sh already does this pattern: `DRUSH="/var/www/html/vendor/bin/drush -r /var/www/html/html --uri=$SITE_URI"`.
**Warning signs:** Login URLs containing "default" or "localhost" instead of the actual domain.

### Pitfall 6: Race Between Site Health and User Creation
**What goes wrong:** Drush user:create fails because Drupal hasn't finished installing when the command runs.
**Why it happens:** The health check polls for HTTP 200 on `/user/login`, but drush commands need a fully bootstrapped Drupal. There's a gap between container start and site install completion.
**How to avoid:** The existing workflow already waits up to 10 minutes for HTTP 200 on `/user/login`. User creation should only run AFTER this health check succeeds. Add an explicit `if: env.HTTP_CODE == '200'` condition.
**Warning signs:** Drush errors about database not being initialized or site not installed.

### Pitfall 7: GitHub Token Scope Insufficient for Workflow Dispatch
**What goes wrong:** GitHub API returns 403 when trying to dispatch the workflow.
**Why it happens:** The PAT doesn't have the `actions:write` permission, or it's a classic token without the `repo` scope.
**How to avoid:** Use a fine-grained PAT with `actions:write` + `metadata:read` permissions on the `LocalNodes/os-knowledge-garden` repository. Store as `GITHUB_PAT` in Vercel env vars.
**Warning signs:** 403 Forbidden responses from the GitHub API.

### Pitfall 8: SSH Key Not Available to GitHub Actions
**What goes wrong:** The `appleboy/ssh-action` step fails to connect to the Coolify server.
**Why it happens:** The SSH private key is not stored as a GitHub secret, or the server doesn't have the corresponding public key in `authorized_keys`.
**How to avoid:** Generate an SSH key pair, add the public key to the Coolify server's `~/.ssh/authorized_keys`, and store the private key as the `SSH_PRIVATE_KEY` GitHub secret on `LocalNodes/os-knowledge-garden`.
**Warning signs:** "Permission denied (publickey)" errors in the SSH action.

## Code Examples

### Modified Webhook Handler (stripe-webhook.post.ts)
```typescript
// server/api/stripe-webhook.post.ts — Phase 15 modifications
import type Stripe from 'stripe'

export default defineEventHandler(async (event) => {
  const signature = getHeader(event, 'stripe-signature')
  if (!signature) {
    throw createError({ status: 400, statusText: 'Missing Stripe signature' })
  }

  const rawBody = await readRawBody(event)
  if (!rawBody) {
    throw createError({ status: 400, statusText: 'Missing request body' })
  }

  const config = useRuntimeConfig()
  const stripe = useStripe()

  let stripeEvent: Stripe.Event
  try {
    stripeEvent = stripe.webhooks.constructEvent(rawBody, signature, config.stripeWebhookSecret)
  } catch (err) {
    console.error('Webhook signature verification failed:', (err as Error).message)
    throw createError({ status: 400, statusText: 'Invalid webhook signature' })
  }

  switch (stripeEvent.type) {
    case 'checkout.session.completed': {
      const session = stripeEvent.data.object as Stripe.Checkout.Session
      console.log('Checkout session completed:', {
        sessionId: session.id,
        customerEmail: session.customer_email,
        metadata: session.metadata,
        subscriptionId: session.subscription
      })

      // Phase 15: trigger provisioning (async, non-blocking)
      const { alreadyProcessing } = await triggerProvisioning({
        id: session.id,
        metadata: session.metadata as { subdomain: string; communityName: string; email: string },
        customer_email: session.customer_email || ''
      })

      if (alreadyProcessing) {
        console.log('Provisioning already in progress for session:', session.id)
      }

      break
    }
    default:
      console.log(`Unhandled event type: ${stripeEvent.type}`)
  }

  return { received: true }
})
```

### Resend Email Singleton
```typescript
// server/utils/resend.ts
import { Resend } from 'resend'

let _resend: Resend | null = null

export function useResend(): Resend {
  if (!_resend) {
    const config = useRuntimeConfig()
    if (!config.resendApiKey) {
      throw new Error('NUXT_RESEND_API_KEY environment variable is not set')
    }
    _resend = new Resend(config.resendApiKey)
  }
  return _resend
}
```

### Provisioning Callback Endpoint
```typescript
// server/api/provision-callback.post.ts
import * as v from 'valibot'

const bodySchema = v.object({
  session_id: v.pipe(v.string(), v.minLength(1)),
  status: v.picklist([
    'triggered', 'provisioning', 'installing',
    'creating_user', 'sending_email', 'complete', 'failed'
  ]),
  site_url: v.optional(v.string()),
  login_url: v.optional(v.string()),
  error: v.optional(v.string())
})

export default defineEventHandler(async (event) => {
  // Verify callback secret
  const config = useRuntimeConfig()
  const authHeader = getHeader(event, 'authorization')
  if (authHeader !== `Bearer ${config.provisionCallbackSecret}`) {
    throw createError({ status: 401, statusText: 'Unauthorized' })
  }

  const body = await readValidatedBody(event, input => v.parse(bodySchema, input))

  await setProvisioningState(body.session_id, {
    status: body.status,
    ...(body.site_url && { siteUrl: body.site_url }),
    ...(body.login_url && { loginUrl: body.login_url }),
    ...(body.error && { error: body.error })
  })

  return { updated: true }
})
```

### Runtime Config Additions
```typescript
// nuxt.config.ts additions for Phase 15
runtimeConfig: {
  // Existing (Phase 13-14)
  coolifyApiUrl: 'https://coolify.localnodes.xyz/api/v1',
  coolifyApiToken: '',
  stripeSecretKey: '',
  stripeWebhookSecret: '',
  stripePriceId: '',

  // NEW: Phase 15
  upstashRedisUrl: '',        // NUXT_UPSTASH_REDIS_URL
  upstashRedisToken: '',      // NUXT_UPSTASH_REDIS_TOKEN
  githubToken: '',            // NUXT_GITHUB_TOKEN (fine-grained PAT)
  githubRepo: 'LocalNodes/os-knowledge-garden',  // NUXT_GITHUB_REPO
  resendApiKey: '',           // NUXT_RESEND_API_KEY
  provisionCallbackSecret: '', // NUXT_PROVISION_CALLBACK_SECRET
}
```

### GitHub Actions Workflow Input Extensions
```yaml
# provision-instance.yml — new inputs for Phase 15
on:
  workflow_dispatch:
    inputs:
      subdomain:
        description: 'Subdomain (e.g., "portland")'
        required: true
        type: string
      site_name:
        description: 'Site display name'
        required: true
        type: string
      demo_module:
        description: 'Demo content module'
        required: true
        type: choice
        options:
          - localnodes_demo
          - boulder_demo
          - portland_demo
          - none
        default: localnodes_demo
      # NEW inputs for Phase 15
      email:
        description: 'Organizer email address'
        required: false
        type: string
      stripe_session_id:
        description: 'Stripe Checkout Session ID for state tracking'
        required: false
        type: string
      callback_url:
        description: 'URL to POST status updates back to the onboarding app'
        required: false
        type: string
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| workflow_dispatch returns 204 (no run ID) | `return_run_details: true` returns run ID in 200 response | Feb 19, 2026 | No more race conditions finding the triggered run |
| Custom GitHub Action for run ID discovery | Native API parameter | Feb 19, 2026 | Remove third-party action dependency |
| Max 10 workflow_dispatch inputs | Max 25 inputs | Dec 2025 | Enough room for all provisioning parameters |
| Coolify execute API endpoint | SSH + docker exec | beta.376+ (endpoint commented out) | Must use SSH for container commands |

**Deprecated/outdated:**
- `workflow_dispatch` marketplace actions for run ID discovery (e.g., `mathze/workflow-dispatch-action`, `lasith-kg/dispatch-workflow`): No longer needed with native `return_run_details` parameter.
- Coolify `POST /api/v1/applications/{uuid}/execute`: Commented out since at least beta.376. Do not rely on it.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Vitest 3.2.x (already configured from Phase 13) |
| Config file | `vitest.config.ts` (exists) |
| Quick run command | `npx vitest run tests/unit/provision-trigger.test.ts` |
| Full suite command | `npx vitest run` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PROV-01 | Webhook triggers GitHub Actions dispatch with correct inputs | unit | `npx vitest run tests/unit/provision-trigger.test.ts` | No -- Wave 0 |
| PROV-01 | Redis state set to 'triggered' then 'provisioning' after dispatch | unit | `npx vitest run tests/unit/provision-trigger.test.ts` | No -- Wave 0 |
| PROV-02 | User creation drush commands are correct (user:create + user:role:add) | manual-only | Manual: verify drush commands in workflow YAML | N/A |
| PROV-03 | Login URL generated via drush uli with correct --uri | manual-only | Manual: verify drush uli command in workflow YAML | N/A |
| PROV-03 | Welcome email sent via Resend with login URL | manual-only | Manual: trigger provisioning, verify email received | N/A |
| PROV-04 | Idempotency: duplicate session IDs are rejected | unit | `npx vitest run tests/unit/idempotency.test.ts` | No -- Wave 0 |
| PROV-04 | Idempotency: subdomain availability re-checked in workflow | manual-only | Manual: verify step in workflow YAML | N/A |

### Sampling Rate
- **Per task commit:** `npx vitest run tests/unit/provision-trigger.test.ts` (fastest)
- **Per wave merge:** `npx vitest run`
- **Phase gate:** Full suite green + manual end-to-end provisioning test

### Wave 0 Gaps
- [ ] `tests/unit/provision-trigger.test.ts` -- covers PROV-01 (dispatch logic, Redis state management)
- [ ] `tests/unit/idempotency.test.ts` -- covers PROV-04 (SETNX guard, duplicate rejection)
- [ ] `tests/unit/provision-status.test.ts` -- covers status polling endpoint

## Open Questions

1. **Drush username for organizer account** — RESOLVED
   - Username format: `[Community Name] Admin` (e.g., "Cascadia Admin")
   - Email set separately via `--mail` flag
   - Drupal allows spaces, periods, hyphens, apostrophes, underscores, and @ in usernames

2. **Open Social admin role machine name**
   - What we know: Open Social has a role hierarchy. The standard Drupal `administrator` role may not exist; Open Social uses `sitemanager` as the top-level site admin role.
   - What's unclear: Need to verify the exact machine name of the role to assign.
   - Recommendation: Use `sitemanager` -- this is Open Social's equivalent of admin. Verify by checking `config/sync/user.role.sitemanager.yml` in the Drupal codebase.

3. **SSH key for GitHub Actions to Coolify server**
   - What we know: The Coolify server is at `localnodes.xyz`, SSH as `root` on port 22. Currently accessible.
   - What's unclear: Whether an SSH key pair for GitHub Actions already exists.
   - Recommendation: Document as a `user_setup` requirement in the plan. Generate a new ed25519 key pair, add the public key to the server, store the private key as `SSH_PRIVATE_KEY` GitHub secret.

4. **Callback URL for status updates from GitHub Actions**
   - What we know: The onboarding app on Vercel needs to receive status updates from GitHub Actions.
   - What's unclear: Whether the Vercel URL is stable and predictable, and whether Deployment Protection might block callbacks.
   - Recommendation: Use the production URL `https://localnodes.xyz` as the callback base. Phase 16 (Status & Notification) will consume these status updates for the progress UI. For Phase 15, the callback mechanism is the infrastructure; Phase 16 adds the frontend.

5. **Resend "from" address domain verification**
   - What we know: Phase 12 configured SPF/DKIM/DMARC DNS records for `localnodes.xyz`.
   - What's unclear: Whether Resend domain verification is complete and the domain is ready to send.
   - Recommendation: Verify in the Resend Dashboard that `localnodes.xyz` shows as verified before sending production emails. Add as a `user_setup` step in the plan.

## Sources

### Primary (HIGH confidence)
- [GitHub Changelog - Workflow dispatch API now returns run IDs](https://github.blog/changelog/2026-02-19-workflow-dispatch-api-now-returns-run-ids/) - `return_run_details` parameter, response format `{ workflow_run_id, run_url, html_url }`
- [GitHub REST API - Workflows](https://docs.github.com/en/rest/actions/workflows) - dispatch endpoint URL, authentication, request body format
- [GitHub REST API - Workflow Runs](https://docs.github.com/en/rest/actions/workflow-runs) - `GET /repos/{owner}/{repo}/actions/runs/{run_id}` for status polling
- [Upstash Redis Documentation](https://upstash.com/docs/redis/sdks/ts/commands/generic/expire) - EXPIRE, HSET, HGETALL, SET with NX
- [Drush - user:create](https://www.drush.org/11.x/commands/user_create/) - `--mail`, `--password`, `--roles` flags
- [Drush - user:login (uli)](https://www.drush.org/11.x/commands/user_login/) - `--name`, `--uri` flags for one-time login URL
- [Drush - user:role:add](https://www.drush.org/12.x/commands/user_role_add/) - role assignment command
- [Resend - Send with Node.js](https://resend.com/docs/send-with-nodejs) - SDK initialization and emails.send API
- Existing `provision-instance.yml` workflow at `/Users/proofoftom/Code/os-decoupled/fresh3/.github/workflows/provision-instance.yml` - proven provisioning pipeline

### Secondary (MEDIUM confidence)
- [Coolify Issue #4544](https://github.com/coollabsio/coolify/issues/4544) - execute endpoint commented out
- [Coolify Issue #5387](https://github.com/coollabsio/coolify/issues/5387) - execute endpoint returns 404
- [@upstash/redis npm](https://www.npmjs.com/package/@upstash/redis) - v1.36.x, HTTP-based, serverless-compatible
- [resend npm](https://www.npmjs.com/package/resend) - v6.9.x, latest stable

### Tertiary (LOW confidence)
- Open Social `sitemanager` role name (from memory, needs verification against config/sync)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - @upstash/redis and resend are well-documented, versions verified via npm. GitHub API return_run_details verified via official changelog.
- Architecture: HIGH - Webhook-to-dispatch pattern is standard. SSH + docker exec is proven (already used for debugging). Redis state tracking with hashes is documented.
- Pitfalls: HIGH - Coolify execute API 404 confirmed via multiple GitHub issues. Stripe webhook timeout well-documented. SSH + drush patterns used in existing entrypoint.sh.
- Testing: MEDIUM - Unit testing pure provisioning logic (trigger, idempotency) is straightforward. E2E testing requires real infrastructure (Stripe, GitHub Actions, Coolify), so manual verification is needed.

**Research date:** 2026-03-04
**Valid until:** 2026-04-04 (GitHub Actions API is stable, Upstash/Resend are mature)
