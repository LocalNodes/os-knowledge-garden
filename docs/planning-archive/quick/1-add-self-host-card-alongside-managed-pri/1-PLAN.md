---
phase: quick
plan: 1
type: execute
wave: 1
depends_on: []
files_modified:
  - ../localnodes-onboarding/app/components/PricingSection.vue
  - ../localnodes-onboarding/content/index.yml
autonomous: true
requirements: [QUICK-1]

must_haves:
  truths:
    - "Visitor sees two pricing cards side by side: Self-Host (free) and Managed ($29/mo)"
    - "Self-Host card links to the GitHub repo (LocalNodes/os-knowledge-garden)"
    - "Managed card retains existing Get Started button linking to /onboarding"
    - "Cards are responsive: side by side on desktop, stacked on mobile"
  artifacts:
    - path: "../localnodes-onboarding/app/components/PricingSection.vue"
      provides: "Two-card pricing layout with self-host and managed options"
    - path: "../localnodes-onboarding/content/index.yml"
      provides: "Self-host card content data alongside existing pricing data"
  key_links:
    - from: "PricingSection.vue"
      to: "https://github.com/LocalNodes/os-knowledge-garden"
      via: "UButton or UPricingPlan button href"
      pattern: "github\\.com/LocalNodes"
---

<objective>
Add a "Self-Host" pricing card alongside the existing $29/mo managed plan card in the PricingSection component. The self-host card communicates that LocalNodes is open source and free to self-host, with a link to the GitHub repo. Two cards side by side on desktop, stacked on mobile.

Purpose: Communicate the open-source nature of LocalNodes and give technical users a free self-host path, while positioning the managed plan as the easy/recommended option.
Output: Updated PricingSection.vue with two-card layout, updated content/index.yml with self-host card data.
</objective>

<execution_context>
@/Users/proofoftom/.claude/get-shit-done/workflows/execute-plan.md
@/Users/proofoftom/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
NOTE: The onboarding project is a SIBLING repo at /Users/proofoftom/Code/os-decoupled/localnodes-onboarding/ (NOT inside fresh3/).

@/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/components/PricingSection.vue
@/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/content/index.yml
@/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/components/FeaturesSection.vue

<interfaces>
<!-- Nuxt UI v4 UPricingPlan component props (from @nuxt/ui v4.5.1): -->
<!-- price: string, billingCycle: string, description: string -->
<!-- features: Array<{ title: string, icon?: string }> -->
<!-- button: { label, to, color, size, variant, target } -->
<!-- highlight: boolean (adds visual emphasis border) -->
<!-- variant: 'solid' | 'outline' | 'soft' | 'subtle' | 'ghost' | 'link' -->
<!-- title: string (plan name shown above price) -->

<!-- Existing PricingSection uses: -->
<!-- UPageSection for section wrapper (headline, title, description props) -->
<!-- UPricingPlan with highlight + variant="subtle" for the managed card -->
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add self-host content to index.yml and update pricing section headline</name>
  <files>/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/content/index.yml</files>
  <action>
Update the `pricing:` section in content/index.yml:
- Change `title` from "One plan. Everything included." to "Open source. Self-host or let us handle it."
- Change `description` from "No tiers, no feature gates. Every community gets the full platform." to "LocalNodes is fully open source. Run it yourself for free, or let us handle the infrastructure."
- Add a new `selfHost:` block under `pricing:` with:
  - `title: "Self-Host"`
  - `price: "Free"`
  - `billingCycle: "forever"`
  - `description: "Run your own instance. Full source code, your infrastructure, total control."`
  - `features:` array with these items (each with title and icon):
    - `"Full source code on GitHub"` / `"i-lucide-github"`
    - `"Docker Compose deployment"` / `"i-lucide-container"`
    - `"All features included"` / `"i-lucide-check-circle"`
    - `"Community support"` / `"i-lucide-message-circle"`
  - `buttonLabel: "View on GitHub"`
  - `buttonUrl: "https://github.com/LocalNodes/os-knowledge-garden"`
