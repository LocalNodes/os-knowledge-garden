# Phase 6 (Boulder): Create Boulder Demo Content for Second LocalNodes Instance - Research

**Researched:** 2026-02-28
**Domain:** Boulder/Front Range bioregional content strategy + Ethereum localism community themes
**Confidence:** HIGH

## Summary

This research defines the content themes, personas, groups, topics, events, and vocabulary for a Boulder, CO demo content module that will run on a **separate LocalNodes instance** alongside the existing Cascadia instance. Together, the two instances simulate the LocalNodes network of bioregional nodes. The Boulder node is distinguished by three intersecting community threads: (1) the South Platte/Boulder Creek watershed and Front Range ecological restoration work, (2) the Ethereum localism and regenerative finance (ReFi) movement centered around ETHBoulder and the OpenCivics Consortium, and (3) the physical-world regenerative practices (permaculture, native planting, beaver dam analogs) happening through organizations like the Boulder Watershed Collective and The Watershed Center.

The key insight from the source material is that Boulder's regenerative community uniquely bridges **technology and land**. Where Cascadia's content emphasizes traditional bioregionalism (watersheds, commons, mutual aid, indigenous knowledge), Boulder's content should emphasize the **convergence of web3 coordination tools with place-based regeneration** -- Ethereum localism, quadratic funding for local public goods, community currencies, and "regen tech" applied to real watershed and land restoration. This creates a compelling contrast when the two nodes are viewed together as a network: Cascadia as the more traditional bioregional node, Boulder as the tech-forward regenerative node, both sharing the same vocabulary of belonging, cosmolocalism, and polycentric governance.

The content structure mirrors the Cascadia module exactly (12 users, 5 groups, 10 topics, 18 posts, 13+ comments, 8 events, likes, enrollments) to maintain parity. All entity types use the same YAML schema and plugin architecture already established in `localnodes_demo`. The new module will be named `boulder_demo` (or similar) with its own plugin IDs and completely fresh UUIDs.

**Primary recommendation:** Create a `boulder_demo` module following the identical architecture as `localnodes_demo`, with content themed around Boulder's intersection of watershed restoration, Ethereum localism, and regenerative community building. Use the updated topic types (Blog, News, Dialog, Research, Question) to showcase the two new types that were added after the Cascadia content was created.

## Source Material Summary

### Source 1: Water Bioregion (unforced.github.io/water-bioregion/)
**Confidence:** HIGH (primary source, detailed content)

Key findings for Boulder content:
- Water falling in the Boulder area flows through a **2,000-mile journey**: Continental Divide through Boulder County, then the South Platte, the Missouri, and the Mississippi to the Gulf of Mexico
- **Riparian corridors** occupy just 1% of Colorado's eastern plains but support 90% of wildlife
- **24,000+ native plants** planted along streams, **20+ completed restoration projects**, **600 acres of forest restoration**, **37 miles of restored creek flows** -- specific metrics to reference in content
- **Beaver dam analogs (BDAs)** are a central restoration technique -- human-constructed structures mimicking natural beaver dams to raise water tables and restore braided stream channels
- **Biotic pump concept**: dense vegetation transpiration creates moisture that forms clouds and drives rainfall
- **Organizations mentioned**: Boulder Watershed Collective, The Watershed Center, Boulder County Parks & Open Space, Colorado Water Trust, Wildlands Restoration Volunteers, Colorado Natural Heritage Program, National Forest Foundation
- **Historical context**: Dust Bowl reference (3 million tons of soil in one day), China's Loess Plateau restoration precedent (11,500 sq miles restored)

### Source 2: RegenHub (regenhub.xyz)
**Confidence:** MEDIUM (minimal web content, supplemented by search)

Key findings:
- **RegenHub** is described as "Boulder's Regenerative Workspace" -- a physical co-working space
- Serves as partnership venue for OpenCivics Consortium events including GFEL Boulder
- Hosted post-conference co-working and community potlucks during GFEL Boulder 2025
- Connected to ETHBoulder as the "Powered by" organization
- Functions as a physical anchor point for Boulder's regen/web3 community

### Source 3: ETHBoulder (ethboulder.xyz)
**Confidence:** HIGH (detailed content from website + supplementary search)

