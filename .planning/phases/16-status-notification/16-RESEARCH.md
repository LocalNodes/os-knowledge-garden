# Phase 16: Status & Notification - Research

**Researched:** 2026-03-04
**Domain:** Nuxt 4 polling composable, VueUse utilities, NuxtUI components, CSS animation, Resend email (from GitHub Actions)
**Confidence:** HIGH

## Summary

Phase 16 transforms the placeholder success page (`app/pages/success.vue`) into a live progress dashboard and ensures the welcome email is sent with everything an organizer needs. All backend infrastructure is already in place from Phase 15: the Redis state machine (`provision:${session_id}` hash), the `GET /api/provision-status` endpoint, the `POST /api/provision-callback` endpoint, and the GitHub Actions workflow steps that post status callbacks at each stage. Phase 16 is entirely frontend-focused on the Nuxt side, with the email content a concern for the GitHub Actions workflow YAML.

The frontend polling pattern uses VueUse's `useTimeoutPoll` (already installed via `@vueuse/core`) to call `/api/provision-status?session_id=XXX` every 3 seconds. The `session_id` is available as a URL query parameter on the `/success` page (Stripe redirects with `?session_id=XXX`). The provisioning state machine already defines seven stages: `triggered -> provisioning -> installing -> creating_user -> sending_email -> complete -> failed`. The progress UI maps these to named human-readable stages for the organizer.

The animated "garden growing" visualization follows the CSS-only pattern established in `HeroSection.vue` — no external animation libraries, using Tailwind CSS keyframes (`animate-pulse`, `animate-spin`, `animate-[...]`), SVG geometry, and `v-motion` from the already-installed `@vueuse/motion@3.0.3`. The countdown timer uses VueUse's `useCountdown` (confirmed available in `@vueuse/core@14.2.1`). The success state transitions to a different view showing the site URL and login CTA once `status === 'complete'`.

**Primary recommendation:** Build a single composable `useProvisioningStatus(sessionId)` that drives the entire polling lifecycle — expose `status`, `currentStage`, `stagesComplete`, `timeElapsed`, `siteUrl`, `loginUrl`, and `isDone`. The success page uses this composable to conditionally render a waiting state (progress + animation) or a completion state (site URL + CTA).

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| STAT-01 | User sees multi-step progress indicator with named stages during provisioning | Redis state machine has 7 stages; map to 4 named human-facing stages in composable; use NuxtUI `UStepper` or custom step indicator |
| STAT-02 | User sees animated "garden growing" visualization during the ~4 minute wait | CSS animation pattern from HeroSection.vue; Tailwind keyframes + SVG + v-motion; no additional packages needed |
| STAT-03 | User sees estimated time remaining (~4 minutes) | VueUse `useCountdown` from already-installed `@vueuse/core@14.2.1`; start at 240s, tick every second |
| STAT-04 | User sees success page with site URL and "Visit Your Garden" CTA | Conditional render in success.vue when status=complete; siteUrl and loginUrl from Redis via provision-status endpoint |
| NOTIF-01 | User receives welcome email when their instance is ready | Already wired in GitHub Actions workflow (Phase 15) — sending_email stage + Resend API call |
| NOTIF-02 | Welcome email contains site URL, one-time login link, and getting-started steps | GitHub Actions welcome email HTML needs getting-started content added; Phase 15 has basic HTML |
</phase_requirements>

## Standard Stack

### Core (already installed — no new packages needed)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| @vueuse/core | 14.2.1 | `useTimeoutPoll` for polling, `useCountdown` for timer | Already installed as transitive dep of `@vueuse/motion`. Both functions confirmed in dist/index.d.ts |
| @vueuse/motion | 3.0.3 | `v-motion` directive for enter animations on stage transitions | Already installed, already used in HeroSection.vue. No new install. |
| @nuxt/ui | 4.5.1 | `UButton`, `UBadge`, `UIcon`, `UPageSection`, layout primitives | Already installed. Provides all UI primitives needed. |
| Tailwind CSS | bundled with @nuxt/ui | CSS animation keyframes (`animate-pulse`, `animate-spin`, `animate-[...]`) | Already configured via @nuxt/ui. All animations via Tailwind. |

### No New Dependencies
All capabilities needed for Phase 16 are already installed. Do NOT add new animation libraries, state management packages, or UI component libraries.

**Installation:** None required.

## Architecture Patterns

