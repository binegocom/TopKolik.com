# Execution roadmap — Topkolik

147 independently tracked tasks across 16 sequential phase gates. The task IDs, actual status and acceptance evidence live in [`ROADMAP.json`](../../ROADMAP.json).

Milestones may overlap for implementation efficiency but phase gates cannot be claimed complete until all their blocking tests and dependencies pass. The project is not yet implemented or deployed.

## P00 — Project blueprint and governance
**Gate:** `P00-GATE` | **Current status:** `in_progress` | **Tasks:** 4

- [x] **P00-001 — Define Topkolik product scope and brand constraints** (`done`)  
  **Approach:** Document platforms, core outcome, target audience and approved logo/colors.  
  **Acceptance:** Brand source and PRD files exist and are internally consistent.
- [x] **P00-002 — Publish baseline architecture and domain boundaries** (`done`)  
  **Approach:** Create auditable stack, domain and backend/API architecture documentation.  
  **Acceptance:** Architecture, data model and AI pipeline documents are present.
- [x] **P00-003 — Publish quality, risk and delivery governance** (`done`)  
  **Approach:** Create release criteria, role map, security policy, process and task tracker.  
  **Acceptance:** Roadmap, AGENTS, QA plan and status files pass validation.
- [ ] **P00-004 — Approve unresolved first-release product decisions** (`todo`)  
  **Approach:** Record explicit decisions for UI locales, legal entity, domain, provider rights, finance country and advertising policy.  
  **Acceptance:** Written owner-approved ADRs recorded; no unsupported defaults.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P01 — Rights, provider discovery and legal foundations
**Gate:** `P01-GATE` | **Current status:** `todo` | **Tasks:** 10

- [ ] **P01-001 — Verify domain and trademark availability** (`todo`)  
  **Approach:** Check trademark, developer identifiers and brand conflict before public usage.  
  **Acceptance:** Trademark/domain/store-name assessment recorded.
- [ ] **P01-002 — Register publisher and responsible editorial contact** (`todo`)  
  **Approach:** Confirm legal entity, publisher identity and accessible public contact channels.  
  **Acceptance:** Approved ownership and public contact policy exists.
- [ ] **P01-003 — Evaluate sports data providers and feature coverage** (`todo`)  
  **Approach:** Matrix Turkish leagues and other sports by schedule/live/events/stats/media and price.  
  **Acceptance:** Provider probe results from legitimate credentials and exact coverage recorded.
- [ ] **P01-004 — Approve sports-data licenses** (`todo`)  
  **Approach:** Confirm contractual rights for storage, redistribution, automated derivative journalism and attribution.  
  **Acceptance:** Signed license references for each activated provider.
- [ ] **P01-005 — Approve player/photo/club asset licensing** (`todo`)  
  **Approach:** Document photographic and team mark rights separately from data rights.  
  **Acceptance:** Asset usage rights and expiry registry reviewed.
- [ ] **P01-006 — Approve privacy and international data transfer design** (`todo`)  
  **Approach:** Map purpose, retention, controllers/processors, notices, transfer mechanisms and user rights.  
  **Acceptance:** Counsel-reviewed privacy DPIA/assessment and vendor register.
- [ ] **P01-007 — Approve advertising and gambling-exclusion policy** (`todo`)  
  **Approach:** Set allowed verticals, banned campaigns, age/placement checks and creative review.  
  **Acceptance:** Documented ad policy and refusal behavior tested in admin.
- [ ] **P01-008 — Select AI vendors and contractual data controls** (`todo`)  
  **Approach:** Confirm usage rights, data retention, model costs and output handling.  
  **Acceptance:** Approved AI provider ADR and spend ceiling.
- [ ] **P01-009 — Select tax jurisdiction and invoicing path** (`todo`)  
  **Approach:** Confirm billing entity, VAT/KDV/e-invoice provider, tax data and retention rules.  
  **Acceptance:** Professional finance/tax signoff for accounting flows.
- [ ] **P01-010 — Approve editorial AI policy and high-risk hold rules** (`todo`)  
  **Approach:** Define source truth, banned topics, correction and disclosure rules.  
  **Acceptance:** Signed editorial policy with automated quarantine tests specified.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P02 — Repository and engineering foundation
**Gate:** `P02-GATE` | **Current status:** `todo` | **Tasks:** 11

- [ ] **P02-001 — Initialize protected GitHub monorepo** (`todo`)  
  **Approach:** Create standard folders while preserving any existing files.  
  **Acceptance:** Repo clone and branch protection visible.
