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
- Source UI locale `de`, then `fr`, `it`, `en`, fallback `en` on unsupported device locales. Do not add Turkish UI localization without explicit owner authorization. Turkish sports editorial articles are distinct from localized interface labels.
- Code, identifiers, API paths, migrations, documentation and GitHub issues in English.

## Completion discipline
- Valid statuses: todo / in_progress / blocked / in_review / done.
- Complete does not mean code written. It means accepted, tested, documented and evidenced.
- Never fake a test result or claim production-ready without deployment, security and real-provider verification.