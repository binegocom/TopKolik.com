# Topkolik — Project Status and Verification Log

> **NOT A WORKING PUBLIC PRODUCT.** The Laravel 13 API, verified reader auth and reader preferences/favorites are implemented; consumer web/mobile/admin, live sports data, AI publishing and production deployment are not.

**As of:** 2026-11-01  
**Release:** Not started  
**Tracked tasks:** 147 | **Documentation tasks done:** 3 | **Implementation done:** 8

## Phase status

| Gate | Phase | Tasks | Status |
|---|---|---:|---|
| `P00-GATE` | Project blueprint and governance | 4 | `in_progress` |
| `P01-GATE` | Rights, provider discovery and legal foundations | 10 | `in_progress` |
| `P02-GATE` | Repository and engineering foundation | 11 | `in_progress` |
| `P03-GATE` | Authentication, roles and privacy | 11 | `in_progress` |
| `P04-GATE` | Sports catalog and licensed ingestion | 10 | `todo` |
| `P05-GATE` | Match center, discovery and search API | 8 | `todo` |
| `P06-GATE` | Autonomous editorial and fact validation | 12 | `todo` |
| `P07-GATE` | Automated media production | 8 | `todo` |
| `P08-GATE` | Consumer web (SEO-first) | 9 | `todo` |
| `P09-GATE` | Flutter mobile iOS and Android | 11 | `todo` |
| `P10-GATE` | Newsroom admin and staff workflows | 11 | `todo` |
| `P11-GATE` | Ads sales, serving and reporting | 9 | `todo` |
| `P12-GATE` | Billing, accounting and finance | 8 | `todo` |
| `P13-GATE` | Support, personalization and analytics | 7 | `todo` |
| `P14-GATE` | Security, performance, accessibility and compliance | 10 | `todo` |
| `P15-GATE` | Release and operation | 8 | `todo` |

## Verified work (planning only)

| Task | Evidence | Verification |
|---|---|---|
| `P00-001` | `docs/product/PRODUCT_REQUIREMENTS.md` | File existence + blueprint integrity validation; no application tests |
| `P00-002` | `docs/architecture/ARCHITECTURE.md` | File existence + blueprint integrity validation; no application tests |
| `P00-003` | `docs/qa/TEST_AND_RELEASE_PLAN.md` | File existence + blueprint integrity validation; no application tests |

## Editorial safety slice — not yet integrated

- PHP preliminary publication guard in `services/api/app/Domain/Editorial/`.
- Local test: 5 PHP syntax checks and 17 unit scenarios passed on PHP 8.4.24 (2026-10-31).
- Not connected to Laravel, live provider contracts, persistence or production publishing. Only the independent API framework foundation (P02-005) is complete; editorial publishing remains unimplemented.
- `P01-003`: desk research documented; actual credentialed provider assessment still pending.

## In-progress infrastructure verification

- `P02-004` local Compose stack is in progress; 7/7 static tests and GitHub Actions PostgreSQL, Redis and Mailpit live smoke checks passed: https://github.com/binegocom/TopKolik.com/actions/runs/41244912287
- LocalStack 2026 S3 test requires an auth token, so this task is **not done**. Laravel was scaffolded later in a separate API task.
- GitHub development infrastructure PR: https://github.com/binegocom/TopKolik.com/pull/19

## Verified Laravel API foundation

- `P02-005` is **done**:  Laravel 13 skeleton, Composer lock, PostgreSQL/Redis connections, `/health` and `/ready`, request IDs, 5 feature tests (15 assertions), and 17 legacy guard scenarios confirmed: https://github.com/binegocom/TopKolik.com/actions/runs/41245895596 and https://github.com/binegocom/TopKolik.com/actions/runs/41246075824
- Live HTTP smoke **passed** on the feature branch. PR #20: https://github.com/binegocom/TopKolik.com/pull/20. No sports data ingestion, staff authorization, stories or production deployment yet.
- Initial scaffold was created safely on an isolated GitHub feature branch, preserving the existing editorial code.

