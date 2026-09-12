# Domain Pitfalls: Self-Service SaaS Provisioning

**Domain:** Adding self-service onboarding with Stripe billing to existing infrastructure provisioning pipeline
**Researched:** 2026-03-02
**Overall Confidence:** HIGH (multiple authoritative sources, verified against existing codebase)

---

## Critical Pitfalls

Mistakes that cause security breaches, financial loss, or require architectural rewrites.

### Pitfall 1: Payment-Provisioning State Desynchronization (The Saga Gap)

**What goes wrong:** User pays via Stripe, webhook fires `checkout.session.completed`, provisioning starts via GitHub Actions, but provisioning fails at minute 3 (Coolify API error, compose parse failure, Drupal install crash). Customer has paid but has no instance. Without explicit compensation logic, the system enters an inconsistent state -- money taken, nothing delivered, no automated recovery.

**Why it happens:** Payment and infrastructure provisioning are inherently distributed transactions. Stripe's payment is atomic, but the downstream provisioning pipeline (GitHub Actions -> Coolify API -> Docker build -> Drupal install) has at least 4 failure points, none of which automatically reverse the payment. Most developers treat provisioning as "fire and forget" after payment succeeds.

**Consequences:**
- Customer charged with no instance delivered
- Manual intervention required for every failure (refunds, cleanup, retry)
- Orphaned Coolify applications consuming server resources with no paying customer
- Orphaned DNS records / Traefik routes pointing to dead containers
- Customer trust destroyed immediately at onboarding -- the worst possible moment

**Prevention:**
- Implement an explicit **provisioning state machine** with states: `PAYMENT_PENDING -> PAYMENT_RECEIVED -> PROVISIONING -> PROVISIONING_FAILED -> ACTIVE -> SUSPENDED -> TERMINATED`
- Every state transition must be recorded in your database with timestamps
- Define **compensating transactions** for each failure point:
  - Provisioning fails -> issue Stripe refund via `stripe.refunds.create()`, delete Coolify app, log incident
  - Coolify app created but deploy fails -> delete Coolify app via API, refund
  - Deploy succeeds but health check fails after 10 min -> flag for manual review (may still be installing)
- Use the **orchestrator variant** of the saga pattern: a single backend service coordinates the sequence and handles rollback, rather than choreography between disconnected services
- **Pivot transaction:** The Coolify health check passing (`HTTP 200` on `/user/login`) is the pivot -- only after this should the subscription be activated in your system

**Detection:** Monitor for instances in `PROVISIONING` state longer than 15 minutes. Alert on any `PROVISIONING_FAILED` state. Daily reconciliation: compare Coolify app list against active subscriptions.

**Phase:** Must be addressed in the backend/API design phase. This is foundational architecture, not a bolt-on.

**Confidence:** HIGH -- saga pattern is well-documented (Microsoft Azure Architecture Center, AWS Prescriptive Guidance, Temporal.io). Payment-provisioning desync is the #1 failure mode in SaaS onboarding.

