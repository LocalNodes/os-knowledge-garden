# Phase 14: Payment Integration - Research

**Researched:** 2026-03-04
**Domain:** Stripe Checkout (hosted redirect), subscription billing, webhook signature verification, Nitro server routes
**Confidence:** HIGH

## Summary

Phase 14 adds Stripe Checkout integration to the existing Nuxt 4 onboarding app (`localnodes-onboarding/`). The current onboarding form (Phase 13) collects community name and email, validates the subdomain, and has a submit button labeled "Continue to Payment" that currently navigates to a placeholder route. This phase wires that button to create a Stripe Checkout Session on the server and redirect the user to Stripe's hosted payment page for a $29/month subscription.

The integration requires three server-side pieces: (1) a Nitro server route that creates a Stripe Checkout Session with `mode: 'subscription'`, passing the community name, email, and subdomain as metadata; (2) a webhook handler that receives `checkout.session.completed` events from Stripe with signature verification via `readRawBody`; and (3) success/cancel pages that the user returns to after completing or abandoning checkout. Receipt emails are handled entirely by Stripe -- enabled via the Stripe Dashboard's "Customer emails" settings under "Successful payments."

The `stripe` npm package v20.4.0 is the current stable release and provides full TypeScript support. Stripe Checkout in redirect mode is the explicit project decision (see REQUIREMENTS.md: "In-app payment forms (Stripe Elements)" is explicitly out of scope). The webhook handler in Phase 14 only needs to verify and log the event -- the actual provisioning trigger is Phase 15's responsibility.

**Primary recommendation:** Install `stripe` v20.x, create a pre-defined Product + Price in the Stripe Dashboard ($29/month recurring), use the Price ID in a Nitro server route to create Checkout Sessions, handle webhooks with `readRawBody` for signature verification, and configure receipt emails in the Stripe Dashboard.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| PAY-01 | User is redirected to Stripe Checkout for monthly subscription payment | Nitro server route creates Checkout Session with `mode: 'subscription'`, `customer_email`, and pre-created Price ID; returns `session.url` for redirect |
| PAY-02 | User receives payment receipt email from Stripe after successful payment | Enable "Successful payments" email in Stripe Dashboard > Settings > Customer emails; Stripe handles receipt automatically for subscriptions |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| stripe | 20.4.0 | Stripe API (server-side) | Official Stripe Node.js SDK. Full TypeScript support. Creates Checkout Sessions, verifies webhooks. |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| h3 | bundled | `readRawBody`, `getHeader`, `createError` | Bundled with Nitro. Required for webhook signature verification (raw body access). |
| valibot | 1.x (already installed) | Server route input validation | Validate POST body for checkout session creation endpoint. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| stripe (direct SDK) | @unlok-co/nuxt-stripe module | The Nuxt module wraps stripe and adds client-side Stripe.js loading. Not needed -- we use Stripe Checkout redirect (no client-side Stripe.js needed). Direct SDK is simpler and more transparent. |
| Stripe Checkout (redirect) | Stripe Elements / Embedded Checkout | Explicitly out of scope per REQUIREMENTS.md. Checkout handles PCI, mobile, 3D Secure with zero custom UI. |
| Pre-created Price in Dashboard | Inline `price_data` | Inline prices cannot be reused or updated. Pre-created Price gives a stable `price_` ID, visible in Dashboard, and can be updated without code changes. |

**Installation:**
```bash
cd /Users/proofoftom/Code/os-decoupled/localnodes-onboarding
npm install stripe
```

No client-side Stripe packages needed -- Stripe Checkout redirect handles all payment UI on Stripe's domain.

## Architecture Patterns

