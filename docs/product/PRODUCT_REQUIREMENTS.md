# Topkolik — Product requirements (P00-001)

## Mission and product boundaries
Build a Türkiye-focused, guest-first, multi-sport news service for web, iOS and Android. Publish independently written news derived from licensed statistics, official releases and verified first-party analysis. Automation should reduce routine editor work, never weaken journalistic verification or rights checks.

## Audiences
- Readers and fans: news, transfers, scores, fixture lists, searchable leagues/teams/athletes; follow teams and receive opt-in alerts.
- Editorial operations: AI-produced drafts, source evidence, exceptions, correction/retraction flows.
- Staff: role-specific dashboards for publisher, editors, sports-data/AI operations, ad sales, finance, support and analytics.
- Advertisers: approved placements, scheduling, performance reports, invoicing and payment reconciliation; no anonymous self-serve publishing of ad creative without review.

## Must-have user journeys
1. Guest opens the news feed, selects sport/league/team, reads original coverage, sees attributable provenance and updated timestamps.
2. Guest views upcoming/live/final matches and available licensed statistical breakdown by event, team and player.
3. Reader optionally creates account, verifies email, resets password securely, selects favorite teams, and controls consent and notifications.
4. Bot ingests licensed match events; records immutable provider event IDs; awaits completed match data; generates and verifies claims; produces branded cover; publishes only when all quality/rights gates pass.
5. Editor reviews blocked stories, corrects an error while preserving version history, and can immediately unpublish/retract.
6. Ad operations approves placements, tracks delivery under privacy constraints; finance issues valid documents and reconciles settlements.
7. Support handles user tickets with audit logs, SLA ownership, abuse escalation and bounded PII access.

## Functional requirements
- Sports catalog: taxonomy, organization, competition, season, fixture, team, athlete, standings, match statistics; per-sport optional fields, never fabricated zeros.
- Editorial: deduplicated events, evidence snapshots, versioned documents, citations/source licenses, policy and confidence evaluation, drafts, correction/retraction.
- Search and SEO: server rendered crawlable news pages, original titles and content, canonical URLs, news sitemap and Article/NewsArticle structured data only when eligible.
- Mobile: Apple HIG and Android Material 3, native navigation conventions, accessibility, anonymous-first, granular permissions.
- Ads/finance: direct-sold + appropriately licensed mediated ads; inventory, campaigns, pacing, impression/click fraud controls, financial ledger, tax provider integration after legal approval.
- Admin: scoped RBAC, mandatory MFA, audit history, 4-eyes approvals for financial changes and high-risk publishing policies.

## Non-functional acceptance
- Source rights and evidence required; every published factual claim points to a source record. Auto-publish only for trusted deterministic classes, not rumors/medical claims/allegations.
- Browser/device interfaces responsive, keyboard/screen-reader accessible, WCAG 2.2 AA targets; no layout jumping during scores/news updates.
- No cross-role access, IDOR, secret exposure, unbounded API polling, duplicate publishing, open redirects, arbitrary media uploads or queue replay side effects.
- Observability: per-provider data freshness, queue lag, event throughput, false-positive quarantine, publication error rate, ad delivery and AI spend.
- Availability, SLO and retention thresholds are **open decisions**, not invented guarantees.

## Shipping gates
See `docs/qa/TEST_AND_RELEASE_PLAN.md` and `ROADMAP.json`. Provider agreements, legal publication entity, image rights, store readiness and security review must be signed off before launch.