## Reader authentication — backend verified

- `P03-001` registration and signed email verification: **done**. Real Mailpit SMTP delivery tested in Actions run 41247084594. Polished client confirmation screen and production email configuration remain pending.
- `P03-002` verified mobile reader sign-in and token revocation: **done**, PR #21 / Actions run 41246890524 (15 Laravel tests, 52 assertions). Scoped, time-limited Sanctum bearer tokens, `/me`, `/logout`, expiry and wrong-scope denials verified.
- Latest GitHub Actions run passed 15 Laravel tests (52 assertions) and 17 editorial checks: https://github.com/binegocom/TopKolik.com/actions/runs/41246890524
- Production email provider setup, web cookie+CSRF auth, staff RBAC/MFA and reader UI are **not complete**.
- P03-001 and P03-002 are backend-complete with recorded tests; P03-GATE is **not complete** (staff MFA/RBAC, browser session, privacy export/delete and security controls pending).

## P03-003 password reset backend — verified

- **done:** Laravel broker with hashed expiring single-use reset token, per-account/IP throttling, PostgreSQL row lock, global Sanctum and DB session revocation.
- CI evidence: https://github.com/binegocom/TopKolik.com/actions/runs/41247766322 — 26 passing Laravel tests (99 assertions), 17 editorial guard tests, real Mailpit SMTP reset message, successful Composer audit.
- UI confirmation form, production SMTP and device flows remain separate unresolved release requirements. A trusted reset URL must be configured; default is fail closed.

## Reader profile/favorites — in progress

- P03-004: reader profile, 4-language locale preference, privacy defaults and referential favorites implemented on a feature branch; PostgreSQL/CI acceptance **pending**.
- Empty sports/teams base tables are only a structural prerequisite for favorites. No providers, real catalog contents or synthetic runtime data have been added.
- Reader mobile bearer tokens add a `reader:write` ability for mutations. Old read-only tokens must reauthenticate.

## Reader profile, locale and favorites — verified P03-004

- **done:** owned reader profile updates, supported language preferences and en fallback, privacy-default flags, no reading history collection and FK-constrained sports/teams favorites.
- Test evidence: https://github.com/binegocom/TopKolik.com/actions/runs/41248701254 — 39 Laravel tests (212 assertions), real PostgreSQL and Redis, HTTP and Mailpit SMTP checks; 17 publication guard tests.
- The sports/teams tables are **empty placeholders for future licensed catalog ingestion**; no fabricated runtime fixtures, news personalization engine, client screens or production data.
- CI-green does not imply P04 taxonomy/club ingestion is complete; both P04 tasks remain TODO. PR #23: https://github.com/binegocom/TopKolik.com/pull/23

## P03-005 invite-only staff enrollment — tested

- **done:** Separate `staff_members` and `staff_invitations` tables. Invitations originate from a privileged server command, not a public HTTP endpoint. Roles are restricted by PostgreSQL check constraints.
- Acceptance requires one-time emailed secret, verifies email, hashes password and creates inactive pending-MFA staff identity. No admin login, grant or session endpoints exist.
- Verified on GitHub Actions: 54 Laravel tests (305 assertions), SMTP send + HTTP accept/replay denial, 17 editorial policy tests and Composer audit: https://github.com/binegocom/TopKolik.com/actions/runs/41249604897
- This does **not** authorize staff access: mandatory MFA, admin login and RBAC remain open. PR #24: https://github.com/binegocom/TopKolik.com/pull/24

## P03-011 reader authentication abuse controls — verified