### Recommended Project Structure (Phase 16 additions)
```
localnodes-onboarding/
├── app/
│   ├── composables/
│   │   └── useProvisioningStatus.ts     # NEW: polling + state machine
│   ├── components/
│   │   ├── GardenAnimation.vue          # NEW: animated garden visualization
│   │   ├── ProvisioningProgress.vue     # NEW: multi-step stage indicator
│   │   └── ProvisioningComplete.vue     # NEW: success state (site URL + CTA)
│   └── pages/
│       └── success.vue                  # MODIFIED: uses composable + components
├── tests/unit/
│   └── use-provisioning-status.test.ts  # NEW: polling composable tests
```

### Pattern 1: Polling Composable with useTimeoutPoll
**What:** A composable that polls the status endpoint every 3 seconds, stops when terminal state is reached, and derives display data from raw status.
**When to use:** Mounted on the success page when a `session_id` is present in the URL.
**Source:** `useTimeoutPoll` signature: `useTimeoutPoll(fn: () => Awaitable<void>, interval: MaybeRefOrGetter<number>, options?: UseTimeoutFnOptions): Pausable`

```typescript
// app/composables/useProvisioningStatus.ts
import { useTimeoutPoll } from '@vueuse/core'
import { ref, computed } from 'vue'

// Maps raw Redis status to human-facing stage names
// State machine: triggered -> provisioning -> installing -> creating_user -> sending_email -> complete -> failed
const STAGES = [
  { id: 'triggered',      label: 'Payment confirmed',           statuses: ['triggered'] },
  { id: 'provisioning',   label: 'Setting up your server',      statuses: ['provisioning'] },
  { id: 'installing',     label: 'Installing your garden',      statuses: ['installing', 'creating_user', 'sending_email'] },
  { id: 'complete',       label: 'Your garden is ready!',       statuses: ['complete'] }
] as const

type RawStatus = 'triggered' | 'provisioning' | 'installing' | 'creating_user' | 'sending_email' | 'complete' | 'failed' | 'unknown'

export function useProvisioningStatus(sessionId: string | undefined) {
  const status = ref<RawStatus>('unknown')
  const siteUrl = ref<string | null>(null)
  const loginUrl = ref<string | null>(null)
  const error = ref<string | null>(null)
  const startedAt = ref<string | null>(null)

  const TERMINAL_STATES = ['complete', 'failed'] as const

  const isTerminal = computed(() => TERMINAL_STATES.includes(status.value as any))
  const isComplete = computed(() => status.value === 'complete')
  const isFailed = computed(() => status.value === 'failed')

  // Current stage index (0-3) based on raw status
  const currentStageIndex = computed(() => {
    if (status.value === 'failed') return -1
    const idx = STAGES.findIndex(s => (s.statuses as readonly string[]).includes(status.value))
    return idx === -1 ? 0 : idx
  })

  const stages = computed(() => STAGES.map((s, i) => ({
    ...s,
    state: i < currentStageIndex.value ? 'completed' : i === currentStageIndex.value ? 'active' : 'pending'
  })))

  const { pause } = useTimeoutPoll(async () => {
    if (!sessionId || isTerminal.value) {
      pause()
      return
    }
    try {
      const data = await $fetch<{
        status: RawStatus
        siteUrl?: string | null
        loginUrl?: string | null
        error?: string | null
        startedAt?: string
      }>('/api/provision-status', { query: { session_id: sessionId } })

      status.value = data.status
      if (data.siteUrl) siteUrl.value = data.siteUrl
      if (data.loginUrl) loginUrl.value = data.loginUrl
      if (data.error) error.value = data.error
      if (data.startedAt) startedAt.value = data.startedAt

      if (TERMINAL_STATES.includes(data.status as any)) {
        pause()
      }
    } catch {
      // Network error: don't change status, retry on next tick
    }
  }, 3000, { immediate: true })

  return {
    status,
    stages,
    currentStageIndex,
    isComplete,
    isFailed,
    siteUrl,
    loginUrl,
    error,
    startedAt,
    pause
  }
}
```

### Pattern 2: useCountdown for Estimated Time Remaining
**What:** Count down from 240 seconds (4 minutes) using VueUse's `useCountdown`.
**When to use:** Displayed while provisioning is in progress. Reset/stop when complete.
**Source:** `useCountdown(initialCountdown: MaybeRefOrGetter<number>, options?: UseCountdownOptions): UseCountdownReturn` — confirmed in `@vueuse/core@14.2.1`.

