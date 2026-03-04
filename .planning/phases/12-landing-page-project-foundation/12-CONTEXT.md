# Phase 12: Landing Page & Project Foundation - Context

**Gathered:** 2026-03-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Nuxt 4 app on Vercel replaces the existing 2200-line static index.html at localnodes.xyz. Delivers a landing page with clear value proposition, pricing display ($29/mo), and "Get Started" CTA that navigates to the onboarding form (Phase 13). Also configures Resend email DNS records (SPF/DKIM/DMARC) so the domain warms up before transactional emails are needed in Phase 16.

</domain>

<decisions>
## Implementation Decisions

### Content & messaging
- Primary audience: non-technical community organizers (civic leaders, neighborhood advocates, bioregional activists)
- Framing approach: problem-first — lead with the pain of scattered community knowledge before introducing the solution
- Tone: warm & empowering — like a fellow organizer who gets it, not a tech company selling software
- Three selling points with equal weight: AI-powered knowledge assistant, instant community platform, bioregional sovereignty/mission

### Visual identity & design
- Design aesthetic: hybrid modern + organic — clean SaaS layout and typography with organic color palette and nature-inspired accents
- Color palette: deep teal primary with warm amber/gold accents — technology serving nature, Stripe-meets-Patagonia
- Dark mode: dark background by default — teal and amber accents pop against dark
- Imagery: abstract/geometric art — flowing shapes, gradients, geometric patterns. No photos. Universal and timeless

### Pricing
- Price: $29/month
- Plan name: none — just "$29/month" with features list, no named tier
- Billing: monthly only at launch — no annual option yet
- Feature list framing: both capabilities (AI assistant, groups, events, topics, file library, hybrid search, custom subdomain) AND what's included (hosting, SSL, updates, AI)

### Page structure
- Layout: single-page scroll — everything on one page with smooth navigation
- Sections in order: Hero (problem + CTA) → How it works (3-step) → Features → Pricing → Social proof → Footer
- Platform presentation: interactive demo link to live instance (cascadia.localnodes.xyz) so visitors can explore
- Social proof: live instance count — "3 communities already growing" with links to Cascadia, Boulder, Portland

### Claude's Discretion
- Exact copy/headlines (within the problem-first, warm & empowering framework)
- Specific Nuxt UI components and layout choices
- Abstract art/gradient implementation approach
- "How it works" 3-step content
- Footer content and links
- Navigation bar design
- Mobile responsiveness approach
- Resend DNS configuration specifics

</decisions>

<specifics>
## Specific Ideas

- Problem-first hero: lead with scattered knowledge pain point, then reveal the solution
- Three pillars get equal visual weight: AI knowledge assistant, community platform features, bioregional mission/values
- Pricing card shows both what the platform does AND what's included — no hidden costs messaging
- Live demo link to cascadia.localnodes.xyz lets visitors experience the actual platform
- Social proof uses real data: 3 live communities with clickable links

</specifics>

<code_context>
## Existing Code Insights

### Reusable Assets
- No existing Nuxt/Vue code — this is a greenfield project
- Existing static landing page (2200-line index.html on Vercel) can be referenced for content ideas but will be completely replaced
- Live demo instances available: cascadia.localnodes.xyz, boulder.localnodes.xyz, portland.localnodes.xyz

### Established Patterns
- Nuxt 4 + Nuxt UI decided during v2.0 research (STACK.md)
- Nitro server routes as BFF pattern — server routes deploy as Vercel serverless functions
- Vercel deployment with zero-config Nuxt support
- `routeRules` for prerendering static pages (landing page should be prerendered)

### Integration Points
- Vercel: project already exists for localnodes.xyz — new Nuxt app replaces static site
- Resend: DNS records (SPF/DKIM/DMARC) configured on localnodes.xyz domain in Cloudflare
- Nuxt UI SaaS template: potential starting point for layout and components
- "Get Started" CTA links to /onboarding (Phase 13's form)

</code_context>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope

</deferred>

---

*Phase: 12-landing-page-project-foundation*
*Context gathered: 2026-03-03*
