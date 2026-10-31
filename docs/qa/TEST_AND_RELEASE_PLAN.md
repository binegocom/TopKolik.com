# Quality, acceptance and release plan (P00-003)

## Evidence-backed completion
Task status flow: todo -> in_progress -> in_review -> done (or blocked). A code task is **done only** with linked Git revision/PR, exact test command, exit/result, acceptance checklist and verification date. Planning docs can be done on documented artifact validation only. Do not infer success from generated code.

## Continuous checks
- PHP: Composer audit, static analysis, formatting, unit + integration/feature tests, OpenAPI schema consistency, migrations on disposable databases.
- PostgreSQL: constraints, race conditions, transaction isolation, query plans and migrations with reversible non-destructive behavior.
- Next.js web/admin: TypeScript, lint, unit, accessibility, Playwright smoke and production build.
- Flutter: analyze, unit/widget/integration tests and actual simulator/emulator runs on iOS/Android.
- Security: RBAC/IDOR negatives, session revocation, MFA, CSRF, SSRF, rate limits, tenant isolation for advertisers, untrusted AI/output, upload/URL sanitization.
- AI: grounded claims, hallucination detection, false positives, duplication, provider drift, traceability, corrections and source bans.
- Ads/finance: impression event dedupe, budgets, pacing, taxes, double-entry balancing, refunds and 4-eyes finance approvals.

## End-to-end journeys
1. Guest -> choose favorite sport -> read source-attributed news -> follow/unfollow and opt-in notifications.
2. Match finalized -> verified stats -> candidate -> article+cover -> safe publish -> push -> correction -> version history.
3. Unlicensed/contradictory/late data -> quarantine without publish -> operations alert and admin inspection.
4. Reader auth -> email verification -> forgotten password -> token expiry/replay rejection -> logout everywhere.
5. Staff role grant -> MFA -> scope checks -> privileged workflow -> immutable audit.
6. Campaign approval -> inventory and targeting -> capped serving -> reports -> reconciliation/invoice.
7. Provider API outage -> alerts -> retries with limits -> state recovery with no duplicate articles.

## Go/no-go checklist
Legal publication/contact, sports and photo licenses, policies for AI-generated articles, evidence retention, security test sign-off, backups restored, monitoring + on-call, verified provider quotas/costs, accessibility, app store policy review, user privacy/consent, payment/tax compliance. A green unit test suite alone is never production approval.