- [ ] **P02-002 — Configure status tracking and issue templates** (`todo`)  
  **Approach:** Import roadmap task IDs, milestones, issue/PR templates and owner labels.  
  **Acceptance:** GitHub issues match ROADMAP.json IDs with evidence fields.
- [ ] **P02-003 — Pin language runtimes and dependency versions** (`todo`)  
  **Approach:** Write tested version manifests and reproducible installs.  
  **Acceptance:** Local installs repeat with lockfiles and version checks.
- [ ] **P02-004 — Create developer Docker environment** (`todo`)  
  **Approach:** PostgreSQL, Redis, S3 emulator, mail catcher and health checks without data loss.  
  **Acceptance:** Fresh local startup with compose validation and isolated volumes.
- [ ] **P02-005 — Scaffold Laravel modular API** (`todo`)  
  **Approach:** Create modules, error envelope, request IDs, logging, middleware and readiness.  
  **Acceptance:** Composer lint/type/test and API health check pass.
- [ ] **P02-006 — Scaffold Next.js public web** (`todo`)  
  **Approach:** App Router, SSR route foundation, shared token integration.  
  **Acceptance:** Typecheck, build and server smoke pass.
- [ ] **P02-007 — Scaffold Next.js admin** (`todo`)  
  **Approach:** Independent auth boundary, route guards and admin design shell.  
  **Acceptance:** Typecheck/build; unauthorized access denied.
- [ ] **P02-008 — Scaffold Flutter iOS and Android** (`todo`)  
  **Approach:** Feature-first structure, adaptive nav, API client/error handling and app themes.  
  **Acceptance:** flutter analyze, flutter test, iOS/Android compile checks pass.
- [ ] **P02-009 — Define OpenAPI and typed client generation** (`todo`)  
  **Approach:** API versioning, pagination, errors, auth and generated TS/Dart types.  
  **Acceptance:** Contract validation and generated clients build.
- [ ] **P02-010 — Configure CI and local/self-hosted runner options** (`todo`)  
  **Approach:** Protected workflows, secrets boundary, test matrix and dependency scanning.  
  **Acceptance:** Fresh PR runs required checks without secrets exposure.
- [ ] **P02-011 — Set ADR, release notes and dependency audit protocol** (`todo`)  
  **Approach:** Require ADR for major choices and regular package policy checks.  
  **Acceptance:** PR checklist and release policy validated.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P03 — Authentication, roles and privacy
**Gate:** `P03-GATE` | **Current status:** `todo` | **Tasks:** 11

- [ ] **P03-001 — Implement consumer registration and email verification** (`todo`)  
  **Approach:** Optional email registration, validation, secure password hash and verification token.  
  **Acceptance:** Guest access persists; verification and abuse cases pass.
- [ ] **P03-002 — Implement consumer login, logout and sessions** (`todo`)  
  **Approach:** Secure cookie/token strategy and session revocation.  
  **Acceptance:** Wrong credentials and expired sessions safely fail.
- [ ] **P03-003 — Implement forgot/reset password** (`todo`)  
  **Approach:** Hashed single-use expiring tokens and reset session invalidation.  
  **Acceptance:** Token replay, account enumeration and rate-limit tests pass.
- [ ] **P03-004 — Implement reader profile and preferences** (`todo`)  
  **Approach:** Support privacy settings, favorites, history minimization and user locale.  
  **Acceptance:** Authorization and data persistence tests pass.
- [ ] **P03-005 — Implement staff invite-only enrollment** (`todo`)  
  **Approach:** Separate staff membership, verified email and no public enrollment path.  
  **Acceptance:** No reader can self-assign any staff role.
- [ ] **P03-006 — Implement staff MFA and recovery** (`todo`)  
  **Approach:** TOTP/passkey approach approved, mandatory MFA, recovery and step-up.  
  **Acceptance:** MFA bypass and recovery tests pass.
- [ ] **P03-007 — Implement RBAC permissions and policy enforcement** (`todo`)  
  **Approach:** Backend policies, role scopes, resource states and default deny.  
  **Acceptance:** Full role matrix + BOLA tests pass.
- [ ] **P03-008 — Implement advertiser organization scoping** (`todo`)  
  **Approach:** Advertiser membership and query/queue/export/cache isolation.  
  **Acceptance:** Cross-advertiser IDOR tests pass.
- [ ] **P03-009 — Implement account deletion/export and consent** (`todo`)  
  **Approach:** Verified privacy requests, consent and retention exceptions.  
  **Acceptance:** Export/delete with finance/legal retention tests pass.