### Recommended Project Structure (Phase 14 additions)
```
localnodes-onboarding/
├── app/
│   ├── components/
│   │   └── OnboardingForm.vue       # Modified: submit creates checkout session
│   ├── pages/
│   │   ├── index.vue                # Landing page (Phase 12)
│   │   ├── onboarding.vue           # Onboarding form (Phase 13)
│   │   ├── success.vue              # Post-payment success page
│   │   └── cancel.vue               # Payment cancelled page
│   └── utils/
│       └── onboarding-schema.ts     # Existing (Phase 13)
├── server/
│   ├── api/
│   │   ├── check-subdomain.get.ts   # Existing (Phase 13)
│   │   ├── create-checkout.post.ts  # NEW: Creates Stripe Checkout Session
│   │   └── stripe-webhook.post.ts   # NEW: Handles Stripe webhook events
│   └── utils/
│       └── stripe.ts                # NEW: Stripe client singleton
├── nuxt.config.ts                   # Add Stripe env vars to runtimeConfig
└── .env.example                     # Add Stripe env var documentation
```

### Pattern 1: Stripe Client Singleton
**What:** Initialize Stripe once in a server utility, import it in all server routes.
**When to use:** Every server route that calls the Stripe API.
**Example:**
```typescript
// server/utils/stripe.ts
import Stripe from 'stripe'

let _stripe: Stripe | null = null

export function useStripe(): Stripe {
  if (!_stripe) {
    const config = useRuntimeConfig()
    if (!config.stripeSecretKey) {
      throw new Error('NUXT_STRIPE_SECRET_KEY environment variable is not set')
    }
    _stripe = new Stripe(config.stripeSecretKey)
  }
  return _stripe
}
```

**Note:** Nitro auto-imports from `server/utils/`, so `useStripe()` is globally available in all server routes without explicit imports.

### Pattern 2: Create Checkout Session Server Route
**What:** POST endpoint that receives form data, creates a Stripe Checkout Session with subscription mode, and returns the checkout URL.
**When to use:** Called by the onboarding form on submit.
**Example:**
```typescript
// server/api/create-checkout.post.ts
import * as v from 'valibot'

const bodySchema = v.object({
  communityName: v.pipe(v.string(), v.minLength(3), v.maxLength(50)),
  email: v.pipe(v.string(), v.email()),
  subdomain: v.pipe(v.string(), v.minLength(3), v.maxLength(63))
})

export default defineEventHandler(async (event) => {
  const body = await readValidatedBody(event, input => v.parse(bodySchema, input))
  const config = useRuntimeConfig()
  const stripe = useStripe()

  const session = await stripe.checkout.sessions.create({
    mode: 'subscription',
    customer_email: body.email,
    line_items: [
      {
        price: config.stripePriceId,  // Pre-created Price ID from Dashboard
        quantity: 1
      }
    ],
    metadata: {
      communityName: body.communityName,
      subdomain: body.subdomain,
      email: body.email
    },
    subscription_data: {
      metadata: {
        communityName: body.communityName,
        subdomain: body.subdomain
      }
    },
    success_url: `${getRequestURL(event).origin}/success?session_id={CHECKOUT_SESSION_ID}`,
    cancel_url: `${getRequestURL(event).origin}/onboarding`
  })

  if (!session.url) {
    throw createError({
      status: 500,
      statusText: 'Failed to create checkout session'
    })
  }

  return { url: session.url }
})
```

### Pattern 3: Webhook Handler with Signature Verification
**What:** POST endpoint that receives Stripe webhook events, verifies the signature using the raw request body, and processes the event.
**When to use:** Stripe sends events to this endpoint after payment completes.
**Example:**
```typescript
// server/api/stripe-webhook.post.ts
import type Stripe from 'stripe'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig()
  const stripe = useStripe()

  const signature = getHeader(event, 'stripe-signature')
  if (!signature) {
    throw createError({ status: 400, statusText: 'Missing Stripe signature' })
  }

  const rawBody = await readRawBody(event)
  if (!rawBody) {
    throw createError({ status: 400, statusText: 'Missing request body' })
  }

  let stripeEvent: Stripe.Event

  try {
    stripeEvent = stripe.webhooks.constructEvent(
      rawBody,
      signature,
      config.stripeWebhookSecret
    )
  } catch (err: any) {
    console.error('Webhook signature verification failed:', err.message)
    throw createError({ status: 400, statusText: 'Invalid webhook signature' })
  }

  // Handle the event
  switch (stripeEvent.type) {
    case 'checkout.session.completed': {
      const session = stripeEvent.data.object as Stripe.Checkout.Session
      console.log('Checkout completed:', {
        sessionId: session.id,
        customerEmail: session.customer_email,
        metadata: session.metadata,
        subscriptionId: session.subscription
      })
      // Phase 15 will add: trigger provisioning via GitHub Actions
      break
    }
    default:
      console.log('Unhandled event type:', stripeEvent.type)
  }

  return { received: true }
})
```

