# P03-007 — Assigned advertising operations review (read-only)

## Security boundary

A new `staff_advertiser_assignments` table explicitly links real MFA-enrolled staff to advertiser organizations. Assignments are **inactive by default** and can be activated only through a future authorized operator workflow; no assignment administration endpoint is added. Unique and foreign key constraints prevent duplicates and orphan assignments. Revoked assignments, staff role changes, disabled staff and disabled organizations take effect on the next request.

Two internal staff endpoints (both `auth:sanctum` + `staff.session`):

- `GET /api/v1/staff/ads/organizations/{organization}/campaigns`: assigned ads staff only, list opaque ID, state, revision and updated timestamp of **in_review** campaigns; cursor pagination bounded to 50, never draft titles or creative bodies.
- `GET /api/v1/staff/ads/organizations/{organization}/campaigns/{campaign}`: assigned ads staff only and **fresh five-minute MFA step-up bearer token**; additionally requires the campaign belongs to the requested organization and has status `in_review`. Returns private encrypted-at-rest creative title and body for that specific submitted record only.

Both routes are throttled and uncached. The `staff-resource` Gate always resolves fresh persisted staff identity, role, token expiry, active assignment and active organization. Controller queries enforce organization ID and submission status in SQL, so a different tenant's campaign ID is never discovered. Requests cannot substitute staff member or org IDs through query overrides. Neither reader nor advertiser principals can reuse a staff token ability to access these routes.

**Not implemented:** Any staff assignment creation/activation API, ads approval, publishing, media, serving, finance/payment access, account credential onboarding or platform-wide operator superuser. The finance role stays default-denied until a real financial record and dual-approval policy exist. No purchased data provider or fake sports data is assumed.

## Verification

`php artisan migrate --force && php artisan test --filter=StaffAdvertiserReviewTest && php artisan test`

Disposable PostgreSQL/Redis integration cases test cross-tenant BOLA, assigned/unassigned organizations, submitted/draft isolation, MFA step-up, revoked memberships, role changes, wrong token principal, no mass assignment and default-deny actions.

`P03-007` remains **in_progress**: this is only a read-only ads workflow slice. Full RBAC matrix including finance, content publishing and support lifecycle still requires separate verified resources and authorization checks.
