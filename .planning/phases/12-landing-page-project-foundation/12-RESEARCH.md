# Phase 12: Landing Page & Project Foundation - Research

**Researched:** 2026-03-03
**Domain:** Nuxt 4 + Nuxt UI v4 landing page, Vercel deployment, Resend DNS configuration
**Confidence:** HIGH

## Summary

Phase 12 is a greenfield Nuxt 4 project that replaces the existing static landing page at localnodes.xyz. The Nuxt 4 ecosystem is mature and stable (v4.3 released January 2026, with v4.0 stable since July 2025). Nuxt UI v4 provides all the components needed for a landing page out of the box -- PageHero, PageSection, PricingPlan, PageCTA, PageLogos, and more -- all completely free and open-source since v4 merged the formerly-paid Pro components into the base `@nuxt/ui` package.

The SaaS template from Nuxt UI provides an excellent starting structure with a landing page, pricing section, and proper layout components. The project should be initialized from this template and then customized with the LocalNodes color palette (deep teal primary, warm amber secondary, dark mode default). Vercel deployment is zero-config for Nuxt 4 projects.

Resend DNS configuration is straightforward -- adding the domain in Resend's dashboard generates DNS records (SPF, DKIM, DMARC) that get added to Cloudflare. Resend offers automatic Cloudflare setup via Domain Connect for one-click configuration. Starting early (Phase 12) gives the domain time to warm up before transactional emails are needed in Phase 16.

**Primary recommendation:** Initialize from the Nuxt UI SaaS template, strip down to landing page essentials, customize the theme with teal/amber colors and dark mode default, prerender the landing page, deploy to Vercel.

<user_constraints>

## User Constraints (from CONTEXT.md)

### Locked Decisions
- Primary audience: non-technical community organizers (civic leaders, neighborhood advocates, bioregional activists)
- Framing approach: problem-first -- lead with the pain of scattered community knowledge before introducing the solution
- Tone: warm & empowering -- like a fellow organizer who gets it, not a tech company selling software
- Three selling points with equal weight: AI-powered knowledge assistant, instant community platform, bioregional sovereignty/mission
- Design aesthetic: hybrid modern + organic -- clean SaaS layout and typography with organic color palette and nature-inspired accents
- Color palette: deep teal primary with warm amber/gold accents -- technology serving nature, Stripe-meets-Patagonia
- Dark mode: dark background by default -- teal and amber accents pop against dark
- Imagery: abstract/geometric art -- flowing shapes, gradients, geometric patterns. No photos. Universal and timeless
- Price: $29/month
- Plan name: none -- just "$29/month" with features list, no named tier
- Billing: monthly only at launch -- no annual option yet
- Feature list framing: both capabilities (AI assistant, groups, events, topics, file library, hybrid search, custom subdomain) AND what's included (hosting, SSL, updates, AI)
- Layout: single-page scroll -- everything on one page with smooth navigation
- Sections in order: Hero (problem + CTA) -> How it works (3-step) -> Features -> Pricing -> Social proof -> Footer
- Platform presentation: interactive demo link to live instance (cascadia.localnodes.xyz) so visitors can explore
- Social proof: live instance count -- "3 communities already growing" with links to Cascadia, Boulder, Portland

### Claude's Discretion
- Exact copy/headlines (within the problem-first, warm & empowering framework)
- Specific Nuxt UI components and layout choices
- Abstract art/gradient implementation approach
- "How it works" 3-step content
- Footer content and links
- Navigation bar design
- Mobile responsiveness approach
- Resend DNS configuration specifics

### Deferred Ideas (OUT OF SCOPE)
None -- discussion stayed within phase scope

</user_constraints>