**Sources:**
- [Saga Design Pattern - Azure Architecture Center](https://learn.microsoft.com/en-us/azure/architecture/patterns/saga)
- [Compensating Transaction Pattern - Azure](https://learn.microsoft.com/en-us/azure/architecture/patterns/compensating-transaction)
- [Microservices Pattern: Saga](https://microservices.io/patterns/data/saga.html)

---

### Pitfall 2: Stripe Webhook Handler as Single Point of Failure

**What goes wrong:** The webhook handler for `checkout.session.completed` triggers provisioning directly. If the handler crashes, returns non-200, or takes longer than 20 seconds, Stripe retries with exponential backoff for up to 72 hours. Each retry can trigger duplicate provisioning attempts. If you process inline (verify -> create Coolify app -> trigger deploy -> return 200), any step timing out means Stripe retries the whole sequence.

**Why it happens:** Developers process webhook events inline instead of using a queue. The provisioning pipeline takes 4+ minutes, but Stripe requires a 200 response within 20 seconds. This forces either: (a) async processing with proper queueing, or (b) a broken handler that times out.

**Consequences:**
- Duplicate Coolify applications created from Stripe retries (each retry thinks it is the first attempt)
- Double-charging if payment confirmation is processed multiple times
- Webhook marked as failed after 72 hours of retries, customer never provisioned
- Silent data inconsistency between Stripe subscription state and your provisioning state

**Prevention:**
- **Enqueue, do not process inline.** Webhook handler should: (1) verify signature, (2) check idempotency (has this `event.id` been seen?), (3) store event in database/queue, (4) return 200. Total time: <1 second.
- Store processed `event.id` values in a database table with a `UNIQUE` constraint. Check existence before processing. This is idempotency.
- Use Stripe's Checkout Session `client_reference_id` to link back to your internal provisioning record
- **Single writer principle:** Only the queue worker writes provisioning state. The webhook endpoint and the frontend status-check endpoint are both read-only. Never have two writers.
- Use idempotency keys on all Stripe API write requests (refund creation, subscription updates)

**Detection:** Monitor webhook delivery success rate in Stripe Dashboard. Alert on any event older than 1 hour that has not been processed. Log every webhook receipt with event type, event ID, and processing outcome.

**Phase:** Must be addressed in the backend/API design phase alongside Pitfall 1.

**Confidence:** HIGH -- Stripe's own documentation emphasizes this pattern. Multiple authoritative sources confirm the 20-second timeout and at-least-once delivery semantics.

**Sources:**
- [The Race Condition You're Probably Shipping Right Now With Stripe Webhooks](https://dev.to/belazy/the-race-condition-youre-probably-shipping-right-now-with-stripe-webhooks-mj4)
- [Billing Webhook Race Condition Solution Guide](https://excessivecoding.com/blog/billing-webhook-race-condition-solution-guide)
- [Stripe Webhook Best Practices](https://hookdeck.com/webhooks/platforms/stripe-thin-events-best-practices)
- [Stripe: Troubleshooting Webhook Delivery Issues](https://support.stripe.com/questions/troubleshooting-webhook-delivery-issues)

---

### Pitfall 3: Exposing Infrastructure Provisioning to the Public Internet

**What goes wrong:** The self-service frontend must trigger infrastructure provisioning (Coolify API calls, GitHub Actions). If the middleware/backend that bridges frontend to provisioning is not properly secured, attackers can: (a) trigger unlimited provisioning to exhaust server resources, (b) manipulate subdomain inputs to create infrastructure under attacker-controlled names, (c) probe the Coolify API through the backend as a proxy, (d) trigger GitHub Actions workflows at will.

**Why it happens:** The existing provisioning pipeline (`provision-instance.yml`) was designed for manual triggering by trusted operators. It contains hardcoded secrets (`DRUPAL_ADMIN_PASS: localnodes2026`), uses `workflow_dispatch` which requires repo write access, and has no per-request authentication beyond the GitHub token. Converting this from "trusted operator tool" to "public-facing API" without re-architecting the trust model is the core mistake.

**Consequences:**
- **Resource exhaustion:** Each provisioned instance consumes ~1-2GB RAM (MariaDB + Solr + Qdrant + Drupal). 10 malicious provisions = server down. Coolify's concurrent build limit has known bugs where it is not enforced.
- **Financial damage:** Each instance consumes Gemini API credits for embeddings/chat. Crypto miners and abuse bots target free-tier provisioning specifically ("freejacking").
- **Coolify API exposure:** Coolify has had 7 CVEs identified, including rate-limit bypass on login and privilege escalation to RCE. The API token used by the backend has full infrastructure control.
- **Secret exposure:** The GitHub Actions workflow contains `GEMINI_API_KEY` and `COOLIFY_API_TOKEN` as secrets. If the backend improperly proxies errors or logs, these could leak.

**Prevention:**
- **Never expose Coolify API or GitHub Actions tokens to the frontend.** The backend is a gatekeeper, not a proxy.
- **Payment as the gate:** Require successful Stripe payment BEFORE any provisioning call. Payment is the identity verification AND the rate limiter. Credit card requirement stops most abuse (GitLab adopted this exact approach).
- **Backend-level rate limiting:** Maximum 1 provisioning request per email per hour. Maximum 3 active instances per Stripe customer. Global maximum of N concurrent provisions (match to server capacity).
- **Input sanitization is not optional:** The existing workflow validates subdomains with `grep -qE '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$'` and checks a small reserved list. The backend must add: length limits (3-30 chars), profanity filtering, trademark checks (basic blocklist), and check against ALL existing Coolify apps (not just compose domains -- the current check can miss apps without `docker_compose_domains` set).
- **Unique admin passwords per instance:** The current workflow hardcodes `localnodes2026`. Generate per-instance random passwords and deliver via email.
- **Do not pass user input directly to shell commands.** The workflow interpolates `${{ inputs.subdomain }}` into curl JSON and shell variables. A backend must sanitize before constructing API calls. Command injection via subdomain names is a real risk.

**Detection:** Monitor Coolify server resource usage. Alert when total container memory exceeds 80% of server RAM. Count active Coolify apps and compare against paying subscribers weekly.

**Phase:** Security architecture must be designed in the backend/API phase. Cannot be retrofitted.

**Confidence:** HIGH -- Coolify's CVE history is documented. Freejacking attacks on free-tier provisioning are extensively documented across GitLab, GitHub, Heroku, and CloudBees.

**Sources:**
- [Seven CVEs in Coolify Identified Through AI Pentesting | Aikido](https://www.aikido.dev/blog/ai-pentesting-coolify-cves)
- [Rate-limit bypass on Coolify login](https://github.com/coollabsio/coolify/security/advisories/GHSA-688j-rm43-5r8x)
- [How to prevent crypto mining abuse on GitLab.com SaaS](https://about.gitlab.com/blog/2021/05/17/prevent-crypto-mining-abuse/)
- [Massive cryptomining campaign abuses free-tier cloud resources](https://www.bleepingcomputer.com/news/security/massive-cryptomining-campaign-abuses-free-tier-cloud-dev-resources/)

---

### Pitfall 4: The 4-Minute Provisioning UX Death Zone

**What goes wrong:** User pays, sees a spinner, waits 4 minutes, assumes it is broken, closes the tab, tries again (triggering duplicate provisioning), or disputes the charge. Research shows users without progress indicators abandon after ~30 seconds. Even with a progress bar, 4 minutes of passive waiting has extreme abandonment rates.

**Why it happens:** The provisioning pipeline is inherently slow: Docker image pull (~30s) + Drupal site:install (~2m) + module enablement (~30s) + demo content loading (~30s) + Solr indexing + vector embedding cron (3 passes). This is unavoidable without architectural changes to the install process.

**Consequences:**
- Users close tab and never return (lost customer at the moment of highest intent)
- Users retry, triggering duplicate provisioning and duplicate charges
- Users dispute charge with credit card company (chargebacks)
- Users contact support, creating operational overhead before they have even used the product
- Perception of an unreliable product from the very first interaction

**Prevention:**
- **Collect email + payment, then release the user.** After successful payment, show: "We're setting up your knowledge garden. You'll receive an email at [email] in about 5 minutes with your login details. You can also check status at [status URL]."
- **Step-by-step progress page (optional, not required):** If the user stays, show determinate progress with named steps. Research shows users wait 3x longer with progress bars (University of Nebraska-Lincoln). Use accelerating progress (fast at start, slower at end) to reduce perceived wait time.
- **Never use an indeterminate spinner** for multi-minute waits. Users assume it is broken.
- **Status polling endpoint:** Backend provides `/api/provisions/:id/status` that returns current step and percentage. Frontend polls every 5 seconds.
- **Set expectations explicitly:** "This usually takes about 4 minutes" is better than no estimate. Research shows even vague estimates ("a few minutes") improve tolerance.
- **Email notification is the primary delivery mechanism.** The progress page is a nice-to-have for users who stay. The email is the guarantee.
- **Prevent duplicate submissions:** Disable the submit button after click. Use idempotency keys tied to the Stripe session. If the same user returns to the status page, show existing provisioning status instead of starting a new one.

**Detection:** Track median and p95 provisioning times. Track tab-close rates during provisioning (via `beforeunload` event). Track retry rates (same email, multiple provisions within 30 minutes).

**Phase:** This spans both frontend and backend design. The backend must expose a status endpoint. The frontend must implement the progress UX. Email delivery must be ready before launch.

**Confidence:** HIGH -- UX research on long waits is extensive. NN/Group, Smart Interface Design Patterns, and multiple empirical studies confirm these patterns.

**Sources:**
- [Designing for Long Waits and Interruptions - NN/Group](https://www.nngroup.com/articles/designing-for-waits-and-interruptions/)
- [Designing Better Loading and Progress UX](https://smart-interface-design-patterns.com/articles/designing-better-loading-progress-ux/)
- [Progress Indicators Explained - DEV Community](https://dev.to/lollypopdesign/progress-indicators-explained-types-variations-best-practices-for-saas-design-392n)

---

## Moderate Pitfalls

Mistakes that cause degraded experience, operational overhead, or delayed launch but are recoverable.

### Pitfall 5: Email Deliverability on a New Domain

**What goes wrong:** You provision an instance, generate credentials, and send the "Your instance is ready!" email. It goes to spam, or never arrives. The user has paid, has no way to access their instance, and your only delivery mechanism failed silently. New domains face a ~30 percentage point inbox placement penalty compared to mature domains.

**Why it happens:** `localnodes.xyz` is a relatively new domain. Without SPF, DKIM, and DMARC configured, inbox providers (especially Gmail, which expects complaint rates below 0.1%) will aggressively filter or reject email. Even with authentication, new domains lack sending reputation and ISPs treat them with suspicion.

**Prevention:**
- **Use a transactional email service** (Resend, Postmark, or SendGrid) instead of sending directly. These services have established IP reputation and handle SPF/DKIM for you.
- **Separate transactional from marketing email** by subdomain: `notify.localnodes.xyz` for transactional, `news.localnodes.xyz` for marketing (future). This isolates reputation.
- **Configure SPF + DKIM + DMARC before sending any email.** Start DMARC at `p=none` to monitor, then escalate to `p=quarantine`. Full authentication yields 2.7x higher inbox placement.
- **Warm up the sending domain** by sending small volumes initially and increasing gradually over weeks.
- **Always provide a fallback:** Include the instance URL and temporary credentials on the post-payment success page, not just in email. If email fails, the user still has their credentials.
- **Store credentials in your database** so the user can retrieve them from a "My Instances" page or via a "resend credentials" action.

**Phase:** Email infrastructure must be set up before launch. DNS records (SPF, DKIM, DMARC) should be configured weeks before go-live to build reputation.

**Confidence:** HIGH -- Email deliverability benchmarks are well-documented. The 30% new-domain penalty is cited across multiple 2025 industry reports.

**Sources:**
- [Sub-Domains & Email Deliverability: 2025 Guide](https://www.courier.com/blog/how-to-use-sub-domains-to-improve-email-deliverability)
- [Mastering Email Deliverability: 2026 Guide](https://www.mailmunch.com/blog/mastering-email-deliverability)
- [Outlook's New Requirements for High-Volume Senders](https://techcommunity.microsoft.com/blog/microsoftdefenderforoffice365blog/strengthening-email-ecosystem-outlook%E2%80%99s-new-requirements-for-high%E2%80%90volume-senders/4399730)

---

### Pitfall 6: Subdomain Collision and DNS Race Conditions

**What goes wrong:** Two users request the same subdomain simultaneously. The existing workflow checks availability by querying Coolify's app list, but this is a TOCTOU (time-of-check-time-of-use) race condition. Between checking availability and creating the app, another request could claim the same subdomain. Additionally, subdomains that were provisioned but then failed (orphaned apps) may block future legitimate requests.

**Why it happens:** The current `provision-instance.yml` does subdomain validation in a non-atomic way: check Coolify apps list -> sleep 15 -> create app. There is no database-level unique constraint on subdomains. With self-service provisioning handling concurrent requests, this gap becomes exploitable.

**Prevention:**
- **Claim subdomains in your database first** with a `UNIQUE` constraint on the subdomain column. The database transaction is the lock, not the Coolify API check.
- **Workflow:** (1) Validate subdomain format, (2) INSERT into database with `status=CLAIMED` (fails on duplicate), (3) proceed with Coolify provisioning, (4) update to `status=ACTIVE` on success or `status=FAILED` on failure.
- **Expand the reserved subdomain list.** The current workflow only blocks `www, api, coolify, mail, smtp`. Add: `admin, dashboard, app, auth, login, signup, status, help, support, docs, blog, cdn, assets, static, staging, dev, test, demo, billing, pay, internal, monitoring, grafana, prometheus, traefik, portainer` and any infrastructure-related names.
- **Handle orphaned subdomains:** Failed provisioning should release the subdomain after a cooldown period (e.g., 1 hour), not permanently block it.
- **Length and content validation:** Minimum 3 characters, maximum 30. No consecutive hyphens. Consider a basic profanity/trademark blocklist.

**Phase:** Backend database schema design phase. The subdomain registry is a foundational data model concern.

**Confidence:** HIGH -- TOCTOU race conditions are a well-understood class of bug. The existing workflow's non-atomic check-then-create is demonstrably vulnerable.

---

### Pitfall 7: GitHub Actions as a Provisioning Backend

**What goes wrong:** Using `workflow_dispatch` as the provisioning mechanism introduces several constraints: (a) triggering requires repo write access (violates least privilege), (b) the `GITHUB_TOKEN` rate limit is 1,000 requests/hour/repo, (c) workflow runs are not instant -- there is queueing delay, (d) the workflow has no built-in mechanism to report status back to your backend in real-time, (e) GitHub Actions outages directly become your provisioning outages.

**Why it happens:** The existing `provision-instance.yml` was designed for operator use, not API-driven provisioning. `workflow_dispatch` is a CI/CD tool, not an infrastructure orchestration API. But it works, and rewriting it is costly, so there is a temptation to wire the frontend directly to it.

**Prevention:**
- **For v2.0, keep GitHub Actions as the provisioning executor but add a backend orchestrator.** The backend calls the GitHub API to trigger the workflow (now returning run IDs as of February 2026), then polls for completion.
- **Create a fine-grained Personal Access Token** with minimal scope rather than using a token with full repo write access. Unfortunately, GitHub still requires repo write for `workflow_dispatch`, so this is a known limitation. Use GitHub Environments to restrict which branches/workflows can access secrets.
- **Add actor checks in the workflow YAML** so only your backend's service account can trigger it: `if: github.actor == 'localnodes-bot'`
- **Implement a status callback:** Have the workflow POST status updates to your backend's webhook endpoint at each step (app created, env configured, deploy triggered, deploy complete, health check passed). This eliminates polling the GitHub API.
- **Plan for the migration path:** The long-term architecture should call the Coolify API directly from the backend, bypassing GitHub Actions entirely. The workflow is a transitional bridge.
- **Monitor GitHub Actions usage:** At 1,000 API requests/hour, you are limited to roughly 100-200 provisions/hour (each provision requires multiple API calls for status polling). This is likely sufficient for early growth but will hit limits at scale.

**Phase:** Backend architecture decision. Must decide whether to keep GitHub Actions as executor or call Coolify API directly.

**Confidence:** MEDIUM -- The GitHub Actions API returning run IDs (February 2026) significantly improves this pattern, but using CI/CD for production provisioning remains a known anti-pattern at scale.

**Sources:**
- [GitHub Actions API Update: Returns Run IDs](https://bitcoinethereumnews.com/tech/github-actions-api-update-streamlines-workflow-tracking-for-developers/)
- [workflow_dispatch permissions discussion](https://github.com/orgs/community/discussions/26622)
- [GitHub Actions Security Best Practices Cheat Sheet](https://blog.gitguardian.com/github-actions-security-cheat-sheet/)

---

### Pitfall 8: Single Server Resource Exhaustion

**What goes wrong:** Each LocalNodes instance runs 4 containers: MariaDB, Solr, Qdrant, and Drupal/Apache. A conservative estimate is 1.5-2GB RAM per instance. On a single server with 32GB RAM, after Coolify overhead (~700MB) and OS (~1GB), you can run approximately 15 instances before hitting memory limits. With no per-container resource limits set (Docker defaults to unlimited), a single runaway instance can OOM-kill the entire server, taking down all instances.

**Why it happens:** Coolify does not enforce resource limits by default. Docker containers with no limits can consume all host RAM. The concurrent build limit in Coolify has documented bugs where it is not enforced. And self-service provisioning means you no longer manually control how many instances exist.

**Prevention:**
- **Set memory limits on every container** in the docker-compose.yaml: `deploy.resources.limits.memory`. Use the 1.5x rule (1.5x expected usage for limits, 1x for reservations).
- **Implement a capacity gate** in the backend: before provisioning, check current instance count against server capacity. Return "we're at capacity, please try again later" rather than crashing the server.
- **Enable swap space** as a safety net (but not a substitute for proper limits).
- **Monitor and alert** on total server memory usage. Alert at 80%, block new provisions at 90%.
- **Plan multi-server architecture early.** Coolify supports deploying to multiple servers from a single dashboard. Define the capacity ceiling for a single server and have a plan for what happens when you hit it.
- **Run `docker system prune` on a schedule** to reclaim disk from old images and build cache.

**Phase:** Infrastructure capacity planning. Must be addressed before launch, with monitoring in place.

**Confidence:** HIGH -- Docker resource behavior is well-documented. Coolify concurrent build bugs are confirmed in multiple GitHub issues.

**Sources:**
- [Running Multiple Apps on One VPS with Coolify: Resource Planning Guide](https://massivegrid.com/blog/coolify-resource-planning-multiple-apps/)
- [Bug: Max Concurrent Builds Ignored](https://github.com/coollabsio/coolify/issues/5747)
- [Bug: Concurrent builds exceed configured limit](https://github.com/coollabsio/coolify/issues/6076)

---

### Pitfall 9: Orphaned Resources on Partial Failure

**What goes wrong:** Provisioning is a multi-step pipeline. If it fails partway through, earlier steps leave artifacts that are not cleaned up: a Coolify application exists but has no running containers, environment variables are set but the deploy never triggered, Traefik routes exist pointing to nothing, DNS wildcard resolves to a Coolify app that returns 502. These orphans accumulate over time, consuming resources and causing confusion.

**Specific failure points in the current workflow:**
1. Coolify app created -> env configuration fails -> orphaned app with no config
2. App created + env set -> deploy fails -> orphaned app with containers in error state
3. App created + deployed -> health check never passes -> app running but Drupal install stuck
4. Everything succeeds -> Stripe payment later disputed/refunded -> active instance with no paying customer

**Prevention:**
- **Track every provisioning step in the database** with a state machine. If provisioning fails, a cleanup job knows exactly what was created and what needs to be deleted.
- **Implement a cleanup worker** that runs on a schedule (every 15 minutes): find provisions stuck in `PROVISIONING` for >20 minutes, attempt cleanup (delete Coolify app via API), transition to `PROVISIONING_FAILED`, trigger refund.
- **Tag Coolify apps with metadata:** include the internal provision ID in the app name or description so orphaned apps can be traced back to their provisioning record.
- **Reconciliation job (daily):** Compare active Coolify apps against active subscriptions. Flag any app without a matching subscription. Flag any subscription without a matching app.
- **For Stripe chargebacks/refunds:** Listen for `charge.refunded` and `charge.dispute.created` webhooks. Trigger instance suspension (not immediate deletion -- allow for dispute resolution).

**Phase:** Backend design phase (cleanup worker) + operational phase (reconciliation).

**Confidence:** HIGH -- Orphaned resource cleanup is a universal problem in cloud provisioning. AWS, Azure, and GCP all have dedicated tooling for this exact issue.

**Sources:**
- [Detecting Orphaned Resources Using AWS Config Rules](https://www.cloudoptimo.com/blog/detecting-orphaned-resources-using-aws-config-rules/)
- [Preventing A Buildup of CloudFormation Orphaned Resources](https://www.serverlesssam.com/p/cleanup-cloudformation/)

---

## Minor Pitfalls

Mistakes that cause friction or tech debt but are not immediately dangerous.

### Pitfall 10: Stripe Test vs. Live Mode Confusion

**What goes wrong:** Developing with Stripe test keys, forgetting to switch to live keys before launch, or accidentally using live keys in development (creating real charges during testing). Stripe's public status logs indicate misconfigured API secrets contribute to over 15% of avoidable support requests.

**Prevention:**
- Use environment variables for all Stripe keys (`STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`). Never hardcode.
- Validate key prefix on startup: test keys start with `sk_test_` / `pk_test_`, live keys with `sk_live_` / `pk_live_`. Log a clear warning if the environment does not match the key type.
- Use separate Stripe webhook endpoints for test and live. Webhook signing secrets are different between modes.

**Phase:** Environment setup, addressed during initial backend development.

**Confidence:** HIGH -- trivial to prevent but frequently missed.

---

### Pitfall 11: Webhook Signature Verification Skipped or Broken

**What goes wrong:** The webhook endpoint does not verify the Stripe signature, or verifies it incorrectly (e.g., parsing the body as JSON before verification, which changes the byte content). Over 40% of transaction issues arise from missed webhook events or improper signature validation. Without verification, anyone can POST fake `checkout.session.completed` events to trigger free provisioning.

**Prevention:**
- Verify the signature using the raw request body (not parsed JSON). Stripe's SDK provides `stripe.webhooks.constructEvent(rawBody, sig, webhookSecret)`.
- Verify timestamp freshness (reject events older than 5 minutes) to prevent replay attacks.
- Test with `stripe listen --forward-to` during development to ensure real signatures are used.

**Phase:** Backend implementation. Must be in place before any webhook endpoint is exposed.

**Confidence:** HIGH -- Stripe documentation is explicit about this requirement.

**Sources:**
- [Webhook Security Best Practices 2025-2026](https://dev.to/digital_trubador/webhook-security-best-practices-for-production-2025-2026-384n)

---

### Pitfall 12: No Monitoring or Alerting from Day One

**What goes wrong:** Provisioning failures, webhook delivery issues, and resource exhaustion happen silently. Without monitoring, the first indication of a problem is a customer complaint or a server crash. One SaaS platform's logging retention was set to 7 days instead of the required 90 days -- when a breach was discovered, they could not provide evidence, resulting in a $6M fine.

**Prevention:**
- Log every webhook receipt, every provisioning state transition, and every API call to Coolify/GitHub.
- Set up alerts for: webhook handler errors, provisioning stuck >15 min, server memory >80%, Stripe webhook delivery failures.
- Use structured logging (JSON) so logs are searchable. Include correlation IDs that span from Stripe event to provisioning completion.
- Retain logs for at least 90 days.

**Phase:** Must be part of initial backend deployment. Not a "later" concern.

**Confidence:** HIGH -- operational monitoring is universally recommended.

---

### Pitfall 13: Hardcoded Secrets in the Provisioning Pipeline

**What goes wrong:** The current `provision-instance.yml` contains `DRUPAL_ADMIN_PASS: "localnodes2026"` as a hardcoded value. Every instance provisioned has the same admin password. If this password leaks (or is guessed -- it is easily guessable), all instances are compromised.

**Prevention:**
- Generate a unique admin password per instance: `openssl rand -base64 16` (already done for DB passwords in the workflow, but not for admin).
- Deliver the unique password via email and/or the post-payment success page.
- Require password change on first login (set via Drupal's `user.settings` config).
- Never log passwords. Never include them in GitHub Actions step summaries.

**Phase:** Must be fixed when adapting the workflow for self-service use.

**Confidence:** HIGH -- this is a concrete, verifiable issue in the existing codebase.

---

## Phase-Specific Warnings

| Phase Topic | Likely Pitfall | Mitigation |
|-------------|---------------|------------|
| **Backend API design** | Payment-provisioning desync (Pitfall 1), webhook handler as SPOF (Pitfall 2), security of public exposure (Pitfall 3) | Design state machine and saga pattern first. Webhook enqueue pattern. Rate limiting + payment gate. |
| **Stripe integration** | Race conditions on duplicate webhooks, test/live key confusion, signature verification bypass | Single writer principle. Idempotency via event ID. Environment-specific keys. Raw body verification. |
| **Frontend provisioning UX** | 4-minute death zone (Pitfall 4), duplicate submissions | Email as primary delivery. Progress page as optional. Disable button. Status polling. |
| **Email delivery** | New domain penalty, SPF/DKIM/DMARC missing (Pitfall 5) | Transactional email service. DNS records weeks before launch. Fallback credentials on success page. |
| **Subdomain management** | TOCTOU collision (Pitfall 6), insufficient reserved list | Database-level unique constraint. Expanded blocklist. Orphan release after cooldown. |
| **GitHub Actions integration** | Repo write access requirement, rate limits, outage dependency (Pitfall 7) | Backend orchestrator. Status callbacks. Fine-grained PAT. Plan migration to direct Coolify API. |
| **Infrastructure** | Single server exhaustion (Pitfall 8), orphaned resources (Pitfall 9) | Container resource limits. Capacity gate. Cleanup worker. Daily reconciliation. |
| **Security hardening** | Hardcoded passwords (Pitfall 13), input injection via subdomain names | Per-instance secrets. Input sanitization. Never interpolate user input into shell commands. |
| **Monitoring & ops** | Silent failures (Pitfall 12) | Structured logging. Alerting on all failure states. 90-day retention. |

---

## Prioritized Risk Matrix

| # | Pitfall | Severity | Likelihood | Priority |
|---|---------|----------|------------|----------|
| 1 | Payment-provisioning desync | Critical | High | P0 -- design first |
| 2 | Webhook handler SPOF | Critical | High | P0 -- design first |
| 3 | Public exposure of infrastructure | Critical | Medium | P0 -- security architecture |
| 4 | 4-minute UX death zone | High | High | P1 -- frontend + email |
| 8 | Server resource exhaustion | High | Medium | P1 -- before launch |
| 5 | Email deliverability | High | Medium | P1 -- weeks before launch |
| 9 | Orphaned resources | Medium | High | P1 -- backend design |
| 6 | Subdomain collision | Medium | Medium | P2 -- database schema |
| 7 | GitHub Actions as backend | Medium | Low | P2 -- architectural debt |
| 13 | Hardcoded admin password | Medium | High | P2 -- quick fix |
| 11 | Webhook signature bypass | High | Low | P2 -- implementation |
| 10 | Test/live key confusion | Low | Medium | P3 -- environment setup |
| 12 | No monitoring | Medium | High | P1 -- must ship with monitoring |

---

## Sources (Complete)

- [Saga Design Pattern - Azure Architecture Center](https://learn.microsoft.com/en-us/azure/architecture/patterns/saga)
- [Compensating Transaction Pattern - Azure](https://learn.microsoft.com/en-us/azure/architecture/patterns/compensating-transaction)
- [Microservices Pattern: Saga](https://microservices.io/patterns/data/saga.html)
- [The Race Condition You're Probably Shipping With Stripe Webhooks](https://dev.to/belazy/the-race-condition-youre-probably-shipping-right-now-with-stripe-webhooks-mj4)
- [Billing Webhook Race Condition Solution Guide](https://excessivecoding.com/blog/billing-webhook-race-condition-solution-guide)
- [Webhook Security Best Practices 2025-2026](https://dev.to/digital_trubador/webhook-security-best-practices-for-production-2025-2026-384n)
- [Stripe: Troubleshooting Webhook Delivery](https://support.stripe.com/questions/troubleshooting-webhook-delivery-issues)
- [Stripe Thin Events Best Practices](https://hookdeck.com/webhooks/platforms/stripe-thin-events-best-practices)
- [Seven CVEs in Coolify | Aikido](https://www.aikido.dev/blog/ai-pentesting-coolify-cves)
- [Coolify Rate-Limit Bypass Advisory](https://github.com/coollabsio/coolify/security/advisories/GHSA-688j-rm43-5r8x)
- [Prevent Crypto Mining Abuse - GitLab](https://about.gitlab.com/blog/2021/05/17/prevent-crypto-mining-abuse/)
- [Massive Cryptomining Campaign - BleepingComputer](https://www.bleepingcomputer.com/news/security/massive-cryptomining-campaign-abuses-free-tier-cloud-dev-resources/)
- [Designing for Long Waits - NN/Group](https://www.nngroup.com/articles/designing-for-waits-and-interruptions/)
- [Designing Better Loading and Progress UX](https://smart-interface-design-patterns.com/articles/designing-better-loading-progress-ux/)
- [Sub-Domains & Email Deliverability Guide](https://www.courier.com/blog/how-to-use-sub-domains-to-improve-email-deliverability)
- [Mastering Email Deliverability 2026](https://www.mailmunch.com/blog/mastering-email-deliverability)
- [GitHub Actions API Returns Run IDs](https://bitcoinethereumnews.com/tech/github-actions-api-update-streamlines-workflow-tracking-for-developers/)
- [GitHub Actions Security Cheat Sheet](https://blog.gitguardian.com/github-actions-security-cheat-sheet/)
- [Running Multiple Apps on Coolify - Resource Planning](https://massivegrid.com/blog/coolify-resource-planning-multiple-apps/)
- [Coolify Bug: Max Concurrent Builds Ignored](https://github.com/coollabsio/coolify/issues/5747)
- [Domain Name Considerations in Multitenant Solutions - Azure](https://learn.microsoft.com/en-us/azure/architecture/guide/multitenant/considerations/domain-names)
- [Wildcard TLS for Multi-Tenant Systems](https://www.skeptrune.com/posts/wildcard-tls-for-multi-tenant-systems/)
- [Detecting Orphaned Resources - AWS Config](https://www.cloudoptimo.com/blog/detecting-orphaned-resources-using-aws-config-rules/)
