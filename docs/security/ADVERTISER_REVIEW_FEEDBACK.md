# P03-007 — Advertiser review changes requested

The assigned `ads` staff reviewer may **request corrections only**, not approve, publish, bill or deliver advertising. Two new routes:

- `POST /api/v1/staff/ads/organizations/{organization}/campaigns/{campaign}/request-changes` with exact `revision` and plain-text `feedback` (15–2,000 characters).
- `GET /api/v1/advertiser/organizations/{organization}/campaigns/{campaign}/review-feedback` with optional validated `limit` (1–50) and exclusive `before_id` cursor.

Review mutations require an active verified MFA-enrolled staff principal with `ads` role, active explicit assignment and organization, and an unexpired `staff:step-up` bearer token. The request locks the assignment and campaign within one PostgreSQL transaction, checks the role policy against fresh stored identity, then conditionally updates only the selected tenant's `in_review` campaign at the exact revision to `draft`, incrementing revision once. The advertiser owner/editor can now edit the unchanged draft copy and resubmit using the existing API. Replays, invalid statuses, revoked assignments and stale versions cannot create extra feedback records.

The same transaction writes `advertiser_campaign_review_feedback` with the real staff/organization/campaign IDs, fixed `changes_requested` action, revision transition, server request UUID and **Laravel-encrypted feedback**. The PostgreSQL table has FKs, revision uniqueness, constraints and append-only DML triggers. Client never chooses reviewer ID, tenant ID or status. The advertiser feedback endpoint limits access to active owner/editor membership and scopes campaign and all feedback rows to the requested organization. It returns only feedback, action, revision markers and timestamp, **never the staff identity or tenant ID**. Private/no-store caching on both routes; route throttles apply. Feedback forbids angle-bracket markup; UI must render it as text, not HTML.

**Outstanding:** No advertiser public onboarding, staff assignment administration, approval, rejection, publication, advertising delivery, billing/finance dual approval, offsite tamper-evident ledger shipping or retention policy. PostgreSQL superusers can bypass DML triggers. The full `P03-007` acceptance remains **in_progress**. This is not an operationally complete advertising workflow.

Verification on isolated PostgreSQL/Redis:

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=AdvertiserReviewFeedbackTest
php artisan test
```