<phase_requirements>

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-----------------|
| LAND-01 | User sees clear value proposition explaining what LocalNodes knowledge gardens are | PageHero component with problem-first headline, PageSection for feature details, SaaS template structure |
| LAND-02 | User sees pricing information (single plan, single price) before starting onboarding | UPricingPlan component with price="$29", billingCycle="/month", features array, CTA button |
| LAND-03 | User can click "Get Started" CTA to begin the onboarding flow | Button/Link component navigating to /onboarding (Phase 13 route), placed in hero and pricing sections |

</phase_requirements>

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Nuxt | 4.3+ | Full-stack framework | Stable since July 2025. Zero-config Vercel deployment. Nitro server routes for future BFF needs. NuxtLabs acquired by Vercel -- first-class platform support. |
| @nuxt/ui | 4.2+ | Component library (125+ components) | Unified free library (Pro merged into base). Includes PageHero, PageSection, PricingPlan, Header, Footer, all landing page components. Reka UI + Tailwind CSS v4 underneath. |
| Vue | 3.5+ | Reactive UI framework | Bundled with Nuxt 4. Composition API + `<script setup>`. |
| TypeScript | 5.x | Type safety | First-class Nuxt 4 support. Type-safe server routes via Nitro. |
| Tailwind CSS | 4.x | Utility-first styling | Bundled with Nuxt UI. CSS-first configuration via `@theme` directive. 5x faster builds than v3. |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| @nuxt/content | 3.x | YAML-driven content | Landing page section content (headlines, descriptions, features) stored in YAML files, not hardcoded in components |
| @nuxt/fonts | auto | Font loading | Auto-included by @nuxt/ui. Handles optimal font loading. |
| @nuxtjs/color-mode | auto | Dark/light mode | Auto-included by @nuxt/ui. Provides `useColorMode()` composable and `.dark` CSS class. |
| Lucide Icons | bundled | Icon set | Nuxt UI uses Lucide icons by default. `i-lucide-*` prefix. |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| SaaS template | Landing template | SaaS template includes pricing page structure that matches our needs; Landing template is simpler but requires building pricing from scratch |
| @nuxt/content for copy | Hardcoded strings | Content module adds slight overhead but makes copy iteration easier without touching component code; worth it for marketing pages |
| Nuxt UI v4 | shadcn-vue | shadcn-vue requires manual component installation and configuration; Nuxt UI is purpose-built for Nuxt with zero-config |

**Installation:**
```bash
# Initialize from SaaS template
npx nuxi@latest init localnodes-onboarding -t github:nuxt-ui-templates/saas

# Or initialize fresh and add UI module
npx nuxi@latest init localnodes-onboarding
cd localnodes-onboarding
npx nuxi module add ui

# Install content module for YAML-driven copy
npx nuxi module add content
```

## Architecture Patterns

### Recommended Project Structure
```
localnodes-onboarding/
├── app/
│   ├── assets/
│   │   └── css/
│   │       └── main.css           # Tailwind + Nuxt UI imports, custom theme
│   ├── components/
│   │   ├── AppHeader.vue          # Site navigation header
│   │   ├── AppFooter.vue          # Site footer with links
│   │   ├── HeroSection.vue        # Problem-first hero with CTA
│   │   ├── HowItWorks.vue         # 3-step process section
│   │   ├── FeaturesSection.vue    # Feature showcase with icons
│   │   ├── PricingSection.vue     # $29/month pricing card
│   │   ├── SocialProof.vue        # Live communities section
│   │   └── LandingCTA.vue         # Final call-to-action
│   ├── layouts/
│   │   └── default.vue            # Header + footer wrapper
│   ├── pages/
│   │   └── index.vue              # Landing page (single-page scroll)
│   ├── app.vue                    # Root component with UApp wrapper
│   ├── app.config.ts              # Theme colors (teal/amber)
│   └── error.vue                  # Error page
├── content/                        # YAML content for landing page sections
│   └── index.yml                  # Headlines, descriptions, features, pricing
├── public/
│   ├── favicon.ico
│   └── og-image.png               # Social sharing image
├── server/                         # Empty for now -- future BFF routes (Phase 13+)
│   └── api/
├── nuxt.config.ts                  # Module config, prerender rules
├── content.config.ts               # Content module configuration
├── package.json
└── tsconfig.json
```