- Rename existing feature list key from `features:` to `managedFeatures:` (under pricing) to distinguish from self-host features. Also add `managedTitle: "Managed"` to the pricing block.
  </action>
  <verify>
    <automated>cd /Users/proofoftom/Code/os-decoupled/localnodes-onboarding && node -e "const fs=require('fs'); const y=require('./node_modules/yaml/dist/index.js'); const d=y.parse(fs.readFileSync('content/index.yml','utf8')); console.assert(d.pricing.selfHost, 'selfHost missing'); console.assert(d.pricing.selfHost.price === 'Free', 'price wrong'); console.assert(d.pricing.selfHost.features.length >= 4, 'features missing'); console.assert(d.pricing.managedTitle, 'managedTitle missing'); console.log('PASS: content/index.yml updated correctly')"</automated>
  </verify>
  <done>content/index.yml has selfHost block with title, price, features, button data, and managed plan has its own title field</done>
</task>

<task type="auto">
  <name>Task 2: Rewrite PricingSection.vue with two-card side-by-side layout</name>
  <files>/Users/proofoftom/Code/os-decoupled/localnodes-onboarding/app/components/PricingSection.vue</files>
  <action>
Rewrite PricingSection.vue to display two pricing cards side by side:

Layout:
- Replace `max-w-md mx-auto` single-card container with `grid grid-cols-1 md:grid-cols-2 gap-8 max-w-4xl mx-auto`
- Left card: Self-Host (free, open source) — uses UPricingPlan WITHOUT `highlight` prop
- Right card: Managed ($29/mo) — uses UPricingPlan WITH `highlight` prop (keeps visual emphasis on the recommended option)

Self-Host card (left):
- `title="Self-Host"`
- `price="Free"`
- `billing-cycle="forever"`
- `description="Run your own instance. Full source code, your infrastructure, total control."`
- Features array: Full source code on GitHub, Docker Compose deployment, All features included, Community support (with appropriate lucide icons)
- Button: `{ label: 'View on GitHub', to: 'https://github.com/LocalNodes/os-knowledge-garden', target: '_blank', color: 'neutral', size: 'xl', variant: 'outline' }`
- `variant="subtle"` (no `highlight`)

Managed card (right):
- `title="Managed"`
- Keep existing props: `price="$29"`, `billing-cycle="/month"`, same description and features
- Keep `highlight` prop (adds the emphasized border to show this is the recommended option)
- Keep existing button: `{ label: 'Get Started', to: '/onboarding', color: 'primary', size: 'xl' }`
- `variant="subtle"`

Update the UPageSection props to use the new headline/title/description (can hardcode or read from content — match existing pattern of hardcoding in the component since the existing PricingSection hardcodes values rather than reading from index.yml).

Keep the reassurance text below the cards: "No hidden costs. No per-query fees. No surprises." but adjust to center across the full grid width.

Note on icons: Use `i-lucide-github` for the GitHub feature. If that icon is not available in the lucide set bundled with Nuxt UI, use `i-lucide-code-2` as fallback for "source code".
  </action>
  <verify>
    <automated>cd /Users/proofoftom/Code/os-decoupled/localnodes-onboarding && npx nuxi typecheck 2>&1 | tail -5; echo "---"; grep -c "UPricingPlan" app/components/PricingSection.vue</automated>
  </verify>
  <done>PricingSection.vue renders two UPricingPlan cards in a responsive grid. Self-Host card links to GitHub repo. Managed card retains existing /onboarding flow with visual highlight emphasis. Count of UPricingPlan in file is 2.</done>
</task>

</tasks>

<verification>
1. `cd /Users/proofoftom/Code/os-decoupled/localnodes-onboarding && npx nuxi build 2>&1 | tail -10` - build succeeds
2. Visual: `npm run dev` and visit http://localhost:3000/#pricing - two cards visible side by side, self-host on left (no highlight), managed on right (highlighted), responsive stacking on mobile
3. Self-Host "View on GitHub" button opens https://github.com/LocalNodes/os-knowledge-garden in new tab
4. Managed "Get Started" button still navigates to /onboarding
</verification>

<success_criteria>
- Two pricing cards rendered side by side on desktop, stacked on mobile
- Self-Host card shows "Free / forever" with GitHub link
- Managed card shows "$29 / month" with /onboarding link and visual highlight
- Build passes without errors
</success_criteria>

<output>
After completion, create `.planning/quick/1-add-self-host-card-alongside-managed-pri/1-SUMMARY.md`
</output>