- [ ] **P03-010 — Implement security audit events** (`todo`)  
  **Approach:** Record authentication, role grants and sensitive actions without secrets.  
  **Acceptance:** Audit records immutable and searchable by authorized staff.
- [ ] **P03-011 — Implement supportable session/security controls** (`todo`)  
  **Approach:** Device sessions, force revoke, rate limits, risk alerts and throttling.  
  **Acceptance:** Pen tests for brute-force, reset and revocation pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P04 — Sports catalog and licensed ingestion
**Gate:** `P04-GATE` | **Current status:** `todo` | **Tasks:** 10

- [ ] **P04-001 — Implement sports taxonomy and competition mapping** (`todo`)  
  **Approach:** Model sports, competitions, seasons and coverage metadata.  
  **Acceptance:** Unique mappings and sport taxonomy tests pass.
- [ ] **P04-002 — Implement club/team/athlete/venue model** (`todo`)  
  **Approach:** Canonical identities, multi-team athlete history and provider maps.  
  **Acceptance:** FK and source map tests pass.
- [ ] **P04-003 — Implement fixture/event domain model** (`todo`)  
  **Approach:** Sport-neutral fixture/event types plus sport-specific extensions.  
  **Acceptance:** Out-of-order event and status transition tests pass.
- [ ] **P04-004 — Implement normalized statistics dictionary** (`todo`)  
  **Approach:** Units, denominators, periods, supported sports and missing data.  
  **Acceptance:** Stats never conflated across sports/periods.
- [ ] **P04-005 — Implement provider adapter contract** (`todo`)  
  **Approach:** Authentication, quotas, caching and typed provider interface.  
  **Acceptance:** Contract tests with legitimately acquired provider payloads.
- [ ] **P04-006 — Implement first licensed football provider** (`todo`)  
  **Approach:** Ingest Turkey prioritized competition coverage and revisions.  
  **Acceptance:** Live provider sandbox/authorized integration verified.
- [ ] **P04-007 — Implement basketball and volleyball provider coverage** (`todo`)  
  **Approach:** Ingest approved sports with explicit capability mapping.  
  **Acceptance:** Coverage and league/sample event test matrix verified.
- [ ] **P04-008 — Implement remaining sports adapter expansion** (`todo`)  
  **Approach:** Add only verified sport/competition entitlements in phases.  
  **Acceptance:** Every enabled sport has capabilities and alerting checks.
- [ ] **P04-009 — Implement provenance and conflict records** (`todo`)  
  **Approach:** Snapshot, observation, source revision, reconciliation and license expiry.  
  **Acceptance:** Conflicting versions trigger hold, not wrong publication.
- [ ] **P04-010 — Implement live ingestion scheduling** (`todo`)  
  **Approach:** Rate-limited polling or webhooks, backoff/circuit breakers and freshness.  
  **Acceptance:** Load/latency/outage tests pass within provider quota.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P05 — Match center, discovery and search API
**Gate:** `P05-GATE` | **Current status:** `todo` | **Tasks:** 8

- [ ] **P05-001 — Implement fixtures and scores public endpoints** (`todo`)  
  **Approach:** Schedule, live, results by sport/league/date with pagination.  
  **Acceptance:** OpenAPI contract and real-source read tests pass.
- [ ] **P05-002 — Implement match timeline and team stats API** (`todo`)  
  **Approach:** Expose only licensed available facts with provenance.  
  **Acceptance:** Missing/VAR/corrected events displayed accurately.
- [ ] **P05-003 — Implement lineups, standings and rankings** (`todo`)  
  **Approach:** Sport-specific point systems and source contracts.  
  **Acceptance:** Standings reproducibility and sport rules tested.
- [ ] **P05-004 — Implement team/athlete/league detail endpoints** (`todo`)  
  **Approach:** Joined content, fixtures, standings and profile facts.  
  **Acceptance:** Authorization/latency/query count tests pass.
- [ ] **P05-005 — Implement entity search and typo tolerance** (`todo`)  
  **Approach:** Search sports, athletes, clubs, competitions and articles.  
  **Acceptance:** Relevance, special characters and empty results pass.
- [ ] **P05-006 — Implement follow and saved-content services** (`todo`)  
  **Approach:** Public preference fallback, user-linked follow, opt in.  
  **Acceptance:** Data consistency/ownership tests pass.
- [ ] **P05-007 — Implement caching and invalidation policy** (`todo`)  
  **Approach:** Cache live states with short TTL; public articles with event invalidation.  
  **Acceptance:** Staleness and unpublished leakage tests pass.