Key findings:
- **ETHBoulder 2026**: February 13-15, 2026 -- a decentralized, community-governed Ethereum un-conference and hackathon in Boulder
- Tagline: "Fork The Frontier" -- framed as participatory gathering
- Uses **Schelling Point App** for quadratic voting on session proposals
- **Six tracks**: Creativity, Ethereum Localism, Public Goods Funding, AI and Society, Privacy, Decentralization
- **Key themes**: open-source coordination, public goods, resilient infrastructure, Ethereum localism, DAOs, governance, community currencies
- **Notable speakers/projects** (for inspiration, use fictional names): Gitcoin co-founder, CU Boulder legal scholars, P2P Foundation founder, privacy advocates, civic innovators working on community currencies
- **GFEL Boulder** (General Forum on Ethereum Localism, Feb 2025): "cosmo-local federation formation," mutual credit, commitment pooling, regenerative finance, with field trips to Niwot Homestead, Yellow Barn Farm, Boulder Food Rescue
- **Culture**: "hippies with laptops," regen tech, vibe-coded, bottom-up coordination, anti-speculation ethos
- Returns **100% of profits to attendees** -- explicitly contrasts with mainstream crypto conferences

### Source 4: Infrastructure of Belonging (Substack article)
**Confidence:** HIGH (conceptual framework shared between both nodes)

Key vocabulary shared with Cascadia (ensures consistency):
- Credibly neutral substrate, polycentric governance, permissionless self-declaration
- Cyber-physical polycentric cosmolocal bioregional stack
- Knowledge Organization Infrastructure (KOI) -- federated commons routing knowledge vertically and horizontally
- Plural resource distribution, composable treasury pools, streaming mechanisms, retroactive impact payments
- Balinese watershed governance as precedent
- Ethereum as credibly-neutral programmable infrastructure
- Defensive accelerationism (d/acc), regenerative accelerationism (re/acc)

### Supplementary Research: Boulder Watershed Organizations
**Confidence:** HIGH (verified through official sources)

- **Boulder Watershed Collective**: Received $1.59M Water Plan Grant for Boulder Creek Headwaters Resiliency Project (2025)
- **The Watershed Center**: Completed 2025 project on South St. Vrain Creek with ecological monitoring; planning 2026-2027 floodplain restoration
- **City of Boulder OSMP**: Lower Boulder Creek Wetland Restoration (regrading floodplain, planting native cottonwoods, removing invasive Russian olive)
- **Caribou Ranch**: Beaver Mimicry Structure volunteer events in Boulder County (Front Range)
- **Coalition for the Poudre River Watershed**: LTPBR (low-tech process-based restoration) programs
- **Boulder County Watershed Monitoring**: Active community science monitoring program
- **Boulder Food Rescue**: Local food redistribution organization (field trip destination for GFEL)
- **CU Boulder Masters of the Environment**: Community-based monitoring capstone projects

## Content Themes

The Boulder node has three primary thematic pillars that distinguish it from Cascadia:

### 1. Front Range Watershed Restoration (ecological)
- South Platte River basin and Boulder Creek watershed
- Beaver dam analogs and low-tech process-based restoration
- Riparian corridor restoration and native planting
- Community science monitoring and water quality data
- Wildfire recovery and forest resilience
- Continental Divide to Gulf of Mexico water journey

### 2. Ethereum Localism and Regen Tech (technological)
- Quadratic funding for local public goods
- Community currencies and mutual credit systems
- DAO governance for bioregional coordination
- Data sovereignty through decentralized infrastructure
- Open-source community tooling
- Cosmo-local coordination protocols

### 3. Place-Based Regenerative Practice (social)
- Regenerative agriculture and permaculture on the Front Range
- Community food systems (food rescue, urban farming, farm-to-table)
- Co-working and community space building (RegenHub model)
- University-community partnerships (CU Boulder)
- Youth engagement in regen tech and watershed stewardship
- Cross-pollination between tech and land communities

## Proposed Groups

5 groups matching Cascadia's count, but reflecting Boulder's unique community structure:

| # | Group Name | Focus | Visibility | Join Method | Cascadia Parallel |
|---|-----------|-------|-----------|-------------|-------------------|
| 1 | **Front Range Watershed Hub** | Main coordination hub for Boulder Creek/South Platte watershed restoration | community | direct | Cascadia Bioregional Hub |
| 2 | **Boulder Regen Tech Collective** | Ethereum localism, quadratic funding, community currencies, DAOs, open-source coordination tools | community | direct | Regenerative Economics Working Group |
| 3 | **Front Range Food Commons** | Regenerative agriculture, food rescue, permaculture, seed saving, urban farming on the Front Range | community | direct | Watershed Guardians Collective |
| 4 | **RegenHub Boulder** | Physical community workspace, events, coworking, community building, onboarding new members | community | direct | Cascadia Commons Land Trust |
| 5 | **Boulder Governance Circle** | Polycentric governance decisions, treasury allocation, cross-group coordination (CLOSED) | group-only | added | Cascadia Governance Council |

