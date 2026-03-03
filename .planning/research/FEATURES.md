# Feature Landscape

**Domain:** Self-service SaaS onboarding for community knowledge garden platform
**Researched:** 2026-03-02
**Target User:** Non-technical community organizer (bioregional, civic, neighborhood)

## Table Stakes

Features users expect from any self-service SaaS onboarding. Missing = product feels broken or untrustworthy.

### Onboarding Flow

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Minimal sign-up form (3 fields max) | Every extra field costs ~7% conversion. Non-technical users abandon long forms. | Low | Community name, email, password. That is the ceiling. |
| Subdomain auto-generation from community name | Users expect `mycommunity.localnodes.xyz` to derive from their community name without manual entry. Vercel, Railway, Render all auto-generate URLs. | Low | Slugify community name, show preview in real-time, allow edit. Validate against reserved names (www, api, coolify, mail, smtp — already in workflow). |
| Email/password account creation | Table stakes for any web service. SSO (Google/GitHub) is a nice-to-have but not required for community organizers. | Low | Keep it simple. OAuth adds dependency and complexity for marginal gain with this audience. |
| Clear pricing display before payment | Users must understand what they are paying for before they enter payment details. 63% of customers consider onboarding experience when making purchase decisions. | Low | Single plan = single card with price, features list, and CTA. Use Stripe Pricing Table embed or custom card. |

### Payment

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Stripe Checkout (hosted) for payment | PCI compliance handled automatically, professional payment UI, supports 100+ payment methods, mobile-optimized. Every competitor uses Stripe or equivalent. | Low | Use Stripe Checkout Session, NOT custom Elements. Fastest to ship, highest conversion, zero PCI scope. Redirect to Stripe -> return to status page. |
| Subscription billing (monthly) | Users expect recurring billing for SaaS. One-time payments feel like buying software, not a service. | Low | Single price, single plan. Create Stripe Product + Price in dashboard. Checkout Session creates subscription automatically. |
| Payment failure messaging | Users whose cards are declined need clear, non-technical feedback. Stripe Checkout handles this natively. | None (free) | Stripe Checkout shows inline errors. No custom work needed. |
| Receipt / confirmation email from Stripe | Users expect a payment receipt. Stripe sends this automatically when configured. | None (free) | Enable receipt emails in Stripe Dashboard settings. |

### Provisioning Feedback

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Multi-step progress indicator during provisioning | Users who see no feedback during a ~4 min wait will assume the system is broken. Research: users abandon after 3 seconds without feedback; progress indicators make users willing to wait 3x longer. | Medium | Show named steps: "Creating your knowledge garden..." / "Setting up database..." / "Installing platform..." / "Almost ready..." Poll backend for status. |
| Animated loader (not static "Please wait") | Static text creates anxiety. Animated indicators build trust and reduce perceived wait time. NNGroup research confirms this. | Low | CSS animation or Lottie. Pair with step-by-step text updates. |
| Estimated time remaining | Long waits (4 min) are tolerable when users know the duration. "Uncertain waits feel longer than known waits." | Low | Show "Usually takes about 4 minutes" prominently. Optional: show elapsed time counter. |
| Success confirmation page | Users need to know it worked. Show the URL, login credentials, and a prominent "Go to your site" button. | Low | Redirect to success page after provisioning completes. Include: site URL, admin email, temporary password, "Visit Your Knowledge Garden" CTA. |