### Pattern 1: Dark Mode Default with Teal/Amber Theme
**What:** Configure Nuxt UI to use dark mode by default with teal primary and amber secondary colors.
**When to use:** All landing page components.
**Example:**
```typescript
// app/app.config.ts
export default defineAppConfig({
  ui: {
    colors: {
      primary: 'teal',
      secondary: 'amber',
      neutral: 'zinc'
    }
  }
})
```

```css
/* app/assets/css/main.css */
@import "tailwindcss";
@import "@nuxt/ui";

@custom-variant dark (&:where(.dark, .dark *));

:root {
  --ui-primary: var(--ui-color-primary-500);
  --ui-secondary: var(--ui-color-secondary-500);
}
.dark {
  --ui-primary: var(--ui-color-primary-400);
  --ui-secondary: var(--ui-color-secondary-400);
  --ui-bg: var(--ui-color-neutral-950);
  --ui-bg-muted: var(--ui-color-neutral-900);
}
```

```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@nuxt/ui', '@nuxt/content'],
  css: ['~/assets/css/main.css'],

  colorMode: {
    preference: 'dark'  // Dark mode by default
  },

  routeRules: {
    '/': { prerender: true }  // Static landing page
  }
})
```

### Pattern 2: Single-Page Scroll with Section Navigation
**What:** All sections on one page with smooth scroll anchors from the navigation.
**When to use:** The landing page layout.
**Example:**
```vue
<!-- app/pages/index.vue -->
<template>
  <div>
    <HeroSection id="hero" />
    <HowItWorks id="how-it-works" />
    <FeaturesSection id="features" />
    <PricingSection id="pricing" />
    <SocialProof id="communities" />
    <LandingCTA id="get-started" />
  </div>
</template>

<script setup lang="ts">
defineRouteRules({
  prerender: true
})
</script>
```

### Pattern 3: UPricingPlan for Single Plan Display
**What:** Display the $29/month pricing with features list and CTA button.
**When to use:** Pricing section of the landing page.
**Example:**
```vue
<!-- app/components/PricingSection.vue -->
<template>
  <UPageSection id="pricing" title="Simple, transparent pricing">
    <div class="max-w-md mx-auto">
      <UPricingPlan
        price="$29"
        billing-cycle="/month"
        description="Everything you need to grow your community's collective knowledge"
        :features="features"
        :button="{
          label: 'Get Started',
          to: '/onboarding',
          color: 'primary',
          size: 'xl'
        }"
        highlight
        variant="subtle"
      />
    </div>
  </UPageSection>
</template>

<script setup lang="ts">
const features = [
  { title: 'AI-powered knowledge assistant', icon: 'i-lucide-brain' },
  { title: 'Groups, events, topics & file library', icon: 'i-lucide-users' },
  { title: 'Hybrid search (keyword + semantic)', icon: 'i-lucide-search' },
  { title: 'Custom subdomain (yourname.localnodes.xyz)', icon: 'i-lucide-globe' },
  { title: 'Hosting, SSL & automatic updates', icon: 'i-lucide-shield-check' },
  { title: 'AI embeddings & processing included', icon: 'i-lucide-sparkles' }
]
</script>
```

