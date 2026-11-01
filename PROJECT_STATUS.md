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

## P03-007 assigned advertising campaign review — feature branch

- Introduced inactive-by-default `staff_advertiser_assignments` with real staff/organization foreign keys and uniqueness. Only active MFA-qualified staff with current `ads` role, active explicit assignment and active advertiser organization may list submitted campaign metadata.
- Reading creative title/body requires a fresh five-minute MFA step-up token and SQL-scoped in-review campaign. Advertiser drafts and foreign-tenant IDs are inaccessible; no write, approve, publish, invoice or finance permission is exposed.
- New PostgreSQL integration tests cover cross-organization IDOR/BOLA, draft filtering, revocation, role change, MFA, wrong token principal, cursor bounds and default-deny operations.
- **in_progress, ads review backend slice verified:** [PR #38](https://github.com/binegocom/TopKolik.com/pull/38) final code SHA `1833d938129561e3faa9071bed7a0821c2821e33`. [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41259764571) passed 156 tests and 1,133 assertions with isolated PostgreSQL/Redis, API HTTP/SMTP, Composer audit and 17 editorial guards; [editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41259764572) passed. Finance dual approval, secure assignment administration, publishing and full RBAC review remain unimplemented; no production release claim.

## P03-007 advertiser submission to staff review — feature-branch verification

- Added `POST /api/v1/advertiser/organizations/{organization}/campaigns/{campaign}/submit` for active advertiser owner/editor membership, current revision and strict organization-scoped SQL. One-way `draft → in_review` only, with row lock and conditional version update; attempts to change status/tenant/reviewer/body are rejected.
- Added append-only `advertiser_campaign_review_events` ledger inside the same transaction as status/revision mutation, linked to actual advertiser account, campaign, organization and server request UUID. No personal credential or raw creative content is logged.
- New PostgreSQL regression scenarios cover current owner/editor, viewer role change, cross-tenant BOLA, no record on failed submission, stale/replayed requests, token isolation, no publication and immutable audit.
- **in_progress, backend slice verified:** [PR #39](https://github.com/binegocom/TopKolik.com/pull/39), code commit `88b3c56d435ab7d025d0219e1f46e2c22d8f6be6`. [Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41260382081) passed **166 tests, 1,233 assertions** with isolated PostgreSQL/Redis, real HTTP/SMTP and no Composer security advisories; [Editorial/Roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41260382064) passed. Finance dual-control, advertiser review approval/publishing, data rights and production release remain unverified.

## P03-007 MFA review changes request — PR review

- Ads staff with current assignment, active org, current role and five-minute MFA step-up can request corrections on the precise in-review campaign; PostgreSQL revision CAS and locking prevent stale changes, cross-organization writes and duplicate feedback.
- The review outcome changes only `in_review → draft`. The advertiser owner/editor can see encrypted feedback via an organization-scoped API, edit the existing creative and submit again; staff identity is not returned in advertiser payload.
- New forward-only append-only PostgreSQL feedback ledger, tenant/actor FKs and revision constraints, no-store responses, route throttles and negative BOLA/concurrent-version/role tests.
- **in_progress, tested backend slice:** [PR #40](https://github.com/binegocom/TopKolik.com/pull/40), code commit `a32d9ea68f8ef21932ce21e31ac315a50b16eca1`. [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41261596280) passed **176 tests / 1,307 assertions** on isolated PostgreSQL/Redis; HTTP/SMTP and Composer security audit clean; [editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41261596232) passed. Approval, publication, delivery, finance dual control, onboarding and production release remain unimplemented.

## P03-010 MFA self-service authentication failure search — PR #41

- Added a read-only, rate-limited `GET /api/v1/staff/security/authentication-failures` endpoint with current persisted staff session and five-minute MFA step-up. SQL always scopes to the authenticated staff ID; actor/email/HMAC selectors are rejected, with only bounded action/UUID/keyset filters allowed.
- Response exposes no staff ID, identity fingerprint, email, token or MFA factor. Unknown-principal login attempts remain append-only in PostgreSQL but are excluded from this self-service endpoint. Reader and advertiser tokens, revoked/inactive sessions and missing MFA are denied.
- **in_progress, verified backend slice:** [PR #41](https://github.com/binegocom/TopKolik.com/pull/41), code/docs SHA `76600fccbe54ffd89fc3306804dd144d66f029ec`. [Laravel CI](https://github.com/binegocom/TopKolik.com/actions/runs/41262734530): **184 tests / 1,390 assertions** passed on isolated PostgreSQL/Redis; Composer audit, HTTP and real SMTP passed. [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41262734534): 17 editorial guards and roadmap integrity passed.
- `P03-010` remains **in_progress**: no global incident-analyst search, offsite immutable archival, production DB role separation, approved retention, or complete throttled/malformed/middleware denial capture. No production release or sports-provider access is claimed.

## P03-007 advertising reviewer self-assignment control — CI review

- Staff in current `ads` role can list only their own active assignments to active organizations, without actor-ID selectors. The server applies authenticated staff ID to all SQL queries and bounds keyset pagination.
- An ads reviewer may relinquish only their own existing active assignment using a short-lived MFA step-up. A transactional row lock, fresh authorization and conditional update deactivate the assignment; an allowlisted staff security audit record commits in the same transaction. No self-grant, reactivation or staff role mutation is available.
- New negative PostgreSQL integration tests cover cross-tenant/cross-staff IDOR, expired/revoked/wrong tokens, role/MFA changes, replay, metadata-only responses, and forbidden privilege elevation.
- **in_progress, backend slice verified:** [PR #42](https://github.com/binegocom/TopKolik.com/pull/42), code commit `6d0ae933c0e92f2e039347ecad9889fac277c434`. [Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41265945163) passed **194 tests, 1,505 assertions** with isolated PostgreSQL/Redis and real HTTP/SMTP, Composer audit clean; [Editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41265945250) passed. Secure assignment provisioning, finance dual approvals, ad publication, external audit anchoring and production release remain unimplemented.

## P03-007 two-person authorization for advertising staff assignments — PR review

- Created separate inactive-by-default staff security administrator grant and two-person assignment approval queue. Existing editor/support/ads/finance roles gain no implicit admin rights and no self-provisioning API exists.
- Initiator and approver must be distinct, active verified MFA-enrolled non-ads staff with independently authorized grants and five-minute step-up tokens. Request is pending without access; a separate approver atomically activates only the scoped assignment, records terminal decision and appends an allowlisted audit. Rejection never activates access.
- Added PostgreSQL constraints/one-way transition guard, 24-hour expiry, uniqueness per pending target/org, current requester authorization recheck and negative replay/role/tenant/token tests.
- **in_progress, partial backend CI verified and merged:** [PR #43](https://github.com/binegocom/TopKolik.com/pull/43), merge `bc78ae5b20b1c55e0a6aa5ef1551e7b1dfc8e05f`. [Laravel CI 41266888211](https://github.com/binegocom/TopKolik.com/actions/runs/41266888211) passed 205 tests / 1,655 assertions on PostgreSQL/Redis, HTTP/SMTP, Composer audit and 17 editorial guards; [editorial/roadmap CI 41266888168](https://github.com/binegocom/TopKolik.com/actions/runs/41266888168) passed. Authorized production administrator bootstrap/revocation, finance dual control, campaign publication and production security gates remain unavailable.

## P03-010 malformed staff authentication validation audit — PR #44

- The existing staff login and MFA step-up validation contracts remain unchanged (422 for malformed input). A malformed login with a syntactically valid email is recorded as an HMAC-only unknown-principal failure; malformed authenticated step-up records the persisted staff identity. No raw email, password, bearer token or MFA factor is written.
- New PostgreSQL feature tests cover malformed login/step-up inputs, token non-issuance, secret redaction, absent/array email and unauthenticated step-up denial.
- **in_progress, backend slice CI-verified:** [PR #44](https://github.com/binegocom/TopKolik.com/pull/44) / [Laravel CI run 41267032971](https://github.com/binegocom/TopKolik.com/actions/runs/41267032971): 209 tests, 1,688 assertions with disposable PostgreSQL/Redis, live HTTP, SMTP, Composer audit clean and 17 editorial guard cases. [Editorial/roadmap CI 41267032961](https://github.com/binegocom/TopKolik.com/actions/runs/41267032961) passed. Missing/invalid-email attempts, middleware and rate-limit denials, offsite tamper-evident archival, approved retention and production DB role separation remain open. No production deployment or live sports-provider access is claimed.

## P03-007 security administrator voluntary revocation — feature review

- Added self-only MFA step-up authenticated `POST /api/v1/staff/security/admin-grant/relinquish`. A current verified security administrator may deactivate only their own independently provisioned grant; grant deactivation and an allowlisted append-only security audit record commit atomically under a PostgreSQL row lock.
- Existing ad assignment approval requests and decisions now acquire and revalidate the current administrator grant under a row lock inside their transaction, preventing stale permissions from being reused after revocation.
- Negative feature coverage for cross-principal types, stale role/MFA, expired/revoked tokens, pending requester/approver privilege loss, client mass-assignment overrides and replay.
- **in_progress, verified backend slice:** [PR #46](https://github.com/binegocom/TopKolik.com/pull/46), feature commit `3d960e7c12ba626207c62492d0ffa506fd9f38a3`. [Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41269986853) passed **218 tests / 1,757 assertions** on isolated PostgreSQL/Redis with live HTTP/SMTP and clean Composer audit; [editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41269986895) passed. Operator-controlled initial administrator grant provisioning and emergency revocation, finance dual-control, publication and production deployment remain missing.

## P03-010 staff authentication throttling audit — PR #47 in review

- Named Laravel staff login and MFA step-up rate-limit response callbacks record HMAC-pseudonymized authentication failures for rejected requests before the controller runs. Both IP and account/principal dimensions remain enforced; HTTP 429 and Retry-After behavior are preserved.
- New PostgreSQL regression tests exercise per-account and per-IP exhaustion, invalid identifier handling, authenticated MFA step-up, reader principal isolation, token non-issuance and secret redaction.
- **in_progress / code CI verified:** [PR #47](https://github.com/binegocom/TopKolik.com/pull/47), code commit `95a45c84f419acd687a12e3c66d7399f417776a4`. [Laravel CI 41270870736](https://github.com/binegocom/TopKolik.com/actions/runs/41270870736) passed **223 tests / 1,838 assertions** on PostgreSQL/Redis with live HTTP/SMTP, clean Composer audit and 17 editorial guard tests; [editorial/roadmap CI 41270870749](https://github.com/binegocom/TopKolik.com/actions/runs/41270870749) passed. Final documentation commit CI remains required. Do not mark P03-010 done until external append-only anchoring, privileged DB role separation, retention policy, authorized analyst access and middleware denial coverage are verified.
- No licensed sports data, runtime fixtures, provider access or production deployment is claimed.

## P03-010 cross-principal MFA throttle isolation — PR #48

- Reader and staff accounts use separate tables and may share numeric IDs. The staff step-up principal rate limiter previously used an unqualified ID, allowing a reader bearer token to exhaust a staff member's six-per-hour MFA quota.
- The limiter now namespaces verified staff principals and separates non-staff attempts. The existing IP quota, 429 audit callback, MFA session checks and response contracts are unchanged.
- A new PostgreSQL feature regression exercises overlapping reader/staff IDs, reader denial without staff audit events, and staff MFA validation after clearing only the shared IP limiter.
- **P03-010 remains in_progress; PR #48 code commit `8b956bf` passed 224 Laravel tests / 1,849 assertions on isolated PostgreSQL/Redis, real HTTP/SMTP and 17 editorial guard checks.** [Laravel CI 41274750485](https://github.com/binegocom/TopKolik.com/actions/runs/41274750485) and [Editorial CI 41274750571](https://github.com/binegocom/TopKolik.com/actions/runs/41274750571) passed; final documentation commit CI is still required. No production deployment, independent audit archival, privileged DB role separation or approved retention is claimed.

## P03-010 middleware-denied staff MFA step-up audit — PR #49

- [PR #49](https://github.com/binegocom/TopKolik.com/pull/49) adds HMAC-pseudonymized `staff.step_up.failed` records when the persisted bearer token matches a staff principal but `EnsureStaffSession` denies a step-up due to revoked staff activation, missing MFA/email verification or missing `staff:session` ability.
- The existing 401/403 authorization behavior remains unchanged. Unknown, revoked, expired, reader and mismatched bearer tokens never become synthetic staff audit actors. Other staff-route denials do not create MFA events.
- New PostgreSQL feature tests cover deactivation, missing MFA enrollment, missing ability, reader/staff ID collisions, revoked/invalid tokens, request-ID linkage, and no plaintext factor, email or bearer token in audit records.
- **in_progress / awaiting final-commit CI:** no test or merge result is claimed until required GitHub Actions checks finish on the last PR commit. P03-010 remains open pending independent immutable archival, privileged DB role separation, approved retention, global analyst authorization and production verification. No provider access or production deployment is claimed.