### Post-Provisioning

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Welcome email with credentials and URL | Slack sends workspace URL. Squarespace sends login details. Every SaaS sends a welcome email. Welcome emails have 23% read rate and 26.9% CTR — highest of any email type. | Medium | Transactional email (not marketing). Must include: site URL, login credentials, 2-3 getting-started steps, support contact. Use SendGrid, Resend, or Postmark. |
| Working site at subdomain with HTTPS | Users click the link and it works. SSL is non-negotiable. Wildcard cert on *.localnodes.xyz handles this. | Low | Already solved: Coolify + Traefik handle SSL via Let's Encrypt. Wildcard DNS on Cloudflare already configured. |
| Admin user auto-created with organizer's email | The person who paid should be able to log in immediately. No separate "create your account" step after provisioning. | Low | Already partially solved: workflow creates admin user. Need to use organizer's email as username/email instead of generic "admin". |
| Demo content pre-loaded | An empty knowledge garden is demoralizing. Pre-load content so the organizer sees what the platform can do. Like Railway templates: "skip setup entirely." | Low | Already solved: DEMO_MODULE env var controls this. Default to localnodes_demo for new instances. |

### Error Handling

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Graceful provisioning failure with retry | Provisioning can fail (Coolify API errors, Docker build failures, network issues). Users must not be left in limbo. | Medium | Show clear error message. Offer "Try Again" button. Do NOT charge again (Stripe subscription already created). Backend must be idempotent — detect existing Coolify app and retry deploy. |
| Subdomain conflict detection | Two users picking the same subdomain must not cause a silent failure. | Low | Already solved: workflow checks subdomain availability. Frontend should validate in real-time before payment. |
| Invalid input validation | Non-technical users will enter spaces, special characters, long names. | Low | Client-side validation with friendly error messages. Slugify community name automatically. Show preview of resulting subdomain. |

## Differentiators

Features that set LocalNodes apart. Not expected in generic SaaS, but valued by community organizers.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Real-time provisioning status via WebSocket/SSE | Most PaaS platforms (Railway, Render) show real-time deploy logs. Showing live status during ~4 min provision is a "wow" moment vs. a simple spinner. | High | Requires server-sent events (SSE) or WebSocket from backend to frontend. Backend polls GitHub Actions run status and Coolify deployment status, forwards to client. Deferred to future phase if too complex for MVP. |
| Instant subdomain preview | As user types community name, show live preview of `mycommunity.localnodes.xyz`. Railway and Vercel do this. Makes the product feel responsive and professional. | Low | Pure frontend. Slugify input, show URL preview. High impact, low effort. |
| Community name personality quiz / guided flow | Mighty Networks uses AI-assisted onboarding. A 2-3 question flow ("What kind of community? What's your bioregion? What topics matter?") feels personal vs. a cold form. | Medium | NOT an AI chatbot (out of scope). Simple branching form. Could pre-configure demo content or group structure. Defer to v2.1 — stub the architecture. |
| "Your garden is growing" animation during provision | Instead of a generic spinner, show a garden metaphor: seeds sprouting, leaves growing. Matches the "knowledge garden" brand. Memorable. | Medium | Custom Lottie animation or CSS. Differentiator but not MVP-critical. |
| Post-provision guided tour email sequence | 3-5 emails over the first week: welcome, "add your first content", "invite your first member", "try the AI assistant", "set up your first group". | Medium | Requires transactional email service with drip sequence capability. High retention impact. Can be built after launch. |

## Anti-Features