### Pattern 4: PageHero with Problem-First Messaging
**What:** Hero section that leads with the pain point, then reveals the solution.
**When to use:** Top of the landing page.
**Example:**
```vue
<template>
  <UPageHero
    orientation="horizontal"
    :links="[
      { label: 'Get Started', to: '/onboarding', size: 'xl', color: 'primary' },
      { label: 'See a Live Garden', to: 'https://cascadia.localnodes.xyz', target: '_blank', size: 'xl', variant: 'outline' }
    ]"
  >
    <template #headline>
      <UBadge variant="subtle" color="primary">For Community Organizers</UBadge>
    </template>
    <template #title>
      <!-- Problem-first: lead with pain, then solution -->
      Your community's knowledge is scattered across dozens of tools
    </template>
    <template #description>
      LocalNodes brings it all together in one AI-powered knowledge garden.
      A place where conversations, events, and shared wisdom grow -- and an
      AI assistant that actually understands your community.
    </template>
    <template #default>
      <!-- Abstract geometric art / gradient visualization -->
    </template>
  </UPageHero>
</template>
```

### Pattern 5: Prerendered Static Page on Vercel
**What:** The landing page is prerendered at build time for instant loading.
**When to use:** All marketing/static pages.
**Example:**
```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  routeRules: {
    '/': { prerender: true },        // Landing page - static at build time
    '/onboarding': { ssr: true },    // Onboarding - SSR (Phase 13)
    '/api/**': { cors: false }       // Server routes (future phases)
  }
})
```

### Anti-Patterns to Avoid
- **Hardcoding all copy in components:** Use `@nuxt/content` with YAML files for marketing copy. Makes iteration fast without touching component code.
- **Using @nuxt/ui-pro separately:** In v4, Pro components are merged into `@nuxt/ui`. Do NOT install `@nuxt/ui-pro` -- it is the legacy v3 package.
- **Using old component names:** `LandingHero` is now `PageHero`, `LandingSection` is now `PageSection`, `PricingCard` is now `PricingPlan`. The v4 migration renamed these.
- **Setting nitro preset to 'vercel' manually:** Vercel auto-detects Nuxt. Only set the preset in monorepo configurations.
- **Using `pages/` directory outside `app/`:** Nuxt 4 uses the `app/` directory structure. All Vue code (pages, components, composables) lives under `app/`.
- **Building a custom dark mode toggle for a dark-only site:** Set `colorMode.preference: 'dark'` in nuxt.config.ts. Do NOT hide the color mode toggle -- leave it available for users who prefer light mode.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Landing page sections | Custom div/flexbox layouts | `UPageHero`, `UPageSection`, `UPageCTA` | Pre-built responsive layouts with proper slots for title, description, links, features |
| Pricing card | Custom card component | `UPricingPlan` | Handles price display, billing cycle, feature list, CTA button, highlight/scale variants |
| Navigation header | Custom navbar | `UHeader` with `UNavigationMenu` | Responsive mobile menu, scroll behavior, logo placement built-in |
| Footer | Custom footer layout | `UFooter` with `UFooterColumns` | Responsive column layout, link groups, social icons |
| Social proof logos | Manual image grid | `UPageLogos` | Responsive logo strip with proper spacing and alignment |
| Dark mode | CSS class toggling | `@nuxtjs/color-mode` (auto-included) | Handles system preference, persistence, SSR flash prevention |
| Smooth scroll | Custom JS scroll handler | CSS `scroll-behavior: smooth` + anchor links | Native browser smooth scrolling is sufficient for section navigation |
| Color theming | Custom CSS variables | Nuxt UI design system (`app.config.ts` + `@theme` directive) | Semantic color system with automatic light/dark variants |
| Feature icons | Custom SVGs | Lucide icons (`i-lucide-*`) | 1400+ icons bundled with Nuxt UI, consistent style |
| Responsive design | Custom media queries | Tailwind CSS breakpoint utilities | `sm:`, `md:`, `lg:`, `xl:` prefixes handle all responsive needs |

**Key insight:** Nuxt UI v4 provides purpose-built components for every section of a SaaS landing page. Building custom equivalents wastes time and produces inferior responsive/accessibility behavior.

## Common Pitfalls