### Group Descriptions (prescriptive)

**Front Range Watershed Hub**: Central coordination space for watershed stewardship across the Front Range. Members share monitoring data from Boulder Creek and South Platte tributaries, coordinate beaver dam analog installations, organize native planting days, and connect community science efforts with The Watershed Center and Boulder Watershed Collective. The hub where land-based and tech-based regeneration meet.

**Boulder Regen Tech Collective**: Where Ethereum localism meets the Front Range. This group explores how web3 coordination tools -- quadratic funding, community currencies, mutual credit, and DAO governance -- can serve place-based regenerative communities. Born from the ETHBoulder and GFEL gatherings, members build open-source tools, run local public goods funding rounds, and practice "regen tech" that serves life rather than extracting from it.

**Front Range Food Commons**: Connecting regenerative agriculture practitioners, food rescue volunteers, urban farmers, and permaculture designers across the Front Range. Share growing knowledge adapted to Colorado's high-altitude semi-arid climate, coordinate seed swaps, organize farm-to-table events, and build the food sovereignty infrastructure our bioregion needs.

**RegenHub Boulder**: The physical anchor of Boulder's regenerative community. RegenHub is a co-working space, event venue, and community commons where regenerators, builders, and organizers gather. This group coordinates events, welcomes newcomers, shares resources, and maintains the social fabric that holds our community together.

**Boulder Governance Circle**: The deliberative body for the Boulder node's polycentric governance. Council members are nominated to participate in decisions about treasury allocation, cross-group coordination, and network-level policies. Closed group -- members added by invitation to ensure accountability. (Exercises permission filtering for AI features.)

## User Personas

12 users matching Cascadia's count. Each persona represents a realistic archetype from Boulder's regen community:

| # | Name | Username | Role | Organization | Timezone | Expertise Tags | Key Distinction from Cascadia |
|---|------|----------|------|-------------|----------|----------------|------------------------------|
| 1 | **Mira Solano** | mira_watershed | Watershed Restoration Lead | Boulder Watershed Collective (fictional) | America/Denver | Watershed Ecology, Riparian Restoration | Front Range watershed focus vs Cascadia's Pacific NW |
| 2 | **Kai Nakamura** | kai_regentech | Regen Tech Builder | OpenCivics Labs (fictional) | America/Denver | Ethereum Localism, Public Goods Funding | Web3/regen tech -- no Cascadia parallel |
| 3 | **Lucia Trujillo** | lucia_foodcommons | Permaculture Designer & Food Rescue | Front Range Food Collective (fictional) | America/Denver | Regenerative Agriculture, Permaculture Design | High-altitude semi-arid growing vs Pacific NW |
| 4 | **Devon Hartley** | devon_currencies | Community Currency Designer | Boulder Mutual Credit Lab (fictional) | America/Denver | Community Currencies, Cooperative Economics | Mutual credit/currencies -- more specific than Cascadia's mutual aid |
| 5 | **Sage Clearwater** | sage_governance | Governance Facilitator | Cosmo-Local Council (fictional) | America/Denver | Polycentric Governance, DAO Governance | DAO + traditional governance hybrid |
| 6 | **Zara Okafor** | zara_datascience | Community Data Scientist | CU Boulder Env Studies (fictional) | America/Denver | Community Science, Data Sovereignty | University-community partnership angle |
| 7 | **Rowan Blackwood** | rowan_beaver | Restoration Ecologist | Front Range Rewilding (fictional) | America/Denver | Beaver Dam Analogs, Ecological Restoration | BDA specialist -- unique to Boulder's restoration approach |
| 8 | **Jess Park** | jess_regenhub | Community Organizer & Space Steward | RegenHub Boulder (fictional) | America/Denver | Community Building, Event Production | Physical space steward -- RegenHub anchor |
| 9 | **Marco Rivera** | marco_solar | Cooperative Energy Coordinator | Front Range Solar Coop (fictional) | America/Denver | Community Energy, Cooperative Finance | Colorado solar (300+ sun days) vs Cascadia's limited sun |
| 10 | **Talia Redhawk** | talia_indigenous | Indigenous Water Protector | Arapaho Water Relations (fictional) | America/Denver | Indigenous Knowledge, Water Rights | Arapaho/Cheyenne territory vs Coast Salish |
| 11 | **Finn O'Brien** | finn_youth | Youth Regen Organizer | Next Gen Front Range (fictional) | America/Denver | Climate Action, Youth Engagement | Youth bridge between tech and land |
| 12 | **Boulder Admin** | boulder_admin | Site Manager | LocalNodes Platform | America/Denver | Digital Infrastructure | Parallel to localnodes_admin |