- [ ] **P05-008 — Implement API usage/rate controls** (`todo`)  
  **Approach:** Public vs privileged quotas, consistent errors and abuse thresholds.  
  **Acceptance:** Load and rate-limiting tests pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P06 — Autonomous editorial and fact validation
**Gate:** `P06-GATE` | **Current status:** `todo` | **Tasks:** 12

- [ ] **P06-001 — Implement deterministic story signal rules** (`todo`)  
  **Approach:** Emit candidates for final scores, verified events and official announcements.  
  **Acceptance:** No news emitted for missing/conflicted/provisional facts.
- [ ] **P06-002 — Implement evidence-bound fact packs** (`todo`)  
  **Approach:** Use typed, versioned metric facts and licensed source references.  
  **Acceptance:** Every required claim links to exact source evidence.
- [ ] **P06-003 — Implement AI schema and prompt version registry** (`todo`)  
  **Approach:** Model-safe JSON contract, cost attribution and prompt lineage.  
  **Acceptance:** Schema and prompt-injection adversarial tests pass.
- [ ] **P06-004 — Implement AI article drafting pipeline** (`todo`)  
  **Approach:** Generate title/lead/body/summary from only provided fact packs.  
  **Acceptance:** Claims are structured and no unsupported values pass.
- [ ] **P06-005 — Implement numerical/identity claim validation** (`todo`)  
  **Approach:** Exact scores, names, times, metrics and source version verification.  
  **Acceptance:** Wrong numbers/names rejected in test suite.
- [ ] **P06-006 — Implement cross-source and high-risk review gate** (`todo`)  
  **Approach:** Quarantine rumors, allegations, injuries, contradictions and rights gaps.  
  **Acceptance:** No high-risk story auto-publishes.
- [ ] **P06-007 — Implement semantic and event duplicate suppression** (`todo`)  
  **Approach:** Unique keys plus similarity detection without deleting valid corrections.  
  **Acceptance:** Parallel ingest produces one canonical article.
- [ ] **P06-008 — Implement autonomous safe publishing state machine** (`todo`)  
  **Approach:** Transactional approval, publication records and outbox.  
  **Acceptance:** No partial publish, retry-safe concurrency tests.
- [ ] **P06-009 — Implement correction/retraction lifecycle** (`todo`)  
  **Approach:** Surface change reasons, history, cache purge and corrected syndication.  
  **Acceptance:** VAR/result corrections update exact article safely.
- [ ] **P06-010 — Implement editorial quality analytics** (`todo`)  
  **Approach:** Track factual issues, duplicates, source lag and publish latency.  
  **Acceptance:** Quality dashboard recomputes from measured events.
- [ ] **P06-011 — Implement source-aware official transfer reporting** (`todo`)  
  **Approach:** Differentiate confirmation from market rumor and forbid inference.  
  **Acceptance:** Unverified transfer never presented as confirmed.
- [ ] **P06-012 — Implement emergency editorial kill switch** (`todo`)  
  **Approach:** Stop automated publishing globally, sport or provider with audit.  
  **Acceptance:** Circuit-breaker rehearsal halts publication.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P07 — Automated media production
**Gate:** `P07-GATE` | **Current status:** `todo` | **Tasks:** 8

- [ ] **P07-001 — Create vector production logo and master tokens** (`todo`)  
  **Approach:** Extract approved brand icon/wordmark into editable approved masters.  
  **Acceptance:** Owner-approved assets validated at small icon sizes.
- [ ] **P07-002 — Implement article hero visual templates** (`todo`)  
  **Approach:** 16:9, 4:3, 1:1 safe layouts for result and news graphics.  
  **Acceptance:** Correct names/scores at all crop sizes and legible alt.
- [ ] **P07-003 — Implement authorized image asset registry** (`todo`)  
  **Approach:** Store license terms, expiry, attribution and allowed platforms.  
  **Acceptance:** Unlicensed or expired image cannot publish.
- [ ] **P07-004 — Integrate image generation vendor** (`todo`)  
  **Approach:** Abstract editorial background generation with usage/cost limits.  
  **Acceptance:** Provider production test and content policy pass.
- [ ] **P07-005 — Implement deterministic text/stat overlays** (`todo`)  
  **Approach:** Exact typography and verified score drawn by renderer, not LLM.  
  **Acceptance:** Pixel and content validation rejects malformed outputs.
- [ ] **P07-006 — Implement derivatives and object storage security** (`todo`)  
  **Approach:** Private pending media, signed previews, public approved renditions.  
  **Acceptance:** Unauthenticated access to unpublished media denied.
