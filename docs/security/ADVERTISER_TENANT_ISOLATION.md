# P03-008 — Advertiser organization isolation

## Current implementation

Advertiser accounts are a separate Sanctum tokenable identity, not reader or staff members. Activation, verified email, an expiring `advertiser:session` bearer token, active organization and active role-specific membership are required before accessing any advertiser resource. A single account may hold distinct roles in multiple organizations.

- `GET /api/v1/advertiser/organizations` returns only active organizations with active memberships.
- `GET /api/v1/advertiser/organizations/{organization}/campaigns` lists only the selected member organization and omits draft bodies.
- `POST /api/v1/advertiser/organizations/{organization}/campaigns` creates a private encrypted draft for an owner/editor.
- `GET /api/v1/advertiser/organizations/{organization}/campaigns/{campaign}` requires active membership and SQL organization scope.
- `PATCH /api/v1/advertiser/organizations/{organization}/campaigns/{campaign}` requires owner/editor role, matching optimistic revision and `draft` status.

Both read and write queries constrain `advertiser_organization_id` before record lookup. Unauthorized organization/record combinations return 404. Viewer cannot mutate (403). All responses are private and uncacheable. The server ignores no client-supplied organization, owner, state or revision overrides: they are rejected by validation. PostgreSQL FK, uniqueness and role/status constraints provide additional protection.

## Deliberately unavailable

No public advertiser registration, email verification, activation, login, membership invitation, organization creation, campaign submission/publishing, payment, ad-serving, media upload, queue job or storage path is exposed by this slice. Those flows require independent authorization, commercial policy, fraud controls, audit instrumentation and external setup. Until a secure enrollment/login workflow is built, an operator-provisioned active account and scoped credential are required; test-created accounts/tokens are not production users.

The scope is application/database isolation, **not** PostgreSQL row-level security. The application database owner can bypass query scoping. Before production: use least-privilege DB roles, organization-aware cache/queue/object keys, rate limits, negative BOLA integration and a documented advertiser onboarding policy. Do not assume P03-GATE or a production release is complete.

## Test

`php artisan migrate --force && php artisan test --filter=AdvertiserTenantIsolationTest`

The test suite exercises two or more organizations, multiple memberships, role downgrade, revoked membership, disabled account/organization, token expiry/revocation, staff/reader token confusion, mass assignment, SQL constraints and encrypted draft storage on disposable PostgreSQL.