Features to explicitly NOT build. These are complexity traps or scope creep.

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Custom pricing page with multiple tiers | Single plan simplifies everything. Multiple tiers require plan management, upgrade/downgrade flows, feature gating, entitlement checking. Kills velocity. | One plan, one price. Add tiers later when usage data shows demand. |
| Free trial / freemium tier | Infrastructure cost is real (~$5/mo Coolify server per instance). Free trials require billing transition, trial expiration handling, instance teardown. Massive complexity. | Charge from day one. Offer a money-back guarantee or a shared demo instance to try. |
| Custom domain support at onboarding | BYOD requires TXT record verification, per-domain DV certificates, DNS propagation handling. Enterprise feature, not MVP. | All instances get `*.localnodes.xyz` subdomain. Add BYOD as a paid upgrade later. |
| GitHub/OAuth sign-in for organizers | Community organizers are non-technical. GitHub login is meaningless. Google OAuth adds dependency for marginal gain. | Email/password only. Add OAuth later if demand exists. |
| Instance management dashboard | Managing scaling, viewing logs, adjusting resources — that is platform engineering, not community organizing. | Provide a support email. Build dashboard in a future milestone (explicitly out of scope per PROJECT.md). |
| Conversational AI onboarding agent | PROJECT.md explicitly defers this. An AI that asks questions and provisions is cool but fragile and unpredictable. | Stub extensibility points in the architecture. Build a simple form. Ship it. |
| In-app payment (Stripe Elements) | Embedding payment forms requires PCI scope awareness, custom error handling, card brand detection, and 3D Secure flows. Stripe Checkout handles all of this. | Use Stripe Checkout (redirect). Simpler, higher conversion, zero PCI compliance burden. |
| User-selectable demo content / templates | Choosing from templates adds decision paralysis for non-technical users. Requires building a template gallery UI. | Default demo content for all. One template. Customize later. |
| Multi-region deployment | Coolify runs on a single server. Multi-region requires load balancing, geo-routing, data residency considerations. | Single server, single region. Scale when needed. |
| Team/org billing (multiple seats at signup) | Adds complexity: seat management, billing per-seat, invite flows, role assignment. | One organizer account per instance. They invite members from within the platform (Open Social handles this natively). |

## Feature Dependencies

```
Email collection (signup) --> Stripe Checkout (needs customer email)
Stripe Checkout success --> Trigger provisioning (GitHub Actions workflow_dispatch)
Subdomain validation --> Provisioning (must validate before triggering)
Provisioning trigger --> Status polling (need workflow run ID)
Status polling --> Progress UI (frontend displays backend status)
Provisioning complete --> Welcome email (needs site URL + credentials)
Provisioning complete --> Success page (redirect after poll detects completion)
Wildcard DNS (*.localnodes.xyz) --> Subdomain routing (already configured)
Stripe webhook (payment_intent.succeeded) --> Provisioning trigger (alternative to redirect-based trigger)
```

### Critical Path (MVP)

```
Landing page --> Sign-up form --> Stripe Checkout redirect -->
  Stripe success redirect --> Backend triggers GitHub Actions -->
    Frontend polls for status --> Progress UI -->
      Provisioning complete --> Success page + Welcome email
```

### Infrastructure Dependencies (already satisfied)

```
Cloudflare wildcard DNS (*.localnodes.xyz) -- DONE
Coolify API for app creation -- DONE
GitHub Actions provision-instance.yml -- DONE
Docker multi-stage build + GHCR -- DONE
Traefik + Let's Encrypt SSL -- DONE
```

## MVP Recommendation

### Must Have (Phase 1 - Launch)

1. **Landing page** with clear value proposition, pricing, and "Get Started" CTA
2. **3-field sign-up form**: community name, email, password (community name auto-generates subdomain preview)
3. **Subdomain validation** (real-time, check availability against Coolify API)
4. **Stripe Checkout redirect** for payment (single monthly plan)
5. **Provisioning trigger** (backend receives Stripe webhook or success redirect, calls GitHub Actions workflow_dispatch API)
6. **Progress/status page** with animated multi-step indicator and "~4 minutes" estimate (poll backend for status)
7. **Success page** with site URL, credentials, and "Visit Your Knowledge Garden" CTA
8. **Welcome email** with credentials, URL, and 2-3 getting-started links
9. **Error handling**: payment failure (Stripe handles), provisioning failure (retry button), subdomain conflict (real-time validation)

### Defer (Phase 2+)

- Real-time SSE/WebSocket status (use polling first)
- Guided onboarding quiz / community type selection
- Post-provision email drip sequence (beyond welcome email)
- Custom "garden growing" animation (use generic animated progress first)
- Stripe Customer Portal for subscription management
- Custom domain support (BYOD)
- Instance management dashboard
- Multiple pricing tiers
- OAuth/SSO sign-in

### Explicitly Never

- Free tier / trial (infrastructure costs are real)
- Conversational AI onboarding (PROJECT.md scopes this out)
- Multi-region deployment
- Team billing at signup