- [ ] **P07-007 — Implement media audit and correction** (`todo`)  
  **Approach:** Track revision and image substitutions after new facts.  
  **Acceptance:** Old image invalidation and license takedown tests pass.
- [ ] **P07-008 — Export native app/OG icons and image assets** (`todo`)  
  **Approach:** iOS/Android adaptive, dark/tinted and web media variants.  
  **Acceptance:** Real device icon review and store asset checks pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P08 — Consumer web (SEO-first)
**Gate:** `P08-GATE` | **Current status:** `todo` | **Tasks:** 9

- [ ] **P08-001 — Implement public layout/header/footer** (`todo`)  
  **Approach:** Responsive accessible layout with real API navigation.  
  **Acceptance:** 320–1920px responsive and keyboard tests pass.
- [ ] **P08-002 — Implement homepage and latest news feeds** (`todo`)  
  **Approach:** Hero, breaking, live strip, sport filters, news stream.  
  **Acceptance:** SSR, real data, freshness and empty state tests pass.
- [ ] **P08-003 — Implement article pages and correction disclosure** (`todo`)  
  **Approach:** Readable content, sources, AI notice, dates and linked entities.  
  **Acceptance:** Correct canonical/SSR/metadata checks pass.
- [ ] **P08-004 — Implement match center and match detail web** (`todo`)  
  **Approach:** Fixtures, scores, stats, lineups and timelines where licensed.  
  **Acceptance:** Live source end-to-end and mobile responsive checks pass.
- [ ] **P08-005 — Implement sport/team/league/athlete web** (`todo`)  
  **Approach:** Reusable category pages with genuine data and internal links.  
  **Acceptance:** Entity mapping and crawlable links verified.
- [ ] **P08-006 — Implement search, follow, save and auth web** (`todo`)  
  **Approach:** Search with filters; account functions where relevant.  
  **Acceptance:** Protected actions deny guest and IDOR tests pass.
- [ ] **P08-007 — Implement SEO structured data and sitemaps** (`todo`)  
  **Approach:** NewsArticle, publisher/contact, canonical, robots, news sitemap.  
  **Acceptance:** Rich Results Test and sitemap freshness checks pass.
- [ ] **P08-008 — Integrate policy-compliant public ad slots** (`todo`)  
  **Approach:** Reserved ad containers with labels and exclusion zones.  
  **Acceptance:** No CLS spike, editorial vs sponsored distinction tested.
- [ ] **P08-009 — Implement analytics and consent UX** (`todo`)  
  **Approach:** First-party page events/privacy/legal controls.  
  **Acceptance:** Consent and opt-out events verified.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P09 — Flutter mobile iOS and Android
**Gate:** `P09-GATE` | **Current status:** `todo` | **Tasks:** 11

- [ ] **P09-001 — Implement native-adaptive navigation and theme** (`todo`)  
  **Approach:** iOS HIG tabs; Android Material 3; Topkolik tokens.  
  **Acceptance:** Screen reader, back, text scaling and icon tests.
- [ ] **P09-002 — Implement onboarding and guest-first reader experience** (`todo`)  
  **Approach:** Skippable sport/team favorites, no mandatory account.  
  **Acceptance:** iOS/Android onboarding E2E tests pass.
- [ ] **P09-003 — Implement mobile home and breaking news** (`todo`)  
  **Approach:** Real feed, live ticker and personalized favorites.  
  **Acceptance:** Offline/error/empty and fast refresh tests pass.
- [ ] **P09-004 — Implement mobile article reading and sharing** (`todo`)  
  **Approach:** Hero image, evidence disclosure, corrections, share/save.  
  **Acceptance:** Real article navigation and accessibility tests pass.
- [ ] **P09-005 — Implement mobile fixtures and match detail** (`todo`)  
  **Approach:** Sport-specific results, data missing states and timelines.  
  **Acceptance:** Cross-sport live provider E2E tests pass.
- [ ] **P09-006 — Implement mobile discover/search/entities** (`todo`)  
  **Approach:** Teams, sports, athletes, leagues and search.  
  **Acceptance:** Index, pagination, keyboard tests pass.
- [ ] **P09-007 — Implement consumer login/registration/reset on mobile** (`todo`)  
  **Approach:** Secure auth token/session handling and recovery.  
  **Acceptance:** Authentication replay/revocation E2E tests pass.
- [ ] **P09-008 — Implement follow and push preferences** (`todo`)  
  **Approach:** Opt-in notification channels, tokens and user interests.  
  **Acceptance:** Permission, revoke, invalid token cleanup tests pass.