```typescript
// In success.vue or useProvisioningStatus.ts
import { useCountdown } from '@vueuse/core'

const PROVISIONING_SECONDS = 240 // 4 minutes

const { count, pause: pauseCountdown } = useCountdown(PROVISIONING_SECONDS)

// Formatted as "3:45"
const timeRemaining = computed(() => {
  const mins = Math.floor(count.value / 60)
  const secs = count.value % 60
  return `${mins}:${secs.toString().padStart(2, '0')}`
})

// Stop countdown when complete
watch(isComplete, (done) => {
  if (done) pauseCountdown()
})
```

### Pattern 3: Animated Garden Visualization (CSS-Only)
**What:** A living botanical illustration using CSS animations, Tailwind keyframes, and SVG — no canvas, no WebGL, no external animation library.
**When to use:** During the provisioning wait in `GardenAnimation.vue`. Follows the exact same approach as `HeroSection.vue`.

```vue
<!-- app/components/GardenAnimation.vue -->
<template>
  <!-- Outer wrapper with v-motion for initial mount animation -->
  <div
    class="relative w-48 h-48 mx-auto"
    v-motion
    :initial="{ opacity: 0, scale: 0.8 }"
    :enter="{ opacity: 1, scale: 1, transition: { duration: 800, type: 'spring', stiffness: 60 } }"
  >
    <!-- Ambient glow: slow-pulsing teal radial gradient -->
    <div
      class="absolute inset-0 rounded-full opacity-30 blur-[40px] animate-[pulse_4s_ease-in-out_infinite]"
      style="background: radial-gradient(circle, rgb(20 184 166) 0%, transparent 70%)"
    />
    <!-- SVG botanical scene -->
    <svg class="absolute inset-0 w-full h-full" viewBox="0 0 192 192" fill="none" xmlns="http://www.w3.org/2000/svg">
      <!-- Ground circle -->
      <circle cx="96" cy="96" r="72" stroke="rgb(20 184 166)" stroke-width="1" opacity="0.2" />
      <!-- Center stem -->
      <line x1="96" y1="140" x2="96" y2="60" stroke="rgb(20 184 166)" stroke-width="2" opacity="0.6" />
      <!-- Leaf left -->
      <path d="M96 90 Q70 80 64 60 Q86 68 96 90Z" fill="rgb(20 184 166)" opacity="0.4"
        class="animate-[pulse_3s_ease-in-out_infinite]" />
      <!-- Leaf right -->
      <path d="M96 90 Q122 80 128 60 Q106 68 96 90Z" fill="rgb(16 185 129)" opacity="0.4"
        class="animate-[pulse_3s_ease-in-out_infinite_0.5s]" style="animation-delay: 0.5s" />
      <!-- Center bloom -->
      <circle cx="96" cy="56" r="10" fill="rgb(20 184 166)" opacity="0.6"
        class="animate-[pulse_2s_ease-in-out_infinite]" />
      <circle cx="96" cy="56" r="5" fill="rgb(245 158 11)" opacity="0.8" />
      <!-- Floating particles -->
      <circle cx="60" cy="80" r="2" fill="rgb(20 184 166)" opacity="0.4"
        class="animate-[ping_2s_cubic-bezier(0,0,0.2,1)_infinite]" />
      <circle cx="132" cy="96" r="1.5" fill="rgb(245 158 11)" opacity="0.4"
        class="animate-[ping_3s_cubic-bezier(0,0,0.2,1)_infinite]" style="animation-delay: 0.8s" />
      <!-- Concentric growth rings -->
      <circle cx="96" cy="96" r="40" stroke="rgb(20 184 166)" stroke-width="0.5" opacity="0.15"
        class="animate-[ping_4s_cubic-bezier(0,0,0.2,1)_infinite]" />
    </svg>
  </div>
</template>
```

### Pattern 4: Multi-Step Progress Indicator
**What:** A vertical or horizontal list of named stages showing completed (checkmark), active (animated ring), and pending (dimmed) states.
**When to use:** In `ProvisioningProgress.vue`, driven by `stages` from the composable.