### Persona Depth Notes

- **Mira Solano**: Coordinates riparian restoration along Boulder Creek, works with The Watershed Center. Knows the 2,000-mile water journey from Continental Divide to Gulf of Mexico. Self-introduction references native planting metrics and community science monitoring.
- **Kai Nakamura**: Came from the ETHBoulder/GFEL community. Builds open-source coordination tools for bioregional DAOs. Believes Ethereum is a credibly-neutral substrate for cosmo-local coordination. Anti-speculation ethos.
- **Lucia Trujillo**: Third-generation Colorado farmer adapting permaculture to the Front Range's semi-arid, high-altitude conditions (5,430 ft). Connected to Boulder Food Rescue for redistribution.
- **Devon Hartley**: Designs mutual credit systems inspired by GFEL workshops on commitment pooling. Wants to pilot a Boulder community currency that keeps value circulating locally.
- **Sage Clearwater**: Facilitates the Boulder Governance Circle. Background in both consensus facilitation AND DAO governance -- bridges traditional and web3 governance.
- **Zara Okafor**: CU Boulder grad student doing community-based watershed monitoring. Bridges academic research with community science data.
- **Rowan Blackwood**: Specialist in beaver dam analogs (BDAs) and low-tech process-based restoration. Hands-in-the-mud ecologist who builds structures in streams.
- **Jess Park**: Stewards the RegenHub physical space. Organizes community potlucks, hackathons, and the social glue events. Community connector.
- **Marco Rivera**: Coordinates community-owned solar in Colorado's 300+ days of sunshine. Colorado-specific cooperative energy model.
- **Talia Redhawk**: Represents Arapaho/Cheyenne relationship to Front Range watersheds. Brings indigenous water law and traditional ecological knowledge perspective.
- **Finn O'Brien**: 22-year-old bridging regen tech and watershed work. Attended first ETHBoulder, now organizing youth watershed ambassador program.
- **Boulder Admin**: Site manager account with sitemanager role.

## Content Structure

### Taxonomy Terms (Profile Tags) -- 12 terms

New terms that differ from Cascadia's vocabulary:

| Term | Why Different from Cascadia |
|------|----------------------------|
| Watershed Ecology | Same as Cascadia (shared vocabulary) |
| Riparian Restoration | New -- specific to BDA/restoration work |
| Ethereum Localism | New -- unique to Boulder's tech-regen intersection |
| Public Goods Funding | New -- quadratic funding, Gitcoin, Artizen |
| Community Currencies | New -- mutual credit, local currencies |
| Regenerative Agriculture | Same as Cascadia (shared vocabulary) |
| Permaculture Design | Same as Cascadia (shared vocabulary) |
| DAO Governance | New -- web3 governance structures |
| Community Science | New -- citizen science / monitoring |
| Community Energy | Same as Cascadia (shared vocabulary) |
| Indigenous Knowledge | Same as Cascadia (shared vocabulary) |
| Climate Action | Same as Cascadia (shared vocabulary) |

### Topics -- 10 topics (using updated types including Research and Question)