- [ ] **P09-009 — Implement offline-safe reading cache** (`todo`)  
  **Approach:** Cache licensed public articles only, with retraction awareness.  
  **Acceptance:** Offline/privacy and correction tests pass.
- [ ] **P09-010 — Implement iOS store and Android assets** (`todo`)  
  **Approach:** Icons, launch screens, metadata, privacy links and release config.  
  **Acceptance:** Store validation and real device screenshots pass.
- [ ] **P09-011 — Run device accessibility and performance tests** (`todo`)  
  **Approach:** VoiceOver/TalkBack, dynamic type, back navigation, start latency.  
  **Acceptance:** Signed build and physical device test evidence recorded.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P10 — Newsroom admin and staff workflows
**Gate:** `P10-GATE` | **Current status:** `todo` | **Tasks:** 11

- [ ] **P10-001 — Implement admin design shell and RBAC routes** (`todo`)  
  **Approach:** Sidebar, search, command bar, identity and permission guards.  
  **Acceptance:** Unauthorized screen/API navigation denied.
- [ ] **P10-002 — Implement operational dashboard** (`todo`)  
  **Approach:** Real monitored metrics, bot status, incidents and release flags.  
  **Acceptance:** Metrics reconcile with source records.
- [ ] **P10-003 — Implement editorial review queue** (`todo`)  
  **Approach:** Evidence, risk flags, duplicate candidates and safe manual decisions.  
  **Acceptance:** Reviewer permissions and audit tests pass.
- [ ] **P10-004 — Implement article editor and revisions** (`todo`)  
  **Approach:** Structured rich text, proofing, preview, publish/correct/retract.  
  **Acceptance:** Sanitization, saved revisions and reviewer test pass.
- [ ] **P10-005 — Implement AI bot management** (`todo`)  
  **Approach:** Queue health, per-bot configuration, budgets and emergency stop.  
  **Acceptance:** Paused jobs cannot sneak through to publish.
- [ ] **P10-006 — Implement sports data source operations** (`todo`)  
  **Approach:** Coverage matrix, sync status, conflicts, retries and license checks.  
  **Acceptance:** Stale provider and conflict incident tests pass.
- [ ] **P10-007 — Implement media library and rights management** (`todo`)  
  **Approach:** Preview/private approval and license restrictions.  
  **Acceptance:** Invalid rights and media leakage tests pass.
- [ ] **P10-008 — Implement notifications administration** (`todo`)  
  **Approach:** Targeting by follows and permitted campaign categories.  
  **Acceptance:** Send only after publish and consent tests pass.
- [ ] **P10-009 — Implement SEO and publication operations** (`todo`)  
  **Approach:** Preview, structured metadata, re-indexing and public cache purge.  
  **Acceptance:** No drafts indexed; released metadata verified.
- [ ] **P10-010 — Implement audit, roles and account administration** (`todo`)  
  **Approach:** Staff onboarding, MFA resets under policy and audit exports.  
  **Acceptance:** Privilege escalation and break-glass tests pass.
- [ ] **P10-011 — Implement admin responsive/accessibility QA** (`todo`)  
  **Approach:** Dense desktop tables and safe small-screen operations.  
  **Acceptance:** Keyboard/screen reader/viewport tests pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P11 — Ads sales, serving and reporting
**Gate:** `P11-GATE` | **Current status:** `todo` | **Tasks:** 9

- [ ] **P11-001 — Implement advertiser account data model** (`todo`)  
  **Approach:** Organization, contacts, identity and staff permissions.  
  **Acceptance:** Cross-advertiser scope tests pass.
- [ ] **P11-002 — Implement inventory and ad placement management** (`todo`)  
  **Approach:** Slots by platform/content/sport with responsive reserved sizes.  
  **Acceptance:** No unapproved content rendered.
- [ ] **P11-003 — Implement contract and campaign creation** (`todo`)  
  **Approach:** Contract version, budget, dates, creative/targeting and approval.  
  **Acceptance:** Invalid dates/budget/approval rejected.
- [ ] **P11-004 — Implement creative review and policy checks** (`todo`)  
  **Approach:** Malware/content moderation, restricted ad categories and audit.  
  **Acceptance:** Disallowed betting/illegal ads blocked by default.
- [ ] **P11-005 — Implement ad scheduling, pacing and cap enforcement** (`todo`)  
  **Approach:** Atomic spend/impression control with device/user privacy rules.  
  **Acceptance:** Concurrent requests never exceed agreed cap rules.