```vue
<!-- app/components/ProvisioningProgress.vue -->
<script setup lang="ts">
defineProps<{
  stages: Array<{ id: string; label: string; state: 'completed' | 'active' | 'pending' }>
}>()
</script>

<template>
  <ol class="space-y-3">
    <li
      v-for="(stage, i) in stages"
      :key="stage.id"
      class="flex items-center gap-3"
      v-motion
      :initial="{ opacity: 0, x: -10 }"
      :enter="{ opacity: 1, x: 0, transition: { duration: 400, delay: i * 100 } }"
    >
      <!-- State indicator -->
      <div class="flex-none w-6 h-6 rounded-full flex items-center justify-center">
        <UIcon
          v-if="stage.state === 'completed'"
          name="i-lucide-check-circle"
          class="text-primary-500 w-6 h-6"
        />
        <div
          v-else-if="stage.state === 'active'"
          class="w-5 h-5 rounded-full border-2 border-primary-500 border-t-transparent animate-spin"
        />
        <div
          v-else
          class="w-5 h-5 rounded-full border-2 border-(--ui-border)"
        />
      </div>
      <!-- Label -->
      <span
        :class="[
          'text-sm',
          stage.state === 'completed' ? 'text-primary-400 line-through' : '',
          stage.state === 'active' ? 'text-white font-medium' : '',
          stage.state === 'pending' ? 'text-(--ui-text-muted)' : ''
        ]"
      >{{ stage.label }}</span>
    </li>
  </ol>
</template>
```

### Pattern 5: Success State with Visit Your Garden CTA
**What:** When `isComplete` becomes true, replace the progress UI with the site URL and a prominent button.
**When to use:** Conditional render in `success.vue` when `status === 'complete'`.

```vue
<!-- In success.vue, completion section -->
<template>
  <div v-if="isComplete" v-motion :initial="{ opacity: 0, y: 20 }" :enter="{ opacity: 1, y: 0 }">
    <UIcon name="i-lucide-sprout" class="text-primary-500 mb-6 mx-auto" size="64" />
    <h1 class="text-2xl font-bold mb-2">Your garden is ready!</h1>
    <p class="text-(--ui-text-muted) mb-4">
      Your knowledge garden at
      <a :href="siteUrl" class="text-primary-400 underline">{{ siteUrl }}</a>
      is live.
    </p>
    <p class="text-sm text-(--ui-text-muted) mb-8">
      Check your email for a login link to set your password.
    </p>
    <div class="flex flex-col sm:flex-row gap-3 justify-center">
      <UButton :to="siteUrl" target="_blank" size="xl" color="primary">
        Visit Your Garden
      </UButton>
      <UButton :href="loginUrl" target="_blank" size="xl" variant="outline">
        Set Your Password
      </UButton>
    </div>
  </div>
</template>
```

### Pattern 6: Complete Success Page (success.vue)
**What:** The unified page that switches between waiting and complete states.
**When to use:** Replace the Phase 14 placeholder.

```vue
<!-- app/pages/success.vue -->
<script setup lang="ts">
const route = useRoute()
const sessionId = route.query.session_id as string | undefined

const {
  status,
  stages,
  isComplete,
  isFailed,
  siteUrl,
  loginUrl,
  error
} = useProvisioningStatus(sessionId)

const { count, pause: pauseCountdown } = useCountdown(240)
watch(isComplete, done => { if (done) pauseCountdown() })

const timeRemaining = computed(() => {
  const m = Math.floor(count.value / 60)
  const s = count.value % 60
  return `${m}:${s.toString().padStart(2, '0')}`
})

useSeoMeta({
  title: 'Setting Up Your Garden - LocalNodes',
  description: 'Your knowledge garden is being prepared. This takes about 4 minutes.'
})
</script>

<template>
  <UPageSection>
    <div class="max-w-md mx-auto text-center">

      <!-- WAITING STATE -->
      <template v-if="!isComplete && !isFailed">
        <GardenAnimation class="mb-8" />
        <h1 class="text-2xl font-bold mb-2">Growing your garden...</h1>
        <p class="text-(--ui-text-muted) mb-2">
          This usually takes about 4 minutes.
        </p>
        <p class="text-xl font-mono text-primary-400 mb-8">{{ timeRemaining }}</p>
        <ProvisioningProgress :stages="stages" class="text-left mb-8" />
        <p class="text-xs text-(--ui-text-muted)">
          You'll receive a welcome email when everything is ready.
        </p>
      </template>

      <!-- SUCCESS STATE -->
      <ProvisioningComplete v-else-if="isComplete" :site-url="siteUrl!" :login-url="loginUrl" />

      <!-- FAILURE STATE (Phase 17 will expand this) -->
      <template v-else-if="isFailed">
        <UIcon name="i-lucide-alert-circle" class="text-red-500 mb-6 mx-auto" size="64" />
        <h1 class="text-2xl font-bold mb-2">Something went wrong</h1>
        <p class="text-(--ui-text-muted) mb-8">
          {{ error || 'Provisioning failed. Please contact support.' }}
        </p>
        <UButton to="/" variant="outline">Back to Home</UButton>
      </template>

      <!-- NO SESSION STATE -->
      <template v-else-if="!sessionId">
        <UButton to="/" variant="outline">Back to Home</UButton>
      </template>

    </div>
  </UPageSection>
</template>
```