- **done (backend security controls):** Independent per-IP (20/minute) and normalized-account (12/hour) reader login throttles prevent both multi-account password spraying and distributed per-account brute force. Reset redemption retains independent IP/account throttles, single-use tokens and all-device revocation.
- GitHub Actions run [41250002242](https://github.com/binegocom/TopKolik.com/actions/runs/41250002242) on commit `ad9e14b703400734fe2985beaf22729ea6e39a25`: **58 Laravel tests / 369 assertions passed** on disposable PostgreSQL and Redis; live HTTP and real Mailpit SMTP checks, Composer audit and 17 editorial guard cases passed. PR [#25](https://github.com/binegocom/TopKolik.com/pull/25).
- Automated adversarial regression tests are not an independent penetration test. Production trusted-proxy configuration, Redis rate-limit topology, WAF and monitoring remain release checks; no production deployment is implied.

## P03-006 staff MFA — verified

- RFC 6238 TOTP enrollment, encrypted staff secrets, short-lived setup grant, 10 single-use hashed recovery codes, separately scoped 15-minute staff sessions and 5-minute step-up are implemented on an isolated feature branch.
- Staff login requires operator activation following email proof and successful MFA. Reader bearer tokens are explicitly denied by staff route middleware.
- **done:** CI 67 Laravel tests / 486 assertions, Composer audit and real HTTP/SMTP smoke; editorial guard 17/17. https://github.com/binegocom/TopKolik.com/actions/runs/41250991386
- TOTP replay and staff token revocation/deactivation were additionally hardened based on failed adversarial tests. The mandatory RBAC and admin UI are not implemented; no administrative privilege is exposed by MFA.

## P03-007 staff authorization — foundation under review

- Laravel Gate `staff-resource` default-deny role matrix implemented for owner-bound editorial drafts and assigned support tickets.
- No publication, finance, advertising, superadmin or cross-advertiser access is granted by this foundational policy. Real resource API integration and BOLA tests remain required before P03-007 done.
- **Validated policy foundation:** https://github.com/binegocom/TopKolik.com/actions/runs/41251472836 — 79 Laravel tests / 552 assertions, PostgreSQL/Redis, real Mailpit and editorial safety passed.
- P03-007 remains **in_progress** until real resource models/controllers and authenticated end-to-end BOLA checks exist. No admin route grants are introduced.

## Open blocking decisions

- [x] Repository created: https://github.com/binegocom/TopKolik.com — baseline planning PR #1 merged; engineering code remains on an independent review PR.
- [ ] Trademark/domain/app identifiers and publisher details.
- [ ] Explicit Turkey-first UI locale decision (`tr` not silently added).
- [ ] Commercial sports-data license and real provider coverage matrix.
- [ ] Visual/photo licensing and AI provider contracts.
- [ ] Legal privacy/transfer, ads/betting exclusions and tax/e-invoice approvals.
- [ ] Hosting/monitoring and budget approvals.

## Update rules

1. Change one task in `ROADMAP.json` only after matching acceptance evidence is captured.
2. Run `python3 scripts/validate_blueprint.py` and `python3 scripts/status.py check`.
3. Refresh status with `python3 scripts/status.py render`; review the git diff.
4. On implementation tasks, record PR/commit, tests and date in the task `evidence` list; a file path alone is not sufficient.
5. Never write `done` for untested or blocked implementation work.

## Change log

- 2026-10-31 — Planning blueprint assembled; initial documentation tasks marked completed based on local artifact file integrity, **not** application implementation.
## P03-007 persisted support-ticket authorization — PR #28

- **in_progress:** Forward-only PostgreSQL `support_tickets` table, assignment FK/index, encrypted subject and message; no runtime ticket seeding or public create/mutation endpoint.
- Assigned support staff can read only their own persisted ticket at `GET /api/v1/staff/support/tickets/{ticket}` after MFA session validation, SQL assignment scoping and a fresh persisted Laravel Gate role check. Other staff assignments return 404; reader and wrong-role tokens cannot read ticket data. Sensitive responses use `Cache-Control: no-store, private`.
- Feature regression cases cover assignment BOLA, missing/expired/reader sessions, role changes, deactivation and encryption at rest. **Verified and merged:** PR [#28](https://github.com/binegocom/TopKolik.com/pull/28), merge `1baf6e95668f2da6bbf6127cf4f7a9df0e7a87ff`. [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41251992725): 85 passing tests / 573 assertions on PostgreSQL and Redis, real HTTP and SMTP, Composer audit clean. [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41251992728): PASS.
- P03-007 is **not done**. Editorial draft controllers, ticket reply/escalation, advertiser/finance scoping, audit ledger, complete permission matrix and deployment verification remain open.

## P03-007 support replies and MFA escalation — PR review

- Added encrypted, idempotent staff replies and recorded step-up escalation; both operations use locked, assignment-scoped persisted tickets and fresh role policy.
- Conversation history is available only to the assigned support operator. No consumer ticket creation, real outbound messaging, finance/ads permissions or external notification has been added.
- CI verified: 95 tests / 641 assertions on PostgreSQL/Redis, real Mailpit SMTP, 17 editorial policy tests, Composer audit: https://github.com/binegocom/TopKolik.com/actions/runs/41253330607
- Implementation remains **partial** and P03-007 stays `in_progress` until editorial models, full staff-resource authorization and advertiser/finance role scopes are built.

## P03-007 persisted editorial draft workflow — PR review

- Added editor-owned encrypted unpublished drafts; creates require active MFA editor; read/edit/submit require persisted owner SQL scope and fresh Laravel Gate permissions.
- Optimistic revisions and row locks prevent stale concurrent updates; submit moves to `in_review` but cannot publish.
- **Verified:** 106 Laravel tests / 724 assertions on PostgreSQL/Redis, real HTTP/SMTP and 17 editorial guard scenarios: https://github.com/binegocom/TopKolik.com/actions/runs/41253897269
- This is a private-editorial backend slice only; external feed ingestion, publishing, finance/ads authorization, admin UI and full BOLA acceptance are still outstanding, so P03-007 stays `in_progress`.

## P03-010 security audit ledger — tested partial implementation

- Forward-only PostgreSQL staff audit ledger, server-generated request correlation and DML append-only triggers. Privileged state changes write audit records in the same transaction; ticket reply replay does not duplicate events.
- Covers staff invitation acceptance, MFA enrollment, operator activation, login, step-up, logout, ticket reply and escalation. No credential, MFA factor, token, email or ticket body is logged.
- **in_progress, not done:** GitHub Actions run [41254268444](https://github.com/binegocom/TopKolik.com/actions/runs/41254268444) passed 110 Laravel tests / 751 assertions, PostgreSQL/Redis, HTTP, SMTP, 17 editorial guard tests and Composer audit. Database owner/superuser can bypass triggers; offsite append-only shipping, production role separation, failed-login coverage, authorized search and retention policy are release prerequisites. No production readiness claim.

## P03-008 advertiser tenant isolation — feature branch review

- A separate advertiser-account Sanctum principal, active organization and explicit active membership are required per tenant. Role changes, deactivation and token revocation invalidate access without trusting client-supplied tenant IDs.
- Private encrypted campaign drafts are scoped in SQL by organization for list/read/edit; owner/editor may write; viewer is read-only. Optimistic revision protects updates; publishing is unavailable.
- New isolated PostgreSQL BOLA tests exercise cross-tenant URLs, role downgrade, token confusion (reader/staff), mass assignment, foreign keys, check constraints and membership revocation.
- **done (backend tenant scoping only):** PR #33 initial feature-branch SHA `a676b7ed8a77efcdd050e37074d09db9fe45103d` verified in [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41257177847): 121 tests / 828 assertions, PostgreSQL, Redis, SMTP, HTTP, Composer audit, 17 editorial guard tests. [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41257177819) passed. No real advertiser onboarding, login, commercial approvals, payments, serving, queue/cache/object tenancy or production deployment is claimed.

## P03-010 staff self-audit query — PR review

- Introduced the read-only, self-scoped audit search API behind active staff identity, current persisted 5-minute MFA step-up token and explicit Gate. Query uses authenticated staff ID only, allowlisted filters, strict cursor pagination and `no-store` responses.
- New PostgreSQL integration regression tests cover cross-staff isolation, reader/advertiser denial, revoked/expired sessions, role/MFA changes, bounded filters and ledger non-mutation.
- **in_progress, partially verified:** [PR #34](https://github.com/binegocom/TopKolik.com/pull/34), feature SHA `5203da02f57ef4e0647e570d7dd2f1d2c856ae3a`. [Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41257648308) passed 129 tests / 905 assertions using PostgreSQL and Redis, real HTTP/SMTP, 17 editorial guard cases, Composer audit. [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41257648219) passed. Global incident security-role search, failed attempts, offsite immutable anchoring, production DB separation and retention remain unresolved; no release-ready claim.

## P03-009 reader privacy export and deletion request — PR review

- Owner-bound, password-reverified private JSON export of account, consent history, preferences and canonical favorites; no export file or shared cache is produced.
- Existing reader preferences now record real opt-in/withdrawal transitions in an append-only PostgreSQL consent ledger, serializing changes with the user row.
- A pending-only, idempotent deletion request and self-status endpoint are available. No deletion, session revocation, data cascade, production deletion or legal retention override is enabled.
- **in_progress** despite verified backend slice: 138 Laravel tests / 1000 assertions passed on isolated PostgreSQL/Redis, real HTTP/SMTP, 17 editorial guards and Composer audit clean in [API CI run 41258326986](https://github.com/binegocom/TopKolik.com/actions/runs/41258326986); editorial/roadmap check also passed in [run 41258327042](https://github.com/binegocom/TopKolik.com/actions/runs/41258327042). Legal/finance retention approval and safely verified erasure lifecycle remain unimplemented. [PR #35](https://github.com/binegocom/TopKolik.com/pull/35). ADR-004 remains unchanged.

## P00-004 owner decision gate — blocked

- Marked `P00-004` **blocked** because product, rights, finance, locale and legal decisions in `docs/product/DECISION_REGISTER.md` require explicit owner/vendor approval.
- Do not infer Turkish UI localization, provider rights, club imagery permissions, gambling ads, tax country or trademark clearance. Independent backend work may proceed while those decisions remain open.
- `P03-009` privacy API slice was merged in [PR #35](https://github.com/binegocom/TopKolik.com/pull/35) with 138 Laravel tests / 1000 assertions and both GitHub checks green. Actual deletion and legal/finance retention remain pending, so it stays `in_progress`.

## P03-010 failed staff authentication audit — PR review

- Added `security_auth_failures` for rejected, well-formed staff login attempts (known/unknown identities) and authenticated MFA step-up failures. No fabricated staff identity, raw email, password, IP, MFA factor or bearer token is stored; identity correlation uses an APP_KEY HMAC.
- Server-generated request UUID, explicit event allowlist, optional staff FK, indexes and database append-only DML triggers. Authentication errors retain generic 401 responses; login session issuance remains unchanged.
- Added PostgreSQL integration tests for known/unknown user indistinguishability, factor replay, success/failure separation, immutable rows, no plaintext secrets and invalid event rejection.
- **in_progress, partial backend verified:** [PR #37](https://github.com/binegocom/TopKolik.com/pull/37), feature commit `1d43da1208eae4b57471c35b669b0002f1ad0cb8`. [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41259116353) passed 146 tests / 1,054 assertions with PostgreSQL/Redis, real HTTP/SMTP, Composer audit clean, and 17 editorial guard cases. [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41259116332) passed. Rate-limit/validation/middleware denials, offsite archival, privileged DB separation, global analyst authorization, and approved retention remain unresolved; no production-ready claim.