### Pattern 4: Form Submit Redirect to Stripe
**What:** Modify the OnboardingForm to POST to the checkout endpoint and redirect the user.
**When to use:** When the user clicks "Continue to Payment" after validation passes.
**Example:**
```typescript
// In OnboardingForm.vue <script setup>
async function onSubmit() {
  if (availability.value !== 'available') return

  const { url } = await $fetch('/api/create-checkout', {
    method: 'POST',
    body: {
      communityName: state.communityName,
      email: state.email,
      subdomain: slug.value
    }
  })

  // Redirect to Stripe Checkout
  await navigateTo(url, { external: true })
}
```

### Pattern 5: Runtime Config for Stripe Keys
**What:** Store all Stripe credentials as private runtime config, mapped from environment variables.
**When to use:** All Stripe-related server routes.
**Example:**
```typescript
// nuxt.config.ts (additions for Phase 14)
export default defineNuxtConfig({
  runtimeConfig: {
    // Existing (Phase 13)
    coolifyApiUrl: 'https://coolify.localnodes.xyz/api/v1',
    coolifyApiToken: '',

    // NEW: Stripe (all server-only)
    stripeSecretKey: '',       // NUXT_STRIPE_SECRET_KEY
    stripeWebhookSecret: '',   // NUXT_STRIPE_WEBHOOK_SECRET
    stripePriceId: '',         // NUXT_STRIPE_PRICE_ID
  }
})
```

```env
# .env.example (additions for Phase 14)
NUXT_COOLIFY_API_TOKEN=your_coolify_api_token_here
NUXT_STRIPE_SECRET_KEY=sk_test_xxx
NUXT_STRIPE_WEBHOOK_SECRET=whsec_xxx
NUXT_STRIPE_PRICE_ID=price_xxx
```

### Anti-Patterns to Avoid
- **Exposing Stripe secret key to the client:** Never put `stripeSecretKey` under `runtimeConfig.public`. All Stripe operations happen server-side.
- **Using `readBody` instead of `readRawBody` for webhooks:** `readBody` parses JSON, destroying the raw payload needed for signature verification. Always use `readRawBody` for webhook handlers.
- **Hardcoding the Price ID in server route code:** Store it as a runtime config variable (`NUXT_STRIPE_PRICE_ID`). This allows switching between test and live prices without code changes.
- **Creating inline prices with `price_data`:** Inline prices are not reusable, cannot be updated, and do not appear in the Stripe Dashboard. Use a pre-created Price.
- **Skipping server-side validation on the checkout endpoint:** The client can send any data. Always validate community name, email, and subdomain on the server before creating a Checkout Session.
- **Processing webhook events synchronously with heavy operations:** Return `{ received: true }` quickly (within 20 seconds). In Phase 15, provisioning will be triggered asynchronously (GitHub Actions dispatch). For Phase 14, logging is sufficient.
- **Installing `@stripe/stripe-js` for redirect Checkout:** Not needed. Redirect checkout only requires a server-side session creation and a `window.location` redirect. No client-side Stripe library required.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Payment UI | Custom credit card form | Stripe Checkout (hosted redirect) | PCI compliance, 3D Secure, mobile optimization, 40+ payment methods -- all handled by Stripe |
| Receipt emails | Custom email sending | Stripe Dashboard "Successful payments" email setting | Stripe generates receipts with correct amounts, tax info, branding, and sends automatically |
| Webhook signature verification | Custom HMAC comparison | `stripe.webhooks.constructEvent()` | Handles timing-safe comparison, signature format parsing, timestamp tolerance |
| Subscription billing | Custom recurring charge logic | Stripe Billing (via Checkout `mode: 'subscription'`) | Handles renewals, failed payment retries, dunning emails, prorations |
| Price management | Hardcoded amount in code | Stripe Dashboard Product + Price | Change pricing without code deployment; dashboard shows MRR, subscriber count |
| Idempotent event processing | Custom dedup logic | Stripe Event ID (`event.id`) | Each webhook event has a unique ID; store and check before processing |