### Pattern 7: Welcome Email Getting-Started Content (GitHub Actions)
**What:** Enhance the Phase 15 welcome email HTML with structured getting-started steps.
**When to use:** Modify the "Send welcome email" step in `provision-instance.yml`.

The Phase 15 workflow already sends a basic HTML email. Phase 16 enriches the HTML body with a getting-started checklist:

```html
<!-- Enhanced email body (inline in GitHub Actions curl -d) -->
<h1>Your knowledge garden is ready! 🌱</h1>
<p>Your community's knowledge garden at
  <a href="https://FQDN">https://FQDN</a> is live and waiting.</p>

<h2>Getting started</h2>
<ol>
  <li><strong>Set your password</strong> — Click the link below (single-use, works once).</li>
  <li><strong>Explore your garden</strong> — Visit Groups, Events, and Topics to see the demo content.</li>
  <li><strong>Invite your community</strong> — Use the Groups section to add members.</li>
  <li><strong>Try the AI assistant</strong> — Ask the chat widget anything about your community's content.</li>
</ol>

<p>
  <a href="LOGIN_URL" style="background:#14b8a6;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;display:inline-block;">
    Set Password &amp; Log In
  </a>
</p>

<p style="color:#888;font-size:0.85em;">
  This link is single-use and will expire. If it expires, visit https://FQDN/user/password to request a new one.
</p>
<p>Happy gardening,<br>The LocalNodes Team</p>
```

### Anti-Patterns to Avoid
- **SSE or WebSockets:** STAT-05 (real-time SSE) is explicitly deferred to v2.1. Use polling (3s interval). The architecture decision is locked.
- **Canvas or WebGL animation:** Use CSS animations and SVG only. The hero uses this pattern; maintain consistency.
- **External animation libraries:** `animate.css`, `gsap`, `animejs` etc. are not needed. `v-motion` + Tailwind keyframes cover everything.
- **Eager polling before session_id is available:** Only start polling if `sessionId` is truthy. Guard in composable.
- **Polling after terminal state:** Call `pause()` immediately on `complete` or `failed`. Don't let intervals accumulate.
- **Hard-coding "4 minutes":** Display a countdown so users feel time is passing. The `useCountdown` approach is better than static text.
- **NuxtUI UStepper:** Nuxt UI 4.x does not include a `UStepper` component — use a custom `<ol>` with conditional icons as shown in Pattern 4. Do NOT attempt `<UStepper>`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Async polling with cleanup | Custom `setInterval` + `onUnmounted` | `useTimeoutPoll` from @vueuse/core | Handles cleanup, pause/resume, immediate first-call, correct timeout-based (not interval-based) behavior |
| Countdown timer | Custom ref + setInterval | `useCountdown` from @vueuse/core | Already installed, handles pause/resume, reactive count ref |
| Enter animations | Manual CSS class toggling or custom transition hooks | `v-motion` directive | Already installed, proven in HeroSection.vue |
| Status polling response types | Manual fetch with type assertions | `$fetch<T>` with typed generic | Nuxt's $fetch handles errors, JSON parsing, TypeScript inference |
| Email HTML | External email templating service | Inline HTML in GitHub Actions curl command | Resend handles delivery, no template engine needed at this stage |

**Key insight:** All animation, polling, and countdown capabilities are already in the installed dependency tree. Zero new packages are required for Phase 16.

## Common Pitfalls

### Pitfall 1: session_id Not in URL on Success Page
**What goes wrong:** `route.query.session_id` is undefined; polling calls `/api/provision-status` without a session_id; the endpoint throws a 400 error on every poll.
**Why it happens:** Stripe only includes `{CHECKOUT_SESSION_ID}` in the redirect URL if the checkout session was created with `success_url` containing `?session_id={CHECKOUT_SESSION_ID}`. If Phase 14 omitted this placeholder, it won't be there.
**How to avoid:** Verify `create-checkout.post.ts` passes `success_url: 'https://localnodes.xyz/success?session_id={CHECKOUT_SESSION_ID}'`. Guard the composable: if `!sessionId`, don't start polling.
**Warning signs:** `status` stays as `'unknown'` on every poll despite provisioning being active.