- [ ] **P11-006 — Implement web/native app ad rendering** (`todo`)  
  **Approach:** Clearly labeled ad placements with no hidden click traps.  
  **Acceptance:** Store policies/accessibility and layout checks pass.
- [ ] **P11-007 — Implement impression/click collection** (`todo`)  
  **Approach:** Dedup, anti-fraud and privacy-friendly measurement.  
  **Acceptance:** Idempotent reporting and bot filtering verified.
- [ ] **P11-008 — Implement campaign and revenue reports** (`todo`)  
  **Approach:** Explainable contract/delivery/invoice/report reconciliation.  
  **Acceptance:** Reports match event source and financial records.
- [ ] **P11-009 — Implement future advertiser portal authorization** (`todo`)  
  **Approach:** Only scoped advertisers see their own campaigns and invoices.  
  **Acceptance:** Cross-tenant penetration tests pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P12 — Billing, accounting and finance
**Gate:** `P12-GATE` | **Current status:** `todo` | **Tasks:** 8

- [ ] **P12-001 — Implement business and tax identity validation** (`todo`)  
  **Approach:** Store payer data with protected fields and approved requirements.  
  **Acceptance:** Country/tax validation and access tests pass.
- [ ] **P12-002 — Implement billable events and pricing** (`todo`)  
  **Approach:** Contracts, currencies, amounts, unit quantities, tax rules.  
  **Acceptance:** Integer math, rounding and snapshot tests pass.
- [ ] **P12-003 — Implement invoice issuance state machine** (`todo`)  
  **Approach:** Draft, issue, send, due, paid, void/credit statuses.  
  **Acceptance:** Posted invoices immutable and validated.
- [ ] **P12-004 — Implement payments and reconciliation** (`todo`)  
  **Approach:** Manual or selected gateway receipts with idempotency.  
  **Acceptance:** Duplicate events cannot double-apply payment.
- [ ] **P12-005 — Implement credit notes and dispute workflow** (`todo`)  
  **Approach:** Compensating financial records with linked reason.  
  **Acceptance:** Totals/reversal audit and permission tests pass.
- [ ] **P12-006 — Implement approved e-invoice/accounting export** (`todo`)  
  **Approach:** Only after approved local provider and fiscal spec.  
  **Acceptance:** Provider sandbox or approved real integration verified.
- [ ] **P12-007 — Implement finance dashboards and exports** (`todo`)  
  **Approach:** Receivables, overdue, tax, campaign revenue and currency.  
  **Acceptance:** Independent SQL reconciliation and access tests pass.
- [ ] **P12-008 — Implement financial audit and retention** (`todo`)  
  **Approach:** Log sensitive changes and legal retention policies.  
  **Acceptance:** Tamper/authorization tests pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P13 — Support, personalization and analytics
**Gate:** `P13-GATE` | **Current status:** `todo` | **Tasks:** 7

- [ ] **P13-001 — Implement help center, contact and editorial corrections** (`todo`)  
  **Approach:** Public correction/privacy/rights/contact forms with anti-spam.  
  **Acceptance:** Routing, email and ticket creation tests pass.
- [ ] **P13-002 — Implement ticket lifecycle and staff scopes** (`todo`)  
  **Approach:** Assign, prioritize, SLA, response, closure and attachment checks.  
  **Acceptance:** Sensitive ticket IDOR and attachment leakage tests pass.
- [ ] **P13-003 — Implement notification subscriptions and delivery** (`todo`)  
  **Approach:** FCM/APNs opt-in, preferences and idempotent delivery.  
  **Acceptance:** Invalid/stale device token handling verified.
- [ ] **P13-004 — Implement privacy-aware event analytics** (`todo`)  
  **Approach:** Event schema, consent controls and aggregation definitions.  
  **Acceptance:** Metric reproducibility and opt-out tests pass.
- [ ] **P13-005 — Implement recommendation ranking v1** (`todo`)  
  **Approach:** Freshness, interests, relevance, source credibility, diversity.  
  **Acceptance:** No contentless trending noise or repeated events.
- [ ] **P13-006 — Implement editorial/content and finance dashboards** (`todo`)  
  **Approach:** Quality, reach, costs, ad and support KPIs by authorization.  
  **Acceptance:** Dashboards reconcile against raw events.
- [ ] **P13-007 — Implement alerting for correction and removal requests** (`todo`)  
  **Approach:** Urgent content rights/privacy tickets and review deadlines.  
  **Acceptance:** Escalation/runbook rehearsal pass.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P14 — Security, performance, accessibility and compliance