**Key insight:** Stripe Checkout in redirect mode eliminates all client-side payment complexity. The entire payment flow is: create session on server, redirect user to Stripe, handle webhook when payment succeeds. Zero PCI scope, zero custom payment UI.

## Common Pitfalls

### Pitfall 1: Webhook Body Parsing Destroys Signature Verification
**What goes wrong:** Using `readBody(event)` instead of `readRawBody(event)` causes `stripe.webhooks.constructEvent()` to throw "No signatures found matching the expected signature for payload."
**Why it happens:** `readBody` parses JSON, which changes whitespace and key ordering. The signature is computed on the exact bytes received.
**How to avoid:** Always use `readRawBody(event)` (returns a string) or `readRawBody(event, false)` (returns a Buffer) for webhook handlers.
**Warning signs:** 400 errors on webhook endpoint, "Webhook signature verification failed" in logs.

### Pitfall 2: Missing Metadata Propagation to Subscription
**What goes wrong:** Metadata is only on the Checkout Session, not on the Subscription object. When Phase 15 needs to look up subdomain/email from recurring subscription events, the data is missing.
**Why it happens:** Stripe does NOT automatically copy Checkout Session metadata to the Subscription. They are separate objects.
**How to avoid:** Pass metadata in BOTH `metadata` (top-level, for checkout.session.completed) AND `subscription_data.metadata` (for recurring subscription events). For Phase 14, the top-level `metadata` on the session is sufficient since we listen to `checkout.session.completed`.
**Warning signs:** `session.subscription` has no metadata when queried later.

### Pitfall 3: Stripe Dashboard Receipt Emails Not Enabled
**What goes wrong:** PAY-02 requirement fails -- users complete payment but receive no receipt email.
**Why it happens:** Stripe receipt emails are NOT enabled by default. They must be turned on in the Dashboard.
**How to avoid:** Go to Stripe Dashboard > Settings > Customer emails > Toggle "Successful payments" ON. Test in sandbox mode by manually sending a test receipt.
**Warning signs:** Successful payments with no receipt emails. Note: sandbox/test mode does NOT automatically send receipt emails even when enabled; you must manually trigger test receipts from the Dashboard.

### Pitfall 4: Vercel Deployment Protection Blocking Webhooks
**What goes wrong:** Stripe webhook requests to the Vercel-deployed app get 401/403 responses.
**Why it happens:** Vercel's Deployment Protection requires authentication for all requests, including webhooks from external services.
**How to avoid:** Disable Deployment Protection for the webhook route, or exclude `/api/stripe-webhook` from protection in Vercel settings. Alternatively, in Vercel project settings, set "Protection Bypass for Automation" to allow specific paths.
**Warning signs:** Webhook deliveries fail with 401 status in Stripe Dashboard.

### Pitfall 5: Wrong Webhook Signing Secret for Environment
**What goes wrong:** Webhook signature verification fails with 400 errors despite correct implementation.
**Why it happens:** The webhook signing secret is different for: (a) local development via Stripe CLI, (b) test mode webhook endpoint in Dashboard, and (c) live mode webhook endpoint. Using the wrong one for the environment causes failures.
**How to avoid:** Use `NUXT_STRIPE_WEBHOOK_SECRET` env var. For local dev, use the `whsec_` secret printed by `stripe listen`. For production, use the secret from the webhook endpoint created in the Stripe Dashboard.
**Warning signs:** Signature verification fails only in specific environments.