### Pitfall 2: useCountdown Signature
**What goes wrong:** `useCountdown` is called incorrectly, starting at 0 or not ticking.
**Why it happens:** `useCountdown` takes an `initialCountdown` number (in seconds) and counts down. Unlike `useInterval`, it is NOT a general timer — it counts from N to 0.
**How to avoid:** Use `useCountdown(240)` for 4 minutes. The `count` ref starts at 240 and decrements. If the provision completes before 0, call `pause()` to stop.
**Warning signs:** Timer shows "NaN:NaN" or stays at "4:00" without ticking.

### Pitfall 3: Polling Continues After Terminal State
**What goes wrong:** The page keeps calling `/api/provision-status` every 3 seconds even after status is `'complete'` or `'failed'`, generating unnecessary API calls.
**Why it happens:** Forgetting to call `pause()` on the Pausable returned by `useTimeoutPoll` when a terminal state is detected.
**How to avoid:** Inside the poll function, call `pause()` immediately when `TERMINAL_STATES.includes(data.status)`. Also call `pause()` if `!sessionId`.
**Warning signs:** Network tab shows continuous requests to `/api/provision-status` after the success state renders.

### Pitfall 4: v-motion on Conditionally Rendered Elements
**What goes wrong:** The success state transition is jarring — no enter animation on `ProvisioningComplete`.
**Why it happens:** `v-if` removes and re-creates the DOM element; `v-motion` enter animation only fires on mount. If the component is initially hidden and revealed via `v-if`, the animation must be on the element itself.
**How to avoid:** Put `v-motion` with `:initial` and `:enter` directly on the root element of `ProvisioningComplete.vue` or the `<template>` wrapper that contains the success content. Transitions fire correctly because the element mounts fresh when `v-if` becomes true.
**Warning signs:** Success section appears instantly without animation.

### Pitfall 5: useTimeoutPoll vs useIntervalFn
**What goes wrong:** Using `useIntervalFn` (if it were available) causes overlapping requests if a poll takes longer than the interval.
**Why it happens:** `useIntervalFn` fires on a fixed clock regardless of whether the previous async call has resolved.
**How to avoid:** `useTimeoutPoll` reschedules the next call only AFTER the previous one resolves — correct behavior for async API polling. This is why it's preferred over `setInterval`.
**Warning signs:** Multiple simultaneous requests to `/api/provision-status` visible in the network tab.

### Pitfall 6: Welcome Email Line Breaks in GitHub Actions Shell
**What goes wrong:** Multi-line HTML string in the GitHub Actions `run:` block causes shell parsing errors (unmatched quotes, unexpected tokens).
**Why it happens:** The email HTML is passed as a JSON string inside a shell heredoc or inline string. Newlines and special characters break shell string parsing.
**How to avoid:** Either (a) escape newlines and use a single-line JSON string, or (b) write the HTML to a temp file first and use `@filename` in curl. For Phase 16, keep the HTML compact and escape all double quotes within the JSON body.
**Warning signs:** GitHub Actions step fails with shell syntax errors, not Resend API errors.

### Pitfall 7: NuxtUI UStepper Does Not Exist
**What goes wrong:** The planner or executor tries to use `<UStepper>` and gets a component-not-found error at runtime.
**Why it happens:** NuxtUI 4.x does not include a stepper/wizard component. The docs do not list one.
**How to avoid:** Build the stage indicator as a custom `<ol>` list with `UIcon` for state icons (confirmed available: `i-lucide-check-circle`, `i-lucide-circle`). See Pattern 4 above.
**Warning signs:** Vue warns "Unknown component: UStepper".

## Code Examples

### Verified: useTimeoutPoll Import Pattern
```typescript
// Confirmed available in @vueuse/core@14.2.1
import { useTimeoutPoll } from '@vueuse/core'

// Already used as transitive dep via @vueuse/motion
// No new install needed
```

### Verified: useCountdown Import Pattern
```typescript
// Confirmed in @vueuse/core/dist/index.d.ts:
// declare function useCountdown(initialCountdown: MaybeRefOrGetter<number>, options?: UseCountdownOptions): UseCountdownReturn;
import { useCountdown } from '@vueuse/core'

const { count, pause, resume, reset } = useCountdown(240)
```