**Gate:** `P14-GATE` | **Current status:** `todo` | **Tasks:** 10

- [ ] **P14-001 — Run identity and authorization penetration suite** (`todo`)  
  **Approach:** BOLA, BFLA, MFA, tokens, session replay and rate-limit paths.  
  **Acceptance:** No unresolved high/critical auth issues.
- [ ] **P14-002 — Run media/source and web security suite** (`todo`)  
  **Approach:** Signed URLs, SSRF, CSP, XSS, CSRF, path traversal and upload scanning.  
  **Acceptance:** No unpublished assets or provider secrets exposed.
- [ ] **P14-003 — Run high-concurrency publishing and billing suite** (`todo`)  
  **Approach:** Race conditions for final score, AI retries, ads and invoices.  
  **Acceptance:** Idempotency and invariants under load pass.
- [ ] **P14-004 — Run WCAG and native UX audit** (`todo`)  
  **Approach:** WCAG 2.2 AA target; HIG/Material 3 device reviews.  
  **Acceptance:** All critical/major a11y findings corrected.
- [ ] **P14-005 — Run SEO and quality validation** (`todo`)  
  **Approach:** Structured data, render freshness, canonical, thin content, news sitemap.  
  **Acceptance:** Rich Results Test and crawl tests pass.
- [ ] **P14-006 — Run source rights and privacy compliance review** (`todo`)  
  **Approach:** Recheck provider rights, privacy, ads and takedown process.  
  **Acceptance:** Approval records current and signed.
- [ ] **P14-007 — Run backup/PITR recovery drill** (`todo`)  
  **Approach:** Restore encrypted backup into isolated environment.  
  **Acceptance:** Measured RPO/RTO and integrity evidence.
- [ ] **P14-008 — Run performance and operational load testing** (`todo`)  
  **Approach:** Peak derby traffic, worker lag, throttling, source outages.  
  **Acceptance:** Measured SLOs and cost boundaries signed off.
- [ ] **P14-009 — Run real-data staged end-to-end tests** (`todo`)  
  **Approach:** Actual licensed sports integrations, real mobile/admin/browser flows.  
  **Acceptance:** Reconciled reports, screenshots and logs stored.
- [ ] **P14-010 — Complete incident runbooks and on-call ownership** (`todo`)  
  **Approach:** Wrong score, breach, license expiry, billing double issue, provider failure.  
  **Acceptance:** Named incident owners and exercises documented.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.

## P15 — Release and operation
**Gate:** `P15-GATE` | **Current status:** `todo` | **Tasks:** 8

- [ ] **P15-001 — Prepare store listings and legal documentation** (`todo`)  
  **Approach:** Store declarations, age rating, privacy/contact and AI editorial disclosures.  
  **Acceptance:** Apple/Google policy checklist signed.
- [ ] **P15-002 — Validate signing and release builds** (`todo`)  
  **Approach:** Real signing, release reproducibility and secure distribution.  
  **Acceptance:** iOS/TestFlight and Android/internal testing build evidence.
- [ ] **P15-003 — Provision production infrastructure securely** (`todo`)  
  **Approach:** Network, credentials, databases, queues, storage and monitoring.  
  **Acceptance:** Staging-prod parity, secrets and TLS validation.
- [ ] **P15-004 — Deploy staging and exercise rollback** (`todo`)  
  **Approach:** Migration safeguards, backup, smoke and rollback tests.  
  **Acceptance:** Failed deploy recovers without data loss.
- [ ] **P15-005 — Approve production GO/NO-GO** (`todo`)  
  **Approach:** Collect legal, security, quality, provider and finance evidence.  
  **Acceptance:** Explicit owner sign-off of zero blocking findings.
- [ ] **P15-006 — Launch web and mobile in controlled rollout** (`todo`)  
  **Approach:** Phased exposure, platform health monitoring and alerts.  
  **Acceptance:** Store publication and production smoke logged.
- [ ] **P15-007 — Monitor launch health and corrections** (`todo`)  
  **Approach:** Track wrong-score incidents, publication delay, API budget and ad safety.  
  **Acceptance:** Post-launch incident and KPI report generated.
- [ ] **P15-008 — Establish ongoing maintenance cadence** (`todo`)  
  **Approach:** Dependency security updates, backups and provider contract/rights reviews.  
  **Acceptance:** Owner, calendar and test routine documented.

**Gate condition:** all tasks have test-backed evidence, relevant legal/source-rights blockers are cleared, and acceptance is reviewed.