### Pitfall 6: Form Double-Submit Creating Multiple Checkout Sessions
**What goes wrong:** User clicks "Continue to Payment" multiple times, creating multiple Checkout Sessions and potentially multiple subscriptions.
**Why it happens:** No loading state on the submit button during the API call.
**How to avoid:** Set a `submitting` ref to `true` before the API call, disable the button while submitting, and show a loading indicator. Only re-enable after redirect or error.
**Warning signs:** Multiple Checkout Sessions for the same user in Stripe Dashboard.

### Pitfall 7: Not Handling Cancel URL Properly
**What goes wrong:** User cancels payment on Stripe, but when they return to the app, their form data is lost.
**Why it happens:** The cancel URL navigates to `/onboarding` which renders a fresh form.
**How to avoid:** Set the cancel URL to `/onboarding` -- the form state will be fresh, but this is acceptable because the user intentionally cancelled. They can re-enter the 2 fields quickly. If preserving state is desired, use sessionStorage, but this adds complexity for minimal UX gain with only 2 fields.
**Warning signs:** Users complaining about re-entering data after cancelling payment.

## Code Examples

### Stripe Dashboard Setup (Manual Steps)

```
1. Log in to Stripe Dashboard (https://dashboard.stripe.com)
2. Create Product:
   - Go to: Products > + Add product
   - Name: "LocalNodes Knowledge Garden"
   - Description: "AI-powered community knowledge garden with hosting, updates, and support"
3. Create Price:
   - Pricing model: Standard pricing
   - Amount: $29.00
   - Currency: USD
   - Billing period: Monthly
   - Save and copy the Price ID (e.g., price_xxx)
4. Enable receipt emails:
   - Go to: Settings > Customer emails
   - Toggle "Successful payments" ON
5. Create webhook endpoint (for production):
   - Go to: Developers > Webhooks > + Add endpoint
   - URL: https://localnodes.xyz/api/stripe-webhook
   - Events: checkout.session.completed
   - Copy the Signing secret (whsec_xxx)
6. Copy API keys:
   - Go to: Developers > API keys
   - Copy Secret key (sk_test_xxx for test, sk_live_xxx for production)
```

### Local Development with Stripe CLI

```bash
# Install Stripe CLI (macOS)
brew install stripe/stripe-cli/stripe

# Login to Stripe account
stripe login

# Forward webhooks to local Nuxt dev server
stripe listen --forward-to localhost:3000/api/stripe-webhook --events checkout.session.completed

# The CLI prints a webhook signing secret (whsec_xxx)
# Set this as NUXT_STRIPE_WEBHOOK_SECRET in .env

# In a separate terminal, trigger a test checkout.session.completed event
stripe trigger checkout.session.completed
```

### Success Page
```vue
<!-- app/pages/success.vue -->
<script setup lang="ts">
useSeoMeta({
  title: 'Payment Successful - LocalNodes',
  description: 'Your payment was successful. Your knowledge garden is being set up.'
})

const route = useRoute()
const sessionId = route.query.session_id as string | undefined
</script>

<template>
  <UPageSection>
    <div class="max-w-md mx-auto text-center">
      <UIcon name="i-lucide-check-circle" class="text-green-500 text-6xl mb-4" />
      <h1 class="text-2xl font-bold mb-2">
        Payment successful!
      </h1>
      <p class="text-(--ui-text-muted) mb-8">
        Thank you for your payment. Your knowledge garden is being prepared.
        You'll receive a welcome email when it's ready.
      </p>
      <!-- Phase 16 will add: progress/status UI here -->
      <UButton
        label="Back to Home"
        to="/"
        variant="outline"
        size="xl"
      />
    </div>
  </UPageSection>
</template>
```

