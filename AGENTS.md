# Agent working agreement

This repository initially contains a blueprint, not running software. On each task:

1. **Analyze:** Read README, PROJECT_STATUS, ROADMAP.json, affected modules, previous PRs, actual database schemas and relevant tests.
2. **Plan:** Identify task ID, dependencies, risk, components, authorization decisions, migration/backfill plan and explicit acceptance tests.
3. **Implement:** Make the smallest coherent change; no mock/fake/hardcoded production content, speculative API contracts, TODO/FIXME workarounds, unnecessary packages or unrelated refactors.
4. **Test:** Run relevant unit, feature, integration, authorization, concurrency, UI, accessibility and build checks. Use licensed provider sandbox/recorded real authorized fixtures only for tests; never present fixtures as live data.
5. **Fix and re-test** all failing cases.
6. **Complete:** Commit only when evidence exists. Update ROADMAP.json and PROJECT_STATUS.md with exact task ID, commands, exit codes, commit/PR link, limitations and follow-ups.

## Security
- Backend authorizes every staff and user access; default deny; least privilege; MFA for staff; preserve audit events.
- Distinguish anonymous readers, account users, staff and external advertisers. Never expose staff APIs to public sessions.
- A public multi-site platform is **not** multi-tenant by default. If advertiser tenancy is introduced, explicitly enforce advertiser_id scoping on queries, jobs, cache, objects and reporting; test IDOR/cross-tenant access.
- Sanitize rich text, validate input, protect file upload, control SSRF, use rate limits, CSP, CSRF defenses and environment secrets. No secrets or data dumps in commits.
- Do not suggest/execute destructive production database commands without explicit approval.

## Editorial automation
- No third-party content ingestion without validated grant and allowed use case.
- Factual claims must bind to deterministic, versioned evidence records.
- AI drafts must pass schema validation, claim/number checks, duplicate/near-duplicate checks and source/license checks before publication.
- Unsafe, contentious, unverifiable or breaking third-party allegations are held for human review. A human-free routine pipeline is a goal, not a reason to bypass safety.
- Article correction and retraction preserve audit history; do not rewrite silently.

## Design and languages
- Respect the approved Topkolik reference brand; do not replace or revise logo/colors without a decision record.
- iOS HIG and Android Material 3 native behaviors; web responsive WCAG 2.2 AA target. Do not copy another app's layouts or assets.
- Source UI locale `de`, plus supported locales `fr`, `it`, `en`, and owner-approved `tr` (`ADR-004`), with fallback `en` on unsupported device locales. Automatically resolve `tr` for Türkiye-based users (`Accept-Language: tr*`) when no explicit `preferred_locale` override is stored.
- Owner-approved social fan platform capabilities (`ADR-015`..`ADR-017`): segmented Match Arenas (`home`, `away`, `neutral`), match polls & reactions, free-to-play Score Predictions (1-X-2, exact score, goal range, first-scoring team; strictly zero wagering/betting), and individual + sample-size-normalized club Fan Rankings. Social posting requires operational moderation, reporting, blocking, slow-mode, and emergency lockdown controls.
- Code, identifiers, API paths, migrations, documentation and GitHub issues in English.

## Completion discipline
- Valid statuses: todo / in_progress / blocked / in_review / done.
- Complete does not mean code written. It means accepted, tested, documented and evidenced.
- Never fake a test result or claim production-ready without deployment, security and real-provider verification.