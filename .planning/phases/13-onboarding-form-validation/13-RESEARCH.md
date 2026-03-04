# Phase 13: Onboarding Form & Validation - Research

**Researched:** 2026-03-04
**Domain:** Nuxt UI v4 forms, Valibot schema validation, Nitro BFF server routes, Coolify API subdomain availability
**Confidence:** HIGH

## Summary

Phase 13 adds a multi-field onboarding form to the existing Nuxt 4 landing page (built in Phase 12). The form collects community name, email, and password, and provides live subdomain preview with real-time availability checking. The existing Nuxt project (`localnodes-onboarding/`) already has Nuxt UI v4.5+, Tailwind CSS v4, and the landing page with a "Get Started" CTA that links to `/onboarding`.

Nuxt UI v4 provides a complete form system via `UForm` + `UFormField` + `UInput` that handles schema-based validation (supporting Valibot, Zod, Yup), automatic error display, and form state tracking (dirty, touched, blurred fields). Valibot is the recommended validation library for this project due to its dramatically smaller bundle size (~1.4 kB vs Zod's ~17.7 kB) while being a first-class citizen in Nuxt UI v4's form system via the Standard Schema specification. Additionally, Nuxt UI v4 provides an `AuthForm` component purpose-built for login/register forms, but for this use case a custom form with the subdomain preview is more appropriate since AuthForm lacks the live preview pattern.

The subdomain availability check requires a Nitro server route (`/api/check-subdomain`) that queries the Coolify API to check if a domain is already in use. This BFF pattern keeps the Coolify API token server-side via `runtimeConfig`. The client uses a debounced watch (via VueUse's `refDebounced`, already available as a transitive dependency) to avoid hammering the API on every keystroke.

**Primary recommendation:** Build a custom onboarding form page at `app/pages/onboarding.vue` using `UForm` + `UFormField` + `UInput` with Valibot schema validation. Create a `useSubdomain` composable for slugification and debounced availability checking. Add a Nitro server route at `server/api/check-subdomain.get.ts` that proxies availability checks to the Coolify API.

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| ONBD-01 | User can enter community name, email, and password in a 3-field form | UForm + UFormField + UInput with Valibot schema; password toggle via trailing slot |
| ONBD-02 | User sees live subdomain preview (e.g., `mycommunity.localnodes.xyz`) as they type | Reactive computed from slugified community name, displayed inline below the name field |
| ONBD-03 | User sees real-time feedback that their chosen subdomain is available | Debounced API call to `/api/check-subdomain` with visual status indicator (icon + text) |
| ONBD-04 | Community name is automatically slugified into a valid subdomain | Custom `slugify()` utility: lowercase, replace spaces/special chars with hyphens, strip invalid chars, collapse hyphens, trim leading/trailing hyphens |
| ERR-03 | User sees validation errors for invalid or unavailable community names | UForm automatic error display via UFormField, plus server-side validation returning structured errors |
</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| @nuxt/ui | 4.5+ | Form components (UForm, UFormField, UInput) | Already installed. Provides complete form system with automatic validation, error display, state tracking. |
| valibot | 1.x | Schema validation | ~1.4 kB bundle (vs Zod's ~17.7 kB). First-class Nuxt UI v4 support via Standard Schema spec. Tree-shakable modular architecture. |
| @vueuse/core | 12.x | Debounced refs for API calls | Already installed as transitive dependency of Nuxt UI. Provides `refDebounced` for debouncing reactive values. |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| h3 | bundled | Server route utilities | Bundled with Nitro. Use `getValidatedQuery`, `createError`, `getQuery` for server-side request handling. |
| slugify | not needed | String slugification | Do NOT install. A simple custom function (15 lines) is sufficient for subdomain slugification. No need for a library dependency. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Valibot | Zod v4 | Zod has larger ecosystem/docs but 12x larger bundle. Both are first-class in Nuxt UI v4. Valibot wins for client-side form validation. |
| Custom slugify function | `slugify` npm package | The npm package handles Unicode transliteration (Chinese, Japanese, etc.) but adds a dependency for a simple regex operation. Community names will be English-centric. |
| Custom debounce | lodash.debounce | VueUse's `refDebounced` is already available and integrates natively with Vue reactivity. No reason to add lodash. |
| AuthForm | Custom UForm | AuthForm is designed for login/register but lacks subdomain preview slot. Custom form gives full control over the live preview UX. |

**Installation:**
```bash
cd localnodes-onboarding
npm install valibot
```

No other installations needed. `@vueuse/core` is already a transitive dependency (via `@nuxt/ui`). Import directly:
```ts
import { refDebounced } from '@vueuse/core'
```

## Architecture Patterns

### Recommended Project Structure (Phase 13 additions)
```
localnodes-onboarding/
├── app/
│   ├── composables/
│   │   └── useSubdomain.ts         # Slugify + availability check logic
│   ├── components/
│   │   ├── OnboardingForm.vue       # Main form component
│   │   └── SubdomainPreview.vue     # Live subdomain preview with status
│   ├── pages/
│   │   ├── index.vue                # Landing page (Phase 12)
│   │   └── onboarding.vue           # Onboarding page with form
│   └── utils/
│       └── slugify.ts               # Pure slugify function
├── server/
│   └── api/
│       └── check-subdomain.get.ts   # Availability check via Coolify API
├── nuxt.config.ts                   # Add runtimeConfig for Coolify API
└── .env.example                     # Document required env vars
```

### Pattern 1: UForm with Valibot Schema Validation
**What:** Define form state as a reactive object and validate it with a Valibot schema passed to UForm's `:schema` prop.
**When to use:** All form pages in this project.
**Example:**
```vue
<script setup lang="ts">
import * as v from 'valibot'

const schema = v.object({
  communityName: v.pipe(
    v.string(),
    v.minLength(3, 'Community name must be at least 3 characters'),
    v.maxLength(50, 'Community name must be under 50 characters')
  ),
  email: v.pipe(
    v.string(),
    v.email('Please enter a valid email address')
  ),
  password: v.pipe(
    v.string(),
    v.minLength(8, 'Password must be at least 8 characters')
  )
})

type Schema = v.InferOutput<typeof schema>

const state = reactive<Partial<Schema>>({
  communityName: '',
  email: '',
  password: ''
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  // Navigate to payment (Phase 14)
  console.log('Form data:', event.data)
}
</script>

<template>
  <UForm :schema="schema" :state="state" @submit="onSubmit">
    <UFormField label="Community Name" name="communityName" required>
      <UInput v-model="state.communityName" placeholder="e.g., Cascadia" />
    </UFormField>

    <UFormField label="Email" name="email" required>
      <UInput v-model="state.email" type="email" placeholder="you@example.com" />
    </UFormField>

    <UFormField label="Password" name="password" required>
      <UInput v-model="state.password" :type="showPassword ? 'text' : 'password'" />
    </UFormField>

    <UButton type="submit" label="Continue to Payment" />
  </UForm>
</template>
```

### Pattern 2: Composable for Subdomain Logic
**What:** Encapsulate slugification, debounced availability checking, and status state in a reusable composable.
**When to use:** Whenever community name input needs subdomain preview.
**Example:**
```ts
// app/composables/useSubdomain.ts
import { refDebounced } from '@vueuse/core'

export function useSubdomain(communityName: Ref<string>) {
  const slug = computed(() => slugify(communityName.value))
  const debouncedSlug = refDebounced(slug, 500)

  const availability = ref<'idle' | 'checking' | 'available' | 'taken' | 'invalid'>('idle')
  const errorMessage = ref<string | null>(null)

  watch(debouncedSlug, async (newSlug) => {
    if (!newSlug || newSlug.length < 3) {
      availability.value = 'idle'
      errorMessage.value = null
      return
    }

    availability.value = 'checking'
    errorMessage.value = null

    try {
      const { available, reason } = await $fetch('/api/check-subdomain', {
        query: { slug: newSlug }
      })
      availability.value = available ? 'available' : 'taken'
      errorMessage.value = available ? null : (reason || 'This subdomain is already taken')
    } catch (e) {
      availability.value = 'invalid'
      errorMessage.value = 'Could not check availability'
    }
  })

  // Reset when raw slug changes (instant feedback before debounce fires)
  watch(slug, () => {
    if (slug.value !== debouncedSlug.value) {
      availability.value = 'checking'
    }
  })

  const subdomain = computed(() =>
    slug.value ? `${slug.value}.localnodes.xyz` : ''
  )

  return { slug, subdomain, availability, errorMessage }
}
```

### Pattern 3: Nitro Server Route for Subdomain Availability
**What:** A GET endpoint that checks the Coolify API for existing applications using the requested subdomain.
**When to use:** Called by the client composable during debounced availability checks.
**Example:**
```ts
// server/api/check-subdomain.get.ts
import * as v from 'valibot'

const querySchema = v.object({
  slug: v.pipe(
    v.string(),
    v.minLength(3),
    v.maxLength(63),
    v.regex(/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/)
  )
})

const RESERVED_SUBDOMAINS = ['www', 'api', 'coolify', 'mail', 'smtp', 'admin', 'app', 'dashboard']

export default defineEventHandler(async (event) => {
  const query = await getValidatedQuery(event, input => v.parse(querySchema, input))
  const { slug } = query

  // Check reserved subdomains
  if (RESERVED_SUBDOMAINS.includes(slug)) {
    return { available: false, reason: 'This name is reserved' }
  }

  // Check Coolify API for existing applications
  const config = useRuntimeConfig(event)
  const fqdn = `${slug}.localnodes.xyz`

  try {
    const apps = await $fetch<any[]>(`${config.coolifyApiUrl}/applications`, {
      headers: {
        Authorization: `Bearer ${config.coolifyApiToken}`,
        Accept: 'application/json'
      }
    })

    const taken = apps.some(app =>
      app.docker_compose_domains &&
      JSON.stringify(app.docker_compose_domains).includes(fqdn)
    )

    return { available: !taken, reason: taken ? 'This subdomain is already in use' : null }
  } catch (error) {
    throw createError({
      status: 502,
      statusText: 'Could not verify subdomain availability'
    })
  }
})
```

### Pattern 4: Live Subdomain Preview Component
**What:** Visual component showing the generated subdomain with availability status.
**When to use:** Below the community name input field.
**Example:**
```vue
<!-- app/components/SubdomainPreview.vue -->
<template>
  <div v-if="subdomain" class="mt-2 flex items-center gap-2 text-sm">
    <UIcon
      :name="statusIcon"
      :class="statusColor"
      class="shrink-0"
    />
    <span class="font-mono text-muted">{{ subdomain }}</span>
    <span :class="statusColor" class="text-xs">
      {{ statusText }}
    </span>
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{
  subdomain: string
  availability: 'idle' | 'checking' | 'available' | 'taken' | 'invalid'
}>()

const statusIcon = computed(() => {
  switch (props.availability) {
    case 'checking': return 'i-lucide-loader-circle'
    case 'available': return 'i-lucide-check-circle'
    case 'taken': return 'i-lucide-x-circle'
    case 'invalid': return 'i-lucide-alert-circle'
    default: return 'i-lucide-globe'
  }
})

const statusColor = computed(() => {
  switch (props.availability) {
    case 'available': return 'text-green-500'
    case 'taken': return 'text-red-500'
    case 'invalid': return 'text-amber-500'
    default: return 'text-muted'
  }
})

const statusText = computed(() => {
  switch (props.availability) {
    case 'checking': return 'Checking...'
    case 'available': return 'Available!'
    case 'taken': return 'Already taken'
    case 'invalid': return 'Could not check'
    default: return ''
  }
})
</script>
```

### Pattern 5: Password Visibility Toggle
**What:** Toggle password input between `password` and `text` types using UInput's trailing slot.
**When to use:** Password fields.
**Example:**
```vue
<script setup>
const showPassword = ref(false)
</script>

<template>
  <UFormField label="Password" name="password" required>
    <UInput
      v-model="state.password"
      :type="showPassword ? 'text' : 'password'"
      placeholder="At least 8 characters"
    >
      <template #trailing>
        <UButton
          :icon="showPassword ? 'i-lucide-eye-off' : 'i-lucide-eye'"
          variant="ghost"
          size="xs"
          :padded="false"
          @click="showPassword = !showPassword"
        />
      </template>
    </UInput>
  </UFormField>
</template>
```

### Pattern 6: Runtime Config for Coolify API Credentials
**What:** Store Coolify API URL and token as private runtime config, overridable via environment variables.
**When to use:** Any server route that calls external APIs.
**Example:**
```ts
// nuxt.config.ts
export default defineNuxtConfig({
  runtimeConfig: {
    // Private keys (server-only)
    coolifyApiUrl: 'https://coolify.localnodes.xyz/api/v1',
    coolifyApiToken: '', // Set via NUXT_COOLIFY_API_TOKEN env var
  }
})
```

```env
# .env.example
NUXT_COOLIFY_API_TOKEN=your_coolify_api_token_here
```

### Anti-Patterns to Avoid
- **Exposing Coolify API token to the client:** Never put `coolifyApiToken` under `runtimeConfig.public`. Always use a server route as a proxy.
- **Checking availability on every keystroke:** Always debounce (500ms minimum). Without debounce, typing "cascadia" fires 8 API calls instead of 1.
- **Using `useFetch` for the availability check:** `useFetch` is for SSR-friendly data loading. Use `$fetch` inside a `watch` callback for user-triggered API calls that should not run on the server.
- **Slugifying on the server only:** Slugify on the client for instant preview. Validate the slug again on the server (defense in depth).
- **Making the form submit button the only validation trigger:** Set `validate-on` to include `blur` so users get feedback when tabbing between fields, not just on submit.
- **Storing availability state in the Valibot schema:** Keep schema validation (format, length) separate from async availability checking. The schema validates synchronously; availability is an async side effect.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Form validation display | Custom error state per field | `UForm` + `UFormField` with `:schema` prop | Automatic error binding, dirty/touched tracking, blur/input/submit triggers |
| Debouncing reactive values | setTimeout/clearTimeout wrapper | `refDebounced` from `@vueuse/core` | Already available, integrates with Vue reactivity, handles cleanup |
| Input component styling | Custom styled inputs | `UInput` with `UFormField` | Consistent theme, automatic error highlighting, icon slots, loading state |
| Server request validation | Manual if/throw checks | `getValidatedQuery` with Valibot schema | Type-safe, automatic 400 responses on validation failure |
| Form state tracking | Custom dirty/touched refs | `UForm` exposed state (`dirty`, `dirtyFields`, `touchedFields`) | Built-in, battle-tested, accessible |
| Loading spinner in input | Custom CSS animation | `UInput` `:loading="true"` prop | Built-in spinner with correct positioning |
| Error focus on submit | Custom scroll-to-error | `UForm` `@error` event with `element.focus()` | Fires with first error element ID |

**Key insight:** Nuxt UI v4's form system is a complete solution. The `UForm` component manages validation timing, error distribution to `UFormField` children, and form state tracking. Do not build parallel state management.

## Common Pitfalls

### Pitfall 1: Using `useFetch` for User-Triggered API Calls
**What goes wrong:** `useFetch` runs on both server and client during SSR, causing the availability check to fire during page render with empty/default values.
**Why it happens:** `useFetch` is designed for data loading, not user interactions.
**How to avoid:** Use `$fetch` inside a `watch` callback or event handler. Only use `useFetch`/`useAsyncData` for data that should load with the page.
**Warning signs:** API calls firing on page load, hydration mismatches, double API calls.

### Pitfall 2: Forgetting to Validate on the Server
**What goes wrong:** Malicious users bypass client validation and submit invalid data.
**Why it happens:** Developers assume client-side Valibot validation is sufficient.
**How to avoid:** Always validate inputs in the Nitro server route using `getValidatedQuery` or `readValidatedBody`. Use the same Valibot schema (or a server-specific one) for defense in depth.
**Warning signs:** No validation code in server route handlers.

### Pitfall 3: Not Handling the "Checking" State
**What goes wrong:** User sees stale availability status while the debounced check is in-flight, or sees no feedback at all during the 500ms debounce delay.
**Why it happens:** Only updating status after the API response, not during the debounce wait.
**How to avoid:** Set status to `'checking'` immediately when the raw slug changes (before debounce), then update to `'available'`/`'taken'` after the API responds.
**Warning signs:** Status jumps directly from one result to another without a loading state.

### Pitfall 4: Slugify Producing Empty or Invalid Subdomains
**What goes wrong:** Edge cases like names with only special characters produce empty slugs, or names starting/ending with hyphens produce invalid DNS labels.
**Why it happens:** Naive regex replacement without post-processing.
**How to avoid:** The slugify function must: (1) trim leading/trailing hyphens, (2) collapse consecutive hyphens, (3) return empty string for names that produce no valid characters, (4) enforce max length of 63 (DNS label limit).
**Warning signs:** Empty subdomain preview, hyphens at start/end of slug, consecutive hyphens.

### Pitfall 5: Coolify API Response Format Assumptions
**What goes wrong:** Server route fails because the Coolify API response format doesn't match expectations.
**Why it happens:** The provision workflow (in `fresh3/.github/workflows/provision-instance.yml`) shows the Coolify API returns a plain array from `GET /applications` (no `.data` wrapper). Developers might assume a wrapped response.
**How to avoid:** The response from `GET /applications` is a plain JSON array of application objects. Each has a `docker_compose_domains` field. Parse it directly.
**Warning signs:** "Cannot read property of undefined" errors, empty availability results.

### Pitfall 6: Form Submit Before Availability Check Completes
**What goes wrong:** User submits the form while the subdomain availability check is still in-flight, proceeding with a potentially taken subdomain.
**Why it happens:** No guard against submitting during `'checking'` state.
**How to avoid:** Disable the submit button when `availability !== 'available'`. Add a custom validation function to UForm that returns an error if availability is not confirmed.
**Warning signs:** Users reaching payment with taken subdomains.

### Pitfall 7: Not Setting Route Rules for the Onboarding Page
**What goes wrong:** The onboarding page is prerendered at build time, but it needs dynamic behavior (API calls, user input).
**Why it happens:** Phase 12 set `'/' : { prerender: true }` but didn't set a rule for `/onboarding`.
**How to avoid:** Set `/onboarding` to SSR mode: `routeRules: { '/onboarding': { ssr: true } }`. This ensures server-side rendering on first load but allows full interactivity.
**Warning signs:** Form not working on first load, hydration errors.

## Code Examples

### Slugify Utility Function
```ts
// app/utils/slugify.ts
/**
 * Convert a community name to a valid DNS subdomain label.
 * Rules: lowercase, alphanumeric + hyphens, no leading/trailing hyphens,
 * no consecutive hyphens, max 63 characters.
 */
export function slugify(input: string): string {
  return input
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')  // Remove non-alphanumeric except spaces and hyphens
    .replace(/[\s]+/g, '-')         // Replace spaces with hyphens
    .replace(/-{2,}/g, '-')         // Collapse consecutive hyphens
    .replace(/^-+|-+$/g, '')        // Trim leading/trailing hyphens
    .slice(0, 63)                   // DNS label max length
}
```

### Complete Onboarding Page
```vue
<!-- app/pages/onboarding.vue -->
<template>
  <UPageSection>
    <div class="max-w-md mx-auto">
      <h1 class="text-2xl font-bold mb-2">Name your community</h1>
      <p class="text-muted mb-8">
        Choose a name for your knowledge garden. This will become your unique subdomain.
      </p>

      <OnboardingForm />
    </div>
  </UPageSection>
</template>

<script setup lang="ts">
useSeoMeta({
  title: 'Get Started - LocalNodes',
  description: 'Set up your community knowledge garden in minutes.'
})

defineRouteRules({
  ssr: true
})
</script>
```

### Runtime Config Setup
```ts
// nuxt.config.ts (additions for Phase 13)
export default defineNuxtConfig({
  // ... existing config from Phase 12
  runtimeConfig: {
    coolifyApiUrl: 'https://coolify.localnodes.xyz/api/v1',
    coolifyApiToken: '',  // Set via NUXT_COOLIFY_API_TOKEN
  },
  routeRules: {
    '/': { prerender: true },
    '/onboarding': { ssr: true }
  }
})
```

### Server Route with Valibot Validation
```ts
// server/api/check-subdomain.get.ts
import * as v from 'valibot'

const querySchema = v.object({
  slug: v.pipe(
    v.string(),
    v.minLength(3, 'Subdomain must be at least 3 characters'),
    v.maxLength(63, 'Subdomain must be at most 63 characters'),
    v.regex(/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/, 'Invalid subdomain format')
  )
})

const RESERVED = ['www', 'api', 'coolify', 'mail', 'smtp', 'admin', 'app',
                   'dashboard', 'status', 'billing', 'support', 'help', 'docs']

export default defineEventHandler(async (event) => {
  const { slug } = await getValidatedQuery(event, input => v.parse(querySchema, input))

  if (RESERVED.includes(slug)) {
    return { available: false, reason: 'This name is reserved' }
  }

  const config = useRuntimeConfig(event)
  const fqdn = `${slug}.localnodes.xyz`

  try {
    const apps = await $fetch<any[]>(`${config.coolifyApiUrl}/applications`, {
      headers: {
        Authorization: `Bearer ${config.coolifyApiToken}`,
        Accept: 'application/json'
      }
    })

    const taken = apps.some(app => {
      const domains = typeof app.docker_compose_domains === 'string'
        ? app.docker_compose_domains
        : JSON.stringify(app.docker_compose_domains || '')
      return domains.includes(fqdn)
    })

    return { available: !taken, reason: taken ? 'This subdomain is already in use' : null }
  } catch (error) {
    console.error('Coolify API error:', error)
    throw createError({ status: 502, statusText: 'Could not verify availability' })
  }
})
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Zod for all validation | Valibot for client-side, Zod for complex server schemas | 2025 (Valibot v1 stable) | 90% bundle reduction for client validation |
| VeeValidate + Yup | UForm built-in validation with Standard Schema | Nuxt UI v4 (Sept 2025) | No extra form library needed |
| Custom form state management | UForm dirty/touched/blurred tracking | Nuxt UI v4 (Sept 2025) | Built-in state tracking |
| UFormGroup (v2/v3 name) | UFormField (v4 name) | Nuxt UI v4 (Sept 2025) | Component renamed in migration |
| `readBody` + manual validation | `readValidatedBody` / `getValidatedQuery` with schema | h3 v1.8+ | Type-safe, automatic 400 errors |
| lodash.debounce | VueUse refDebounced | VueUse 10+ | Vue-reactive, auto-cleanup |

**Deprecated/outdated:**
- `UFormGroup`: renamed to `UFormField` in Nuxt UI v4
- `v-model.nullify`: renamed to `v-model.nullable` in Nuxt UI v4
- VeeValidate for Nuxt UI forms: unnecessary since UForm handles validation natively
- `statusCode` / `statusMessage` in h3: deprecated in favor of `status` / `statusText` (prep for h3 v2)

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Vitest (not yet configured) |
| Config file | none -- see Wave 0 |
| Quick run command | `npx vitest run --reporter=verbose` |
| Full suite command | `npx vitest run` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| ONBD-01 | Form renders 3 fields, validates required fields | unit | `npx vitest run tests/unit/onboarding-form.test.ts -t "form fields"` | No -- Wave 0 |
| ONBD-02 | Subdomain preview updates reactively from community name | unit | `npx vitest run tests/unit/subdomain-preview.test.ts` | No -- Wave 0 |
| ONBD-03 | Availability check fires after debounce, shows correct status | unit | `npx vitest run tests/unit/use-subdomain.test.ts` | No -- Wave 0 |
| ONBD-04 | Slugify produces valid DNS labels from various inputs | unit | `npx vitest run tests/unit/slugify.test.ts` | No -- Wave 0 |
| ERR-03 | Invalid/taken names show validation errors | unit | `npx vitest run tests/unit/onboarding-form.test.ts -t "validation errors"` | No -- Wave 0 |

### Sampling Rate
- **Per task commit:** `npx vitest run tests/unit/slugify.test.ts` (fastest, pure function)
- **Per wave merge:** `npx vitest run`
- **Phase gate:** Full suite green before `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `vitest.config.ts` -- Vitest configuration for Nuxt project
- [ ] `@nuxt/test-utils` -- Testing utilities for Nuxt components
- [ ] `tests/unit/slugify.test.ts` -- covers ONBD-04 (pure function, easiest to test)
- [ ] `tests/unit/use-subdomain.test.ts` -- covers ONBD-02, ONBD-03 (composable logic)
- [ ] `tests/unit/onboarding-form.test.ts` -- covers ONBD-01, ERR-03 (component rendering)

## Open Questions

1. **Form submission target in Phase 13**
   - What we know: Phase 14 handles Stripe Checkout. The form submit in Phase 13 needs to go somewhere.
   - What's unclear: Should the form navigate to a confirmation page, or directly create a Stripe Checkout session?
   - Recommendation: For Phase 13 scope, the submit handler should store form data in a composable/state and navigate to `/onboarding/review` or similar. Phase 14 will add the Stripe integration. For now, the submit can just `navigateTo('/onboarding/confirm')` with a placeholder page.

2. **Rate limiting the availability check API**
   - What we know: The Coolify API is on a single server. Hammering it with availability checks could be problematic.
   - What's unclear: Whether Coolify has its own rate limiting.
   - Recommendation: The 500ms debounce provides client-side protection. Server-side rate limiting (via Upstash Redis) is planned for later phases but not needed for Phase 13 MVP.

3. **Handling Coolify API being down**
   - What we know: If Coolify is unreachable, the availability check fails.
   - What's unclear: Whether to allow form submission with an unverified subdomain.
   - Recommendation: Show a warning ("Could not verify availability") but do NOT block form submission. The provisioning workflow (Phase 15) will catch conflicts. Better UX than blocking signup.

## Sources

### Primary (HIGH confidence)
- [Nuxt UI v4 Form Component](https://ui.nuxt.com/docs/components/form) - UForm props, validation, schema support, exposed methods, state tracking
- [Nuxt UI v4 FormField Component](https://ui.nuxt.com/docs/components/form-field) - UFormField props, slots, error integration
- [Nuxt UI v4 Input Component](https://ui.nuxt.com/docs/components/input) - UInput props, slots, type support, trailing/leading slots
- [Nuxt UI v4 AuthForm Component](https://ui.nuxt.com/docs/components/auth-form) - AuthForm field configuration (evaluated, not recommended)
- [Nuxt 4 Server Directory](https://nuxt.com/docs/4.x/directory-structure/server) - Nitro server routes, file-based routing, validation
- [Nuxt 4 Runtime Config](https://nuxt.com/docs/4.x/guide/going-further/runtime-config) - Private/public config, env var mapping
- [VueUse refDebounced](https://vueuse.org/shared/refdebounced/) - Debounced ref composable
- [Valibot Comparison](https://valibot.dev/guides/comparison/) - Bundle size comparison with Zod
- [Coolify API List Applications](https://coolify.io/docs/api-reference/api/operations/list-applications) - Endpoint for checking existing apps

### Secondary (MEDIUM confidence)
- [Zod vs Valibot vs ArkType 2026 Comparison](https://pockit.tools/blog/zod-valibot-arktype-comparison-2026/) - Bundle size benchmarks, runtime performance
- [Nuxt UI v4 Form System DeepWiki](https://deepwiki.com/nuxt/ui/4.2-form-system) - Internal form architecture
- [h3 Validate Data](https://v1.h3.dev/examples/validate-data) - `readValidatedBody`, `getValidatedQuery` patterns

### Tertiary (LOW confidence)
- Coolify API internal `validateDomains()` behavior (referenced in DeepWiki but not in official API docs)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Nuxt UI v4 form system verified via official docs, Valibot verified as first-class citizen
- Architecture: HIGH - Patterns verified against Nuxt 4 server directory docs, existing provision workflow confirms Coolify API structure
- Pitfalls: HIGH - Form validation timing, debounce patterns, and SSR gotchas well-documented
- Server routes: HIGH - Nitro validation utilities confirmed in official docs, Coolify API response format confirmed from existing workflow code

**Research date:** 2026-03-04
**Valid until:** 2026-04-04 (stable ecosystem, 30-day validity)