### Cancel Page
```vue
<!-- app/pages/cancel.vue -->
<script setup lang="ts">
useSeoMeta({
  title: 'Payment Cancelled - LocalNodes',
  description: 'Your payment was cancelled. No charges were made.'
})
</script>

<template>
  <UPageSection>
    <div class="max-w-md mx-auto text-center">
      <UIcon name="i-lucide-arrow-left" class="text-(--ui-text-muted) text-6xl mb-4" />
      <h1 class="text-2xl font-bold mb-2">
        Payment cancelled
      </h1>
      <p class="text-(--ui-text-muted) mb-8">
        No charges were made. You can try again whenever you're ready.
      </p>
      <UButton
        label="Try Again"
        to="/onboarding"
        color="primary"
        size="xl"
      />
    </div>
  </UPageSection>
</template>
```

### Complete nuxt.config.ts (after Phase 14)
```typescript
export default defineNuxtConfig({
  modules: ['@nuxt/ui', '@nuxt/content'],

  css: ['~/assets/css/main.css'],

  colorMode: {
    preference: 'dark'
  },

  runtimeConfig: {
    // Coolify API (Phase 13)
    coolifyApiUrl: 'https://coolify.localnodes.xyz/api/v1',
    coolifyApiToken: '',

    // Stripe (Phase 14)
    stripeSecretKey: '',
    stripeWebhookSecret: '',
    stripePriceId: '',
  },

  routeRules: {
    '/': { prerender: true },
    '/onboarding': { ssr: true },
    '/success': { ssr: true },
    '/cancel': { prerender: true }
  },

  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' }
      ]
    }
  },

  compatibilityDate: '2025-07-15',
  devtools: { enabled: true }
})
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Stripe Checkout (redirect only) | Stripe Checkout (redirect, embedded, or custom UI mode) | 2025-2026 | New `ui_mode: 'embedded'` keeps user on your domain; redirect remains simplest |
| `stripe.checkout.sessions.create()` one-time | Same API, `mode: 'subscription'` for recurring | Stable since 2020 | No change needed -- subscription checkout is mature |
| Manual `JSON.parse` + HMAC for webhooks | `stripe.webhooks.constructEvent()` | Built into stripe SDK | Handles timing-safe comparison, timestamp tolerance |
| `@stripe/stripe-js` required for all flows | Not needed for redirect Checkout | Always true | Redirect checkout only needs server-side SDK |
| API version pinning required | SDK pins API version automatically | v20.x pins `2026-02-25.clover` | No manual API version management needed |

**Deprecated/outdated:**
- `payment_method_types: ['card']` on Checkout Sessions: Stripe now auto-detects the best payment methods. Omit this parameter to let Stripe optimize.
- `statusCode` / `statusMessage` in h3: deprecated in favor of `status` / `statusText` (prep for h3 v2). Use `statusText` in `createError()`.
- Manual API version string: stripe v20.4.0 pins `2026-02-25.clover` automatically; no need to pass `apiVersion` to constructor.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Vitest 3.2.x (already configured) |
| Config file | `vitest.config.ts` (exists from Phase 13) |
| Quick run command | `npx vitest run tests/unit/create-checkout.test.ts` |
| Full suite command | `npx vitest run` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PAY-01 | Checkout session created with correct params, URL returned | unit | `npx vitest run tests/unit/create-checkout.test.ts` | No -- Wave 0 |
| PAY-01 | Form submit calls API and redirects to returned URL | unit | `npx vitest run tests/unit/onboarding-submit.test.ts` | No -- Wave 0 |
| PAY-02 | Receipt emails -- Stripe Dashboard config | manual-only | Manual: verify "Successful payments" toggle in Stripe Dashboard | N/A |
| PAY-01+02 | Webhook handler verifies signature, parses event | unit | `npx vitest run tests/unit/stripe-webhook.test.ts` | No -- Wave 0 |

### Sampling Rate
- **Per task commit:** `npx vitest run tests/unit/create-checkout.test.ts` (fastest)
- **Per wave merge:** `npx vitest run`
- **Phase gate:** Full suite green + manual Stripe Dashboard verification

### Wave 0 Gaps
- [ ] `tests/unit/create-checkout.test.ts` -- covers PAY-01 (checkout session creation logic)
- [ ] `tests/unit/stripe-webhook.test.ts` -- covers webhook signature verification and event parsing

## Open Questions

1. **Vercel Deployment Protection for webhooks**
   - What we know: Vercel may block webhook requests from Stripe if Deployment Protection is enabled
   - What's unclear: Whether the production Vercel project has this enabled
   - Recommendation: Check Vercel project settings during deployment. If enabled, add webhook path exclusion. This is a deployment-time configuration, not a code concern.

2. **Test mode vs. live mode switchover timing**
   - What we know: Stripe provides separate API keys and webhook secrets for test/live modes. All development uses test mode.
   - What's unclear: When to switch to live mode (presumably after all phases are complete)
   - Recommendation: Build everything with test mode keys. Switch to live mode is a configuration change (env vars) -- no code changes needed. Document the switchover in the deployment guide.

3. **Webhook retry behavior during Phase 14**
   - What we know: Stripe retries failed webhook deliveries for up to 3 days with exponential backoff. Phase 14 only logs events -- Phase 15 adds provisioning.
   - What's unclear: Whether webhook retries could cause issues during Phase 14's minimal handler.
   - Recommendation: Return `{ received: true }` (200 status) immediately after logging. No retry concerns for a logging-only handler.

## Sources

### Primary (HIGH confidence)
- [Stripe API - Create Checkout Session](https://docs.stripe.com/api/checkout/sessions/create?lang=node) - session creation parameters, subscription mode, metadata, URLs
- [Stripe Checkout Receipts](https://docs.stripe.com/payments/checkout/receipts) - receipt email configuration, Dashboard toggle, subscription vs one-time behavior
- [Stripe Webhook Signature Verification](https://docs.stripe.com/webhooks/signature) - `constructEvent()` usage, raw body requirement, header format
- [Stripe Products & Prices](https://docs.stripe.com/products-prices/how-products-and-prices-work) - Price ID patterns, recurring billing, Dashboard creation
- [Stripe Subscriptions via Webhooks](https://docs.stripe.com/billing/subscriptions/webhooks) - event types, metadata propagation, idempotency
- [Stripe CLI Listen Command](https://docs.stripe.com/stripe-cli/use-cli) - local webhook testing, forwarding, signing secret
- [stripe-node GitHub Releases](https://github.com/stripe/stripe-node/releases) - v20.4.0 latest stable, API version 2026-02-25.clover

### Secondary (MEDIUM confidence)
- [Stripe Payments in Nuxt 4 (djamware.com)](https://www.djamware.com/post/693eb332b00ad03314a098d2/stripe-payments-in-nuxt-4-with-server-routes-and-webhooks) - Nuxt 4 + Nitro + Stripe patterns, readRawBody usage
- [Vercel Raw Body for Webhooks](https://vercel.com/kb/guide/how-do-i-get-the-raw-body-of-a-serverless-function) - raw body access in Vercel serverless
- [Stripe Metadata Use Cases](https://docs.stripe.com/metadata/use-cases) - metadata best practices, Session vs Subscription metadata propagation

### Tertiary (LOW confidence)
- Vercel Deployment Protection interaction with webhooks (inferred from community reports, not tested)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - stripe v20.4.0 verified via GitHub releases, Nitro readRawBody verified in official Nuxt docs
- Architecture: HIGH - Checkout Session creation and webhook patterns verified via official Stripe API docs and Nuxt 4 tutorial
- Pitfalls: HIGH - Raw body parsing, metadata propagation, receipt email configuration all documented in official Stripe docs
- Testing: MEDIUM - Vitest infrastructure exists from Phase 13; stripe mocking patterns standard but not verified against specific project setup

**Research date:** 2026-03-04
**Valid until:** 2026-04-04 (Stripe API is mature and stable, 30-day validity)