### Verified: v-motion Directive Pattern (from HeroSection.vue)
```vue
<!-- Direct v-motion usage — confirmed working in this codebase -->
<div
  v-motion
  :initial="{ opacity: 0, y: 20 }"
  :enter="{ opacity: 1, y: 0, transition: { duration: 800 } }"
>
  content
</div>

<!-- Spring animation (also used in HeroSection.vue) -->
<div
  v-motion
  :initial="{ opacity: 0, scale: 0.9 }"
  :enter="{ opacity: 1, scale: 1, transition: { duration: 1200, type: 'spring', stiffness: 50 } }"
>
  content
</div>
```

### Verified: CSS Animation Keyframes (from HeroSection.vue)
```html
<!-- Tailwind animate-pulse for ambient glow -->
<div class="animate-[pulse_6s_ease-in-out_infinite]" ... />

<!-- Tailwind animate-spin for rotation -->
<div class="animate-[spin_20s_linear_infinite]" ... />

<!-- Tailwind animate-ping for particles -->
<circle class="animate-[ping_2s_cubic-bezier(0,0,0.2,1)_infinite]" ... />

<!-- Animation delay via style attribute (Tailwind doesn't support arbitrary delay classes) -->
<div class="animate-pulse" style="animation-delay: 1s" />
```

### Verified: $fetch with Query Params (from useSubdomain.ts)
```typescript
// Pattern already used in this codebase
const result = await $fetch<{ available: boolean; reason: string | null }>('/api/check-subdomain', {
  query: { slug: newSlug }
})

// Phase 16 adaptation:
const data = await $fetch<{ status: RawStatus; siteUrl?: string | null }>('/api/provision-status', {
  query: { session_id: sessionId }
})
```

### Verified: Success Page session_id Access (from current success.vue)
```typescript
// Already done in success.vue — Phase 16 expands on this
const route = useRoute()
const sessionId = route.query.session_id as string | undefined
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| SSE / WebSockets for real-time updates | Polling every 3s | Architecture decision (Phase 15) | Simpler, works with Vercel serverless, no persistent connections needed |
| Generic "please wait" page | Named stages with live progress | Phase 16 | Reduces organizer anxiety during 4-min wait |
| Canvas/WebGL for loading animations | CSS-only animations + SVG | Codebase convention (HeroSection.vue) | Zero bundle overhead, consistent art style |
| vue-countdown / custom timer component | VueUse useCountdown | Confirmed available in @vueuse/core 14.2.1 | No new dependency, same ecosystem |

**Deprecated/outdated in this context:**
- `useIntervalFn`: Would work, but `useTimeoutPoll` is safer for async polling (prevents overlap).
- `UStepper` from NuxtUI: Component does not exist in NuxtUI 4.x. Use custom list.

## Open Questions

1. **Is the success_url in create-checkout.post.ts already templated with session_id?**
   - What we know: Phase 14 created the checkout session. The `session_id` is available in `route.query.session_id` as shown in the existing `success.vue` comment referencing it.
   - What's unclear: Whether `success_url` was set to `https://localnodes.xyz/success?session_id={CHECKOUT_SESSION_ID}` or just `https://localnodes.xyz/success`.
   - Recommendation: The planner should include a Wave 0 task to verify `create-checkout.post.ts` has the Stripe session ID template variable in `success_url`. If missing, add it.

2. **Should useProvisioningStatus be in app/composables/ or app/utils/?**
   - What we know: VueUse composables go in `app/composables/` (Nuxt auto-import from this directory). Utility functions that don't use Vue reactivity go in `app/utils/`. The `useSubdomain.ts` composable is in `app/composables/`.
   - Recommendation: `app/composables/useProvisioningStatus.ts` — it uses `ref`, `computed`, `watch`, and `useTimeoutPoll` (all reactive).

3. **What polling interval is right — 3s or 5s?**
   - What we know: Provisioning takes ~4 minutes (240 seconds). With 3s polling that's ~80 requests; with 5s that's ~48 requests. Upstash Redis is HTTP-based and handles this fine. Stage transitions happen at coarse intervals (Coolify deploy, Drupal install, user creation) — precision under 3s adds no UX value.
   - Recommendation: 3 seconds. Fast enough to feel responsive, cheap enough at Upstash's free tier. Can be adjusted to 5s if cost becomes a concern.