| # | Title | Type | Visibility | Group | Author | Purpose |
|---|-------|------|-----------|-------|--------|---------|
| 1 | "Welcome to the Boulder Node: Where Mountains Meet the Mesh" | Blog | public | none | boulder_admin | Long intro (300+ words) -- explains Boulder's unique position at intersection of land and tech. Parallels Cascadia's welcome post. |
| 2 | "What is Ethereum Localism? Technology in Service of Place" | Blog | community | Regen Tech | kai_regentech | Long explainer (300+ words) -- defines Ethereum localism, cosmo-local coordination, credibly-neutral infrastructure. Parallels Cascadia's bioregionalism explainer. |
| 3 | "Boulder Creek Restoration: Spring Update" | News | community | Watershed Hub | mira_watershed | Seasonal update on BDA installations, native planting, water quality data. Parallels Cascadia's watershed update. |
| 4 | "Proposal: Quadratic Funding Round for Front Range Public Goods" | Dialog | community | Regen Tech | devon_currencies | Proposes a QF round for local regenerative projects. Parallels Cascadia's solar proposal. |
| 5 | "Beaver Dam Analogs: Restoring Stream Complexity on the Front Range" | Research | community | Watershed Hub | rowan_beaver | **NEW TYPE** -- Research article on BDA methodology, data from local installations, references to LTPBR science. |
| 6 | "How Do We Bridge the Tech-Land Divide in Our Community?" | Question | community | RegenHub | jess_regenhub | **NEW TYPE** -- Open question about how to connect builders/coders with land stewards. |
| 7 | "Front Range Food Forest Design for Semi-Arid Climates" | Blog | community | Food Commons | lucia_foodcommons | Permaculture adapted to 5,400 ft elevation, 300 days sun, low rainfall. |
| 8 | "Community Currency Pilot: Lessons from Month One" | News | community | Regen Tech | devon_currencies | Update on a Boulder mutual credit experiment. |
| 9 | "Treasury Allocation Discussion: Boulder Node Q2" | Dialog | group | Governance Circle | sage_governance | PERMISSION TEST -- group-only visibility in closed group. Parallels Cascadia treasury topic. |
| 10 | "Mapping Boulder's Water Bioregion: From Continental Divide to Gulf" | Dialog | community | Watershed Hub | zara_datascience | Participatory mapping project for Front Range water systems. Parallels Cascadia's GIS project. |

### Events -- 8 events

| # | Title | When | Visibility | Group | Author | Type |
|---|-------|------|-----------|-------|--------|------|
| 1 | "Front Range Bioregional Assembly" | future +30 day | community | Watershed Hub | sage_governance | Assembly |
| 2 | "Beaver Dam Analog Build Day at Caribou Ranch" | future +14 day | public | none | rowan_beaver | Workshop |
| 3 | "RegenHub Open House & Community Potluck" | future +21 day | public | RegenHub | jess_regenhub | Social gathering |
| 4 | "Monthly Governance Circle" | past -14 day | community | Governance Circle | sage_governance | Assembly |
| 5 | "Seed Swap & High-Altitude Growing Workshop" | future +7 day | community | Food Commons | lucia_foodcommons | Social gathering |
| 6 | "Quadratic Funding Workshop: How to Run a Local QF Round" | future +45 day | community | Regen Tech | devon_currencies | Workshop |
| 7 | "Summer Solstice Gathering at Boulder Creek" | future +60 day | public | none | talia_indigenous | Social gathering |
| 8 | "Community Science Data Jam" | past -30 day | community | Watershed Hub | zara_datascience | Workshop |

### Posts -- 18 social posts

Distribution matching Cascadia's pattern:
- 1 welcome post (community, no group) -- boulder_admin
- 2 group-scoped resource sharing posts (group visibility in Watershed Hub, Regen Tech)
- 2 cross-group community updates (community, no group)
- 3 direct messages (recipient-only, visibility 0)
- 2 event-related posts (community, public)
- 2 question/mutual aid posts (community)
- 2 photo posts with images (community)
- 2 group-only posts (group visibility in Governance Circle, Food Commons)
- 2 public engagement posts (public)

### Comments -- 13+ comments

Distribution:
- 3 comments on welcome topic (threaded reply chain)
- 3 comments on QF proposal topic (threaded discussion)
- 3 comments on events (different events)
- 4+ comments on posts (across different post types)

### Likes -- 9 likes
Distributed across popular topics and posts.

### Event Enrollments -- 10 enrollments
Distributed across future events.

## Cascadia vs Boulder

### How the Two Nodes Differ and Complement Each Other