## Complexity Estimates

| Feature Area | Complexity | Rationale |
|--------------|------------|-----------|
| Landing page | Low | Static content, single CTA, can be a single-page app |
| Sign-up form + validation | Low | 3 fields, slugify, availability check API call |
| Stripe Checkout integration | Low | Create Checkout Session endpoint, handle redirect, webhook listener |
| Backend provisioning bridge | Medium | API endpoint that receives Stripe event, triggers GitHub Actions via API, tracks run status |
| Progress/status UI | Medium | Poll backend for GitHub Actions run status + Coolify deploy status, render multi-step progress |
| Welcome email | Medium | Need transactional email service (Resend/SendGrid), email template, trigger after provisioning |
| Error handling + retry | Medium | Idempotent provisioning, detect existing apps, graceful failure states |
| User account bootstrapping | Low-Medium | Pass organizer email to workflow, create Drupal user with that email during site-install |

## Sources

- [Vercel Onboarding Flow](https://pageflows.com/post/desktop-web/onboarding/vercel/) -- Onboarding UX patterns
- [Railway Review 2025](https://ikigaiteck.com/pages/railway-review-2025-modern-app-deployment-platform) -- Developer onboarding, provisioning UX
- [Railway vs Render 2026](https://thesoftwarescout.com/railway-vs-render-2026-best-platform-for-deploying-apps/) -- Platform comparison
- [Render First Deploy](https://render.com/docs/your-first-deploy) -- Self-service deploy patterns
- [Render Platform](https://render.com/platform) -- Golden path deployment philosophy
- [Netlify Start Pathways](https://docs.netlify.com/start/overview/) -- Onboarding pathways
- [Coolify Docs](https://coolify.io/docs/) -- Self-hosted PaaS onboarding
- [Stripe Checkout Pricing Table](https://stripe.com/docs/payments/checkout/pricing-table) -- Embeddable pricing
- [Stripe Subscription Quickstart](https://docs.stripe.com/billing/quickstart) -- Subscription flow
- [Stripe SaaS Integration](https://docs.stripe.com/saas) -- SaaS billing patterns
- [SaaS Onboarding Best Practices 2025](https://productled.com/blog/5-best-practices-for-better-saas-user-onboarding) -- Conversion optimization
- [SaaS Failed Payments](https://blog.payproglobal.com/saas-failed-payments) -- Payment failure handling
- [Progress Indicators in SaaS](https://dev.to/lollypopdesign/progress-indicators-explained-types-variations-best-practices-for-saas-design-392n) -- Progress UX patterns
- [NNGroup Progress Indicators](https://www.nngroup.com/articles/progress-indicators/) -- Research on wait tolerance
- [SaaS Welcome Emails](https://beefree.io/blog/saas-welcome-emails-7-examples-and-best-practices) -- Email onboarding patterns
- [DNS Automation for Multi-Tenant SaaS](https://dev.to/alexisfranorge/dns-automation-for-multi-tenant-saas-on-cloudflare-2ag7) -- Subdomain provisioning
- [Wildcard TLS for Multi-Tenant Systems](https://www.skeptrune.com/posts/wildcard-tls-for-multi-tenant-systems/) -- SSL certificate patterns
- [Mighty Networks](https://www.mightynetworks.com/resources/community-platforms) -- Community platform onboarding
- [Hivebrite vs Mighty Networks](https://innoloft.com/en-us/blog/hivebrite-vs-mighty-networks) -- Community platform comparison
- [User Onboarding Strategies B2B SaaS](https://auth0.com/blog/user-onboarding-strategies-b2b-saas/) -- Provisioning patterns
- [SaaS Onboarding UX Patterns](https://www.eleken.co/blog-posts/user-onboarding-ux-patterns-a-guide-for-saas-companies) -- UX research
- [Designing SaaS Onboarding Flows](https://www.jobstractor.com/2025/11/30/designing-effective-saas-onboarding-flows-for-new-users/) -- Flow design