4. **GitHub Actions welcome email: inline HTML or separate file?**
   - What we know: The Phase 15 workflow already sends a curl-based Resend API call with inline HTML. The shell escaping works for basic HTML.
   - Recommendation: Enhance the existing inline HTML in place. Adding an `<ol>` with getting-started steps is straightforward. Keep it single-file (no external template).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Vitest 3.2.4 (already configured from Phase 13) |
| Config file | `vitest.config.ts` (exists at project root) |
| Quick run command | `npx vitest run tests/unit/use-provisioning-status.test.ts` |
| Full suite command | `npx vitest run` |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| STAT-01 | `useProvisioningStatus` maps raw status to named stages correctly | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — Wave 0 |
| STAT-01 | Stage `installing` covers statuses: installing, creating_user, sending_email | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — Wave 0 |
| STAT-02 | GardenAnimation renders without errors | manual-only | Visual inspection in browser | N/A |
| STAT-03 | `useCountdown(240)` ticks from 240 to 0 correctly | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — Wave 0 |
| STAT-04 | `isComplete` is true when status=complete; siteUrl and loginUrl propagated | unit | `npx vitest run tests/unit/use-provisioning-status.test.ts` | No — Wave 0 |
| STAT-04 | Visit Your Garden CTA renders with correct href | manual-only | Visual inspection after E2E | N/A |
| NOTIF-01 | Welcome email is sent (Resend API called from GitHub Actions) | manual-only | Trigger provisioning and verify email received | N/A |
| NOTIF-02 | Email contains site URL, login link, and getting-started steps | manual-only | Read received email and verify content | N/A |

### Sampling Rate
- **Per task commit:** `npx vitest run tests/unit/use-provisioning-status.test.ts`
- **Per wave merge:** `npx vitest run`
- **Phase gate:** Full suite green + manual browser verification of progress UI + welcome email content check

### Wave 0 Gaps
- [ ] `tests/unit/use-provisioning-status.test.ts` — covers STAT-01 (stage mapping), STAT-03 (countdown integration), STAT-04 (completion detection)

None — existing test infrastructure (Vitest config, existing test files) covers the setup.

## Sources

### Primary (HIGH confidence)
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/node_modules/@vueuse/core/dist/index.d.ts` — confirmed `useTimeoutPoll`, `useCountdown`, `refDebounced` exports in @vueuse/core@14.2.1
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/components/HeroSection.vue` — verified `v-motion` directive usage pattern, CSS animation keyframes, SVG geometric art approach
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/composables/useSubdomain.ts` — verified `$fetch` polling pattern with query params
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/pages/success.vue` — current state, `route.query.session_id` access
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/server/utils/provision-handlers.ts` — confirmed `handleProvisionStatus` response shape
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/server/utils/redis.ts` — confirmed `ProvisioningState` interface with all status values
- `/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/package.json` — confirmed installed packages: @vueuse/core 14.2.1, @vueuse/motion 3.0.3, @nuxt/ui 4.5.1
- `.planning/phases/15-provisioning-pipeline/15-RESEARCH.md` — confirmed state machine stages and callback endpoint contract

### Secondary (MEDIUM confidence)
- VueUse docs (useTimeoutPoll): https://vueuse.org/core/useTimeoutPoll/ — behavior described matches type signature in installed package
- VueUse docs (useCountdown): https://vueuse.org/core/useCountdown/ — confirmed it counts down from initial value, returns Pausable
- @vueuse/motion docs: https://motion.vueuse.org/ — v-motion directive, `:initial`, `:enter`, `transition` options

### Tertiary (LOW confidence — not verified against NuxtUI 4.x docs)
- NuxtUI 4.x component list: `UStepper` is NOT in the component list — inferred from lack of mention in NuxtUI 4.x docs, but not exhaustively checked via official docs URL

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all packages confirmed installed via package.json + node_modules inspection, type signatures confirmed via dist/index.d.ts
- Architecture: HIGH — follows established patterns from HeroSection.vue and useSubdomain.ts; polling endpoint contract is already built and tested
- Pitfalls: HIGH — most derived from confirmed code patterns in the codebase (v-motion entry, $fetch usage, Tailwind animation delays)
- Email content: MEDIUM — Resend delivery is proven (Phase 15); HTML content enhancement is straightforward but untested

**Research date:** 2026-03-04
**Valid until:** 2026-04-04 (VueUse is stable, @nuxt/ui 4.x is the current major)