| Dimension | Cascadia Node | Boulder Node | Network Effect |
|-----------|--------------|-------------|----------------|
| **Geography** | Pacific Northwest -- Salish Sea, Nooksack, Willamette | Front Range -- Boulder Creek, South Platte, Continental Divide | Two distinct bioregions showing the network spans diverse ecosystems |
| **Timezone** | America/Los_Angeles | America/Denver | Mountain vs Pacific -- realistic timezone diversity |
| **Cultural flavor** | Rain forests, salmon runs, indigenous Coast Salish traditions | High desert, beaver dams, Arapaho/Cheyenne territory, tech culture | Different ecological vocabularies, same bioregional ethic |
| **Tech integration** | Platform as tool -- uses LocalNodes but content is land-focused | Tech as practice -- Ethereum localism, DAOs, QF actively discussed as community topics | Shows the spectrum from tech-as-infrastructure to tech-as-practice |
| **Economic models** | Mutual aid, community land trusts, seed libraries, cooperative energy | Mutual credit, community currencies, quadratic funding, retroactive public goods | Complementary economic innovations |
| **Governance** | Consensus-based council, rotating facilitation | Hybrid DAO + consensus governance, on-chain treasury | Evolution from traditional to hybrid governance models |
| **Topic types** | Blog, News, Dialog (original 3 types only) | Blog, News, Dialog, **Research**, **Question** (uses all 5 types) | Boulder showcases the two new topic types |
| **Content tone** | Warm, grounded, nature-centered | Energetic, experimental, tech-curious but place-rooted | Different community personalities, same values |
| **Key vocabulary** | Watershed, salmon, bioregion, commons, mutual aid | BDA, quadratic funding, regen tech, cosmo-local, mesh | Distinct but overlapping vocabularies |
| **Cross-references** | References "sharing knowledge with other nodes" | References "learning from Cascadia's land trust model" | Implicit network connections between nodes |

### Network Effect Content

Include 2-3 pieces of content in the Boulder node that explicitly reference the Cascadia node:
- A post mentioning "Just learned about Cascadia's community land trust model from the network -- could we adapt something similar for Front Range housing?"
- A topic or comment referencing cosmo-local knowledge sharing: "The Cascadia node's watershed monitoring protocols are excellent -- we are adapting them for Boulder Creek"
- This creates the illusion of an active inter-node network when both demos are viewed together

## Vocabulary and Tone

### Boulder-Specific Vocabulary (use naturally throughout content)

**Watershed/Ecological:**
- Beaver dam analogs (BDAs), low-tech process-based restoration (LTPBR)
- Riparian corridor, floodplain reconnection, braided streams
- South Platte, Boulder Creek, Continental Divide
- Native cottonwoods, willows, invasive Russian olive removal
- Biotic pump, transpiration, microclimate effects
- Front Range, high plains, semi-arid, 5,400 feet elevation

**Technology/Web3:**
- Ethereum localism, regen tech, cosmo-local
- Quadratic funding (QF), retroactive public goods funding (RetroPGF)
- Community currencies, mutual credit, commitment pooling
- DAO governance, on-chain treasury, composable governance
- Credibly neutral, permissionless, open-source coordination
- Public goods, protocol society, knowledge graphs
- Schelling point, vibe-coded (informal contexts only)

**Community/Organizational:**
- RegenHub, unconference, hackathon
- Community potluck, open house, build day
- Data jam, community science, citizen monitoring
- Food rescue, seed swap, farm-to-table
- Place-based, rooted, infrastructure of belonging
- Cosmo-local federation, network node

### Tone Guidance

Boulder content should feel:
- **Energetic and experimental** -- this is a community that runs hackathons AND plants trees
- **Technically literate but not jargon-heavy** -- explain web3 concepts in plain language
- **Grounded despite the tech** -- always connect technology back to place and land
- **Collaborative** -- frequent @-mentions of other personas, cross-group references
- **Self-aware** -- acknowledge the tension between tech culture and land work, treat it as a feature not a bug
- **Not crypto-bro** -- explicitly anti-speculation, pro-public-goods, "hippies with laptops" energy

## Architecture Patterns

### Module Structure

The new Boulder module follows the identical structure as `localnodes_demo`:

```
html/modules/custom/boulder_demo/
  boulder_demo.info.yml
  src/
    Plugin/
      DemoContent/
        BoulderUserTerms.php
        BoulderFile.php
        BoulderUser.php
        BoulderGroup.php
        BoulderEventType.php
        BoulderEvent.php
        BoulderTopic.php
        BoulderPost.php
        BoulderComment.php
        BoulderLike.php
        BoulderEventEnrollment.php
  content/
    entity/
      user-terms.yml
      file.yml
      user.yml
      group.yml
      event-type.yml
      event.yml
      topic.yml
      post.yml
      comment.yml
      like.yml
      event-enrollment.yml
    files/
      01.jpg ... NN.jpg
      persona1.jpg ... personaN.jpg
```