### Pitfall 1: Installing @nuxt/ui-pro Instead of @nuxt/ui
**What goes wrong:** Developer installs the legacy `@nuxt/ui-pro` package (v3) alongside `@nuxt/ui` (v4), causing version conflicts and missing components.
**Why it happens:** Old tutorials and Stack Overflow answers reference `@nuxt/ui-pro` as a separate package.
**How to avoid:** Only install `@nuxt/ui`. All 125+ components (including all former Pro components like PageHero, PricingPlan, etc.) are included in the base package since v4.
**Warning signs:** Seeing `@nuxt/ui-pro` in `package.json`, component resolution errors, "component not found" warnings.

### Pitfall 2: Using Old Component Names from Nuxt UI v2/v3
**What goes wrong:** Using `ULandingHero`, `ULandingSection`, `UPricingCard` -- these do not exist in v4.
**Why it happens:** Search results and templates may reference v2/v3 component names.
**How to avoid:** Use v4 names: `UPageHero`, `UPageSection`, `UPricingPlan`. Check the [v4 component docs](https://ui.nuxt.com/docs/components).
**Warning signs:** "Failed to resolve component" warnings in dev console.

### Pitfall 3: Placing Files Outside the app/ Directory
**What goes wrong:** Pages, components, or composables placed at the project root are not auto-imported by Nuxt 4.
**Why it happens:** Nuxt 3 used a flat structure; Nuxt 4 defaults to the `app/` directory.
**How to avoid:** All Vue application code goes under `app/`. Only `server/`, `content/`, `public/`, and config files remain at the root.
**Warning signs:** Routes not registering, components not auto-resolving, composables not available.

### Pitfall 4: Flash of Unstyled Content (FOUC) with Dark Mode
**What goes wrong:** Page briefly flashes in light mode before dark mode styles apply.
**Why it happens:** Color mode preference is resolved client-side by default.
**How to avoid:** Set `colorMode.preference: 'dark'` in `nuxt.config.ts`. Nuxt Color Mode injects a script in `<head>` to apply the class before paint. For prerendered pages, the dark class is baked into the HTML.
**Warning signs:** Brief white flash on page load.

### Pitfall 5: Forgetting UApp Wrapper
**What goes wrong:** Toast notifications, tooltips, and programmatic overlays fail silently.
**Why it happens:** These features require the `UApp` component to be present in the component tree.
**How to avoid:** Wrap your app in `<UApp>` in `app.vue`.
**Warning signs:** Toasts not appearing, tooltips not rendering, console warnings about missing provide/inject.

### Pitfall 6: Resend DNS Records Not Propagating
**What goes wrong:** Domain stays in "Pending" status for days.
**Why it happens:** DNS propagation can take up to 72 hours, and CNAME records on Cloudflare must have the proxy (orange cloud) disabled -- set to "DNS Only".
**How to avoid:** Use Resend's "Sign in to Cloudflare" automatic setup if available. If adding records manually, ensure all CNAME records have proxy status set to "DNS Only" (grey cloud). Verify with Resend's "Verify DNS Records" button after 15-30 minutes.
**Warning signs:** Domain stuck in "Pending" after 24+ hours, Cloudflare "Code: 1004" errors when adding records.

### Pitfall 7: nuxi init Template Version Mismatch
**What goes wrong:** Running `npx nuxi init -t github:nuxt-ui-pro/saas` pulls an outdated v2/v3 template.
**Why it happens:** The `nuxt-ui-pro` org has legacy templates. The v4 templates are under `nuxt-ui-templates`.
**How to avoid:** Use `npx nuxi@latest init -t github:nuxt-ui-templates/saas` for the latest v4 template. Alternatively, use the "Use this template" button on the GitHub repository.
**Warning signs:** Generated project has `@nuxt/ui-pro` in dependencies, uses old component names, has Nuxt 3 config structure.

## Code Examples

### Complete Landing Page Structure
```vue
<!-- app/pages/index.vue -->
<template>
  <div>
    <HeroSection />
    <HowItWorks />
    <FeaturesSection />
    <PricingSection />
    <SocialProof />
    <LandingCTA />
  </div>
</template>

<script setup lang="ts">
useSeoMeta({
  title: 'LocalNodes - AI-Powered Knowledge Gardens for Communities',
  description: 'Give your community an AI-powered knowledge garden. Groups, events, topics, and an AI assistant that understands your community. $29/month.',
  ogTitle: 'LocalNodes - AI-Powered Knowledge Gardens',
  ogDescription: 'Give your community an AI-powered knowledge garden.',
  ogImage: '/og-image.png',
  twitterCard: 'summary_large_image'
})
</script>
```

### App Root with UApp Wrapper
```vue
<!-- app/app.vue -->
<template>
  <UApp>
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
```

### Default Layout with Header and Footer
```vue
<!-- app/layouts/default.vue -->
<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />
    <main class="flex-1">
      <slot />
    </main>
    <AppFooter />
  </div>
</template>
```

### Header with Scroll Navigation
```vue
<!-- app/components/AppHeader.vue -->
<template>
  <UHeader>
    <template #left>
      <NuxtLink to="/" class="flex items-center gap-2">
        <!-- Logo -->
        <span class="font-bold text-xl">LocalNodes</span>
      </NuxtLink>
    </template>

    <template #center>
      <UNavigationMenu :items="navItems" />
    </template>

    <template #right>
      <UButton
        label="Get Started"
        to="/onboarding"
        color="primary"
      />
    </template>
  </UHeader>
</template>

<script setup lang="ts">
const navItems = [
  [{ label: 'How it Works', to: '#how-it-works' }],
  [{ label: 'Features', to: '#features' }],
  [{ label: 'Pricing', to: '#pricing' }],
  [{ label: 'Communities', to: '#communities' }]
]
</script>
```

### Social Proof Section
```vue
<!-- app/components/SocialProof.vue -->
<template>
  <UPageSection
    id="communities"
    headline="Growing communities"
    title="3 communities already growing"
    description="Real communities using LocalNodes today"
  >
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <UCard v-for="community in communities" :key="community.name">
        <template #header>
          <h3 class="text-lg font-semibold">{{ community.name }}</h3>
        </template>
        <p class="text-sm text-muted">{{ community.description }}</p>
        <template #footer>
          <UButton
            :label="'Visit ' + community.name"
            :to="community.url"
            target="_blank"
            variant="outline"
            size="sm"
          />
        </template>
      </UCard>
    </div>
  </UPageSection>
</template>

<script setup lang="ts">
const communities = [
  {
    name: 'Cascadia',
    description: 'Bioregional knowledge commons for the Pacific Northwest',
    url: 'https://cascadia.localnodes.xyz'
  },
  {
    name: 'Boulder',
    description: 'Community knowledge garden for Boulder, Colorado',
    url: 'https://boulder.localnodes.xyz'
  },
  {
    name: 'Portland',
    description: 'Local knowledge network for Portland, Oregon',
    url: 'https://portland.localnodes.xyz'
  }
]
</script>
```

### nuxt.config.ts (Phase 12 Complete)
```typescript
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@nuxt/ui', '@nuxt/content'],
  css: ['~/assets/css/main.css'],

  colorMode: {
    preference: 'dark'
  },

  routeRules: {
    '/': { prerender: true }
  },

  app: {
    head: {
      htmlAttrs: { lang: 'en' },
      link: [
        { rel: 'icon', type: 'image/x-icon', href: '/favicon.ico' }
      ]
    }
  },

  compatibilityDate: '2025-07-15'
})
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| @nuxt/ui + @nuxt/ui-pro (separate packages) | Single @nuxt/ui package with all 125+ components | September 2025 (v4) | All Pro components free, single install |
| LandingHero, LandingSection, PricingCard | PageHero, PageSection, PricingPlan | September 2025 (v4) | Component naming convention changed |
| Tailwind CSS v3 (JS config) | Tailwind CSS v4 (CSS-first @theme directive) | 2025 | Configuration in CSS, not tailwind.config.js |
| Nuxt 3 flat directory | Nuxt 4 app/ directory | July 2025 | Application code isolated in app/ subdirectory |
| @nuxt/ui-pro paid license | All free, MIT license | September 2025 | NuxtLabs joined Vercel, everything open-sourced |
| Nuxt UI v2/v3 theming (app.config.ts only) | CSS variables + app.config.ts + @theme directive | v4 | More flexible, CSS-first approach |

**Deprecated/outdated:**
- `@nuxt/ui-pro` package: replaced by `@nuxt/ui` v4 (do not install)
- `LandingHero`, `LandingSection`, `LandingCTA`, `PricingCard` component names: renamed in v4
- `tailwind.config.js`: replaced by CSS-first `@theme` directive in Tailwind v4
- Flat directory structure (pages/ at root): replaced by `app/` directory in Nuxt 4

## Resend DNS Configuration Guide

### Steps for Cloudflare + Resend Setup

1. **Add domain in Resend Dashboard:** Go to Domains, click "Add Domain", enter `localnodes.xyz`
2. **Choose sending subdomain:** Resend uses a subdomain (e.g., `send.localnodes.xyz`) for sending -- this avoids conflicting with existing MX records
3. **Get DNS records from Resend:** Resend generates 3-5 DNS records:
   - 2x CNAME records for DKIM (e.g., `resend._domainkey.localnodes.xyz`)
   - 1x TXT record for SPF (e.g., `send.localnodes.xyz` with value `v=spf1 include:amazonses.com ~all`)
   - 1x MX record (optional, for receiving)
4. **Add records in Cloudflare:**
   - Navigate to DNS > Records in Cloudflare dashboard
   - Add each record from Resend
   - **CRITICAL: Set proxy status to "DNS Only" (grey cloud) for all CNAME records** -- Cloudflare proxy breaks DKIM validation
5. **Add DMARC record:** Create TXT record at `_dmarc.localnodes.xyz` with value `v=DMARC1; p=none; rua=mailto:dmarc@localnodes.xyz;`
6. **Verify in Resend:** Click "Verify DNS Records" -- may take 15 minutes to 72 hours
7. **Alternative: Automatic setup:** Use Resend's "Sign in to Cloudflare" button for Domain Connect one-click setup

### DMARC Warmup Strategy
- Start with `p=none` (monitor only) -- Phase 12
- After successful email sending confirmed (Phase 16+) -- upgrade to `p=quarantine`
- Long term -- consider `p=reject` for maximum protection

### Confidence: HIGH
Source: [Resend Cloudflare docs](https://resend.com/docs/knowledge-base/cloudflare), [Resend DMARC docs](https://resend.com/docs/dashboard/domains/dmarc)

## Project Setup Notes

### Separate Repository
The Nuxt onboarding frontend is a separate project from the Drupal codebase (`fresh3`). It deploys independently to Vercel. The STACK.md recommends a project name of `localnodes-onboarding`.

**Recommendation:** Create the project in a new directory/repo (e.g., `LocalNodes/localnodes-onboarding` on GitHub) rather than nesting it inside the Drupal codebase. This keeps deployments independent and avoids Vercel detecting the wrong framework.

### Vercel Configuration
- The existing Vercel project for `localnodes.xyz` currently serves a static HTML file
- Connecting the new Nuxt repo to Vercel will replace the static site
- Zero-config: Vercel auto-detects Nuxt and configures build settings
- Environment variables for future phases (Stripe, GitHub, Resend) added via Vercel Dashboard

### Abstract Art / Gradient Implementation
For the "abstract/geometric art with flowing shapes, gradients" requirement, recommended approach:
- CSS gradients with `bg-gradient-to-*` Tailwind utilities
- CSS `conic-gradient()` and `radial-gradient()` for organic patterns
- SVG shapes for geometric elements
- Tailwind `blur-*` and `opacity-*` for ethereal effects
- No external libraries needed -- CSS is sufficient for the aesthetic described

## Open Questions

1. **Repository location**
   - What we know: The Nuxt app is a separate project from the Drupal codebase
   - What's unclear: Whether to create it inside the fresh3 monorepo or as a separate GitHub repo
   - Recommendation: Separate repo (`LocalNodes/localnodes-onboarding`) for clean Vercel deployment. Can create within `fresh3` as a subdirectory with `.vercelignore` if monorepo preferred, but separate is simpler.

2. **Content module necessity**
   - What we know: @nuxt/content enables YAML-driven copy management
   - What's unclear: Whether the overhead is justified for a single landing page
   - Recommendation: Include it. The SaaS template already uses it, and it makes copy iteration trivial during the design phase. Content changes without component changes.

3. **Template initialization approach**
   - What we know: SaaS template exists but may pull outdated version via CLI
   - What's unclear: Exact CLI command reliability for v4 template
   - Recommendation: Use `npx nuxi@latest init -t github:nuxt-ui-templates/saas` first. If it pulls an old version, use GitHub "Use this template" button or start fresh with `npx nuxi@latest init` and add modules manually. The fresh init approach is more reliable.

## Sources

### Primary (HIGH confidence)
- [Nuxt 4 Official Docs - Directory Structure](https://nuxt.com/docs/4.x/directory-structure) - verified app/ directory layout, server/ structure
- [Nuxt UI v4 Components](https://ui.nuxt.com/docs/components) - verified 125+ component list, PageHero, PageSection, PricingPlan names and props
- [Nuxt UI v4 Design System](https://ui.nuxt.com/docs/getting-started/theme/design-system) - color configuration, app.config.ts structure
- [Nuxt UI v4 CSS Variables](https://ui.nuxt.com/docs/getting-started/theme/css-variables) - dark mode tokens, semantic color system
- [Nuxt UI v4 Installation](https://ui.nuxt.com/docs/getting-started/installation/nuxt) - installation steps, UApp wrapper requirement
- [Nuxt UI Templates](https://ui.nuxt.com/templates) - SaaS and Landing template availability
- [Nuxt Deploy to Vercel](https://nuxt.com/deploy/vercel) - zero-config deployment confirmed
- [Resend Cloudflare DNS](https://resend.com/docs/knowledge-base/cloudflare) - automatic and manual DNS setup
- [Resend DMARC](https://resend.com/docs/dashboard/domains/dmarc) - DMARC policy configuration

### Secondary (MEDIUM confidence)
- [Nuxt UI v4 Blog Post](https://nuxt.com/blog/nuxt-ui-v4) - Pro/free unification, NuxtLabs joining Vercel
- [Nuxt 4.3 Blog Post](https://nuxt.com/blog/v4-3) - latest version features
- [Nuxt UI SaaS Template GitHub](https://github.com/nuxt-ui-templates/saas) - template structure reference
- [Vercel Nuxt Docs](https://vercel.com/docs/frameworks/full-stack/nuxt) - Vercel-side Nuxt integration

### Tertiary (LOW confidence)
- SaaS template CLI init command reliability (reported issues in March 2025, may be resolved)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - Nuxt 4 + Nuxt UI v4 verified via official docs, stable since July/September 2025
- Architecture: HIGH - Component names, props, and patterns verified via official Nuxt UI docs
- Pitfalls: HIGH - Component naming changes, @nuxt/ui-pro deprecation, app/ directory all documented in official migration guide
- Resend DNS: HIGH - Official Cloudflare guide with step-by-step instructions

**Research date:** 2026-03-03
**Valid until:** 2026-04-03 (stable ecosystem, 30-day validity)
