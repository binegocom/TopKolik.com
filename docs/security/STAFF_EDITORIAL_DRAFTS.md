# Editorial draft workflow — scoped P03-007 implementation

**Status:** Backend slice verified by CI (106 tests / 724 assertions) https://github.com/binegocom/TopKolik.com/actions/runs/41253897269. The repository now has private, revisioned editorial draft persistence; publishing remains forbidden and P03-007 is still incomplete.

## API
- `GET /api/v1/staff/editorial/drafts`: paginate at most 20 non-public drafts belonging to the authenticated editor, without body text in list.
- `POST /api/v1/staff/editorial/drafts`: create a draft for the authenticated MFA-enrolled active editor, never a client-selected owner or state.
- `GET /api/v1/staff/editorial/drafts/{draft}`: SQL owner scope and a current role/state Gate check before reading encrypted content.
- `PATCH /api/v1/staff/editorial/drafts/{draft}`: requires current revision and permitted field changes, PostgreSQL row lock, conflict 409 for stale writes. Only draft state is editable.
- `POST /api/v1/staff/editorial/drafts/{draft}/submit`: moves only a matching revision in draft state to in_review; submitted_at recorded, revision incremented. No publishing, search indexing, external notification or AI generation is invoked.

## Security and limitations
- Only `staff:session` token belonging to the active, verified, MFA-enrolled staff editor. Other roles or reader sessions are denied. Current persisted role is rechecked by the policy.
- Draft title/body are encrypted at rest; authorization and private/no-store responses keep unpublished content private.
- Owner scope is in SQL before decrypting or evaluating the authorization context. No client-supplied owner ID, state, revision override or mass-assignment.
- The database contains no fabricated sports fixtures, provider events or published articles. All test data are isolated disposable PostgreSQL fixtures.
- Server-side publication must first connect real licensed provider evidence to the publication guard, pass claim/licensing checks and preserve version history (P06).
- Editorial review staff separation, full audit ledger, advertiser scopes, CMS UI, provider integrations, cross-object BOLA suite and legal approval remain pending. No production migration has been run.

## Editorial logout / write concurrency verification (2026-11-02)

- A separate four-worker HTTP regression runs against disposable GitHub Actions PostgreSQL: `php scripts/check-editorial-logout-concurrency.php`.
- Each of create, update and submit races a real staff logout using the same bearer. The write may serialize before logout or return 401 after revocation; both orders are safe only if persisted draft state/revision matches the response.
- The test verifies the presented token is deleted, the logout audit event is written exactly once with request correlation, and a later replay of each write is rejected with 401 without additional persistence or audit.
- Verified on PR #90 head `46bc39eea3f0717e8e044cb6468a3612a9ec46a8`: [Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41448560623) (354 tests, 3,179 assertions and all three real HTTP races); [editorial/roadmap CI](https://github.com/binegocom/TopKolik.com/actions/runs/41448560710).
- This is an isolated backend concurrency check, not production verification or completion of the full staff RBAC/BOLA matrix.

## Editorial read / revocation HTTP concurrency (2026-11-02)

- The isolated four-worker scripts/check-editorial-logout-concurrency.php harness now also races private draft index and detail reads against logout and a committed editor role downgrade.
- Read-before-logout may return only the owner's own records (200); a revoked bearer must return 401 and cannot be replayed. One attributed logout audit event is required, with no draft changes.
- Role-revocation tests hold a PostgreSQL staff row lock, observe a real HTTP worker blocked on FOR UPDATE, commit the downgrade, and require 403 on the resumed read. Foreign-owner detail stays concealed (404) on replay; audit and draft state remain unchanged.
- Verified implementation commit 946a4c3e6c2e9daee681739e2ad6fbe2d0100517: [Laravel API CI 41476526869](https://github.com/binegocom/TopKolik.com/actions/runs/41476526869) and [editorial/roadmap CI 41476526891](https://github.com/binegocom/TopKolik.com/actions/runs/41476526891) both passed. This does not complete the full RBAC/BOLA acceptance matrix or authorize production release.