### Plugin IDs

All plugin IDs must use `boulder_` prefix to avoid collision with `localnodes_` plugins:

| Plugin ID | Entity Type | Source File |
|-----------|-------------|------------|
| boulder_user_terms | taxonomy_term | content/entity/user-terms.yml |
| boulder_file | file | content/entity/file.yml |
| boulder_user | user | content/entity/user.yml |
| boulder_group | group | content/entity/group.yml |
| boulder_event_type | taxonomy_term | content/entity/event-type.yml |
| boulder_event | node (event) | content/entity/event.yml |
| boulder_topic | node (topic) | content/entity/topic.yml |
| boulder_post | post | content/entity/post.yml |
| boulder_comment | comment | content/entity/comment.yml |
| boulder_like | vote | content/entity/like.yml |
| boulder_event_enrollment | event_enrollment | content/entity/event-enrollment.yml |

### Key YAML Differences from Cascadia

1. **Timezone**: All users use `America/Denver` instead of `America/Los_Angeles`
2. **Addresses**: All events use Boulder/Front Range locations (Boulder, CO; Longmont, CO; Nederland, CO; etc.)
3. **Topic types**: Use all 5 types including `Research` and `Question`
4. **UUIDs**: ALL fresh v4 UUIDs with ZERO overlap against both social_demo AND localnodes_demo

### Important: This Module Runs on a SEPARATE Instance

The Boulder module is NOT meant to coexist with the Cascadia localnodes_demo on the same Drupal site. Each module runs on its own separate LocalNodes instance (different database, different site). Therefore:
- UUID collisions with localnodes_demo are unlikely but should still be avoided for cleanliness
- The two modules share the same social_demo dependency for base classes
- Each instance shows its own themed content independently
- The "network effect" is simulated by cross-referencing content that mentions the other node

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Entity creation | Custom PHP entity code | social_demo base classes (same as localnodes_demo) | All edge cases already handled |
| YAML parsing | Custom parser | social_demo.yaml_parser service | Module-relative path resolution |
| Plugin discovery | Manual registration | @DemoContent annotation with `boulder_` prefix IDs | Plugin manager scans automatically |
| Group relationships | Custom group_content code | DemoNode::createGroupRelationship() | Complex type resolution handled |
| Profile creation | Manual profile entity | DemoUser::fillProfile() | Handles auto-created profile pattern |
| Image handling | Manual file management | Copy social_demo images, rename for Boulder personas | Same approach that worked for Cascadia |

## Common Pitfalls

### Pitfall 1: Using Only Old Topic Types
**What goes wrong:** Cascadia content only uses Blog, News, Dialog. Boulder content should showcase the newer Research and Question types.
**How to avoid:** Include at least 1 Research topic and 1 Question topic. The `prepareTopicType()` method loads terms by name, so use exact strings: `Research`, `Question`.
**Warning signs:** All topics showing only Blog/News/Dialog types.

### Pitfall 2: Boulder Content That Reads Like Cascadia With Different Names
**What goes wrong:** If the vocabulary, themes, and concerns are identical to Cascadia just with "Boulder" substituted, the two nodes feel like copies rather than distinct communities.
**How to avoid:** Lean into Boulder's unique threads: BDAs and stream restoration (not salmon habitat), Ethereum localism and QF (not just mutual aid), high-altitude semi-arid agriculture (not Pacific NW rain forests), Arapaho/Cheyenne context (not Coast Salish).
**Warning signs:** Content mentioning salmon, rain forests, Pacific Northwest, or Cascadia-specific references.

### Pitfall 3: Crypto-Bro Tone in Web3 Content
**What goes wrong:** Web3 content reads like a token pitch or speculation-focused crypto content, alienating the bioregional audience.
**How to avoid:** Frame all web3 content through the lens of public goods, coordination, and community service. Use "regen tech" vocabulary, not "DeFi" or "alpha." Reference ETHBoulder's anti-speculation ethos explicitly.
**Warning signs:** Content mentioning tokens, profits, trading, market caps, or "to the moon."

### Pitfall 4: Forgetting the Physical Anchor
**What goes wrong:** The tech content overwhelms the place-based content, making Boulder feel like a crypto project rather than a bioregional community.
**How to avoid:** Maintain at least 50% content about physical-world activities (watershed restoration, food growing, community gatherings). Every tech topic should connect back to place.
**Warning signs:** More than half the content is about web3 with no mention of land, water, or community spaces.

