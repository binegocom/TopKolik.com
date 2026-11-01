# P03-007 — Advertiser campaign submission for review

## Boundaries

The existing authenticated advertiser controller now supports:

`POST /api/v1/advertiser/organizations/{organization}/campaigns/{campaign}/submit`

Body: `{"revision": <current_revision>}`.

Only an **active, verified advertiser account** presenting a valid unexpired `advertiser:session` Sanctum bearer token and an **active owner/editor membership** in the **active route organization** can submit a draft. Viewers, other organizations, staff and readers cannot submit. A campaign is loaded with a SQL organization constraint and locked only after validating the organization membership. Both membership and campaign are locked in the same transaction with the same ordering as existing advertiser updates.

The endpoint accepts an exact expected `revision`, rejects client-supplied status, ownership, reviewer, title and body overrides, and only transitions **draft → in_review**. A PostgreSQL conditional update additionally verifies organization, status and revision at the mutation boundary. Stale requests and replays return HTTP 409; failed attempts write no audit events. An accepted transition increments the revision once. Published, approved, declined, live/served or paid advertising states **do not exist here**.

## Audit

`advertiser_campaign_review_events` records the real advertiser account ID, organization and campaign IDs, fixed action `submitted_for_review`, from/to revision, server-generated request UUID and creation timestamp **inside the same database transaction**. It never stores raw creative text, bearer tokens, emails, IP addresses, MFA or banking details. PostgreSQL enforces foreign keys, revision progression, uniqueness per campaign revision and insert-only DML triggers. Application transaction rollback removes both the state update and the audit insert if either fails. Database owners can still bypass triggers; production requires external tamper-evident copies and restricted DB roles.

The existing assigned ads staff review list now displays submitted campaign metadata; details require a separate fresh MFA step-up. No staff approval, publication, serving, billing or credentials provisioning is implemented. There is no external data provider, simulated match data or public ad endpoint.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=AdvertiserCampaignSubmissionTest
php artisan test
```

GitHub Actions exercises an isolated real PostgreSQL and Redis service. Regression tests cover owner/editor permissions, viewer downgrade, foreign tenant BOLA, inactive membership/organization, wrong principal and expired token, server-controlled status, stale revisions, replay, audit integrity and immediate visibility to authorized reviewers.

**Roadmap:** `P03-007` remains `in_progress` until the full staff RBAC/BOLA role matrix, reviewed ad approval and finance dual-control are implemented and verified. `P03-008` tenant scoping is not generalized to queue/cache/object storage by this endpoint.