### Pitfall 5: UUID Collisions (same as Cascadia)
**What goes wrong:** Reusing UUIDs from social_demo or localnodes_demo causes entities to be silently skipped.
**How to avoid:** Generate completely fresh v4 UUIDs for ALL entities. Cross-check against both social_demo and localnodes_demo UUID sets.
**Warning signs:** "already exists" warnings during drush import.

### Pitfall 6: Wrong Event Locations
**What goes wrong:** Events set in Portland, Seattle, or other Cascadia locations rather than Boulder/Front Range.
**How to avoid:** All events use Boulder, CO area locations: Boulder (80301-80310), Longmont (80501), Nederland (80466), Lyons (80540), Louisville (80027).
**Warning signs:** Event addresses showing non-Colorado locations.

### Pitfall 7: Topic Type "Content" No Longer Exists
**What goes wrong:** Using `Content` as a topic type -- it was removed. Only Blog, News, Dialog, Research, Question exist now.
**How to avoid:** Use only the 5 valid topic type names.
**Warning signs:** Topics without topic type tags after import.

## Open Questions

1. **Module Name**
   - What we know: Should be parallel to `localnodes_demo` but themed for Boulder
   - Options: `boulder_demo`, `boulder_localnodes_demo`, `front_range_demo`
   - Recommendation: Use `boulder_demo` for simplicity and clear distinction

2. **Profile Images**
   - What we know: Cascadia reused social_demo's profile photos renamed for personas
   - Recommendation: Same approach -- copy social_demo profile photos, rename for Boulder personas. No external downloads needed.

3. **Cross-Node References**
   - What we know: The two instances are separate sites, but content should hint at network connectivity
   - Recommendation: Include 2-3 pieces of content that reference "the Cascadia node" or "other nodes in the network." Keep it natural, not forced.

## Sources

### Primary (HIGH confidence)
- https://unforced.github.io/water-bioregion/ -- Water bioregion concept, Boulder Creek watershed restoration details, restoration metrics, organizations
- https://ethboulder.xyz/ -- ETHBoulder 2026 event details, tracks, themes, speakers, community culture
- https://omniharmonic.substack.com/p/the-infrastructure-of-belonging -- Conceptual framework, shared vocabulary with Cascadia content
- Existing localnodes_demo module source code -- Complete YAML structure, entity counts, plugin patterns (direct file reads)

### Secondary (MEDIUM confidence)
- https://broadcast.opencivics.co/p/ethereum-localism-goes-cosmo-local -- Benjamin Life's Ethereum localism article, GFEL Boulder details, RegenHub partnership, OpenCivics Consortium
- https://cryptogood.substack.com/p/ethboulder-3-days-of-regen-tech-ai -- ETHBoulder 2026 recap with session details, community culture, specific projects
- https://regenhub.xyz/ -- RegenHub identity as "Boulder's Regenerative Workspace" (minimal content on site)
- https://boulder.earth/organizations/boulder-watershed-collective/ -- Boulder Watershed Collective mission and focus
- https://watershed.center/ -- The Watershed Center river restoration programs
- https://bouldercolorado.gov/guide/enjoy-and-protect-2025-open-space-projects -- City of Boulder OSMP 2025 restoration projects
- https://cusp.ws/ -- Coalition for the Upper South Platte watershed protection

### Tertiary (LOW confidence)
- WebSearch results for "Boulder Colorado bioregion" and "ETHBoulder 2026" -- used for discovery, cross-verified with primary sources above

## Metadata

**Confidence breakdown:**
- Content themes: HIGH -- Based on direct analysis of all 4 source URLs plus supplementary research on Boulder watershed organizations
- Proposed groups: HIGH -- Derived from real Boulder community structures (RegenHub, ETHBoulder, watershed organizations)
- User personas: HIGH -- Archetypes based on real roles identified in source material (restoration ecologists, regen tech builders, food system organizers, governance facilitators)
- Content structure: HIGH -- Mirrors proven Cascadia structure exactly with Boulder-specific themes
- Vocabulary: HIGH -- Drawn directly from source materials (water bioregion page, ETHBoulder site, GFEL retrospective)
- Pitfalls: HIGH -- Based on experience from Cascadia content creation (06-02-SUMMARY.md)

**Research date:** 2026-02-28
**Valid until:** 2026-06-28 (content strategy, unlikely to change)
