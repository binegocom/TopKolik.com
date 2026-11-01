# P03-007 — Dual approval for staff advertiser review assignments

## Security-administration authority

There is **no automatic security administrator role**, no widened global `staff_members.role` grant, and no HTTP endpoint for security-administrator enrolment. `staff_security_admin_grants` represents a separate **inactive-by-default** and auditable administrative capability. A valid staff session alone never grants it. A privileged, independently authorized operator must provision any active administrative grant through a reviewed operational process; no automatic provisioner or client can create/activate one in this change.

Only current, active, email-verified, MFA-enrolled non-`ads` staff with a pre-existing active grant, an unexpired **five-minute step-up** token and current persisted role may access the assignment approval API. Grants do not add editor, support, finance or content publication privileges. The target must be an active, verified, MFA-enrolled `ads` staff member, not either administrator.

## API

- `GET /api/v1/staff/security/ads/assignment-approvals`: pending request IDs, target staff IDs, organization IDs, requester IDs and timestamps, bounded keyset pagination; security admins only.
- `POST /api/v1/staff/security/ads/assignment-approvals`: exact `target_staff_member_id` and `advertiser_organization_id`. Locks the eligible target and active advertiser organization. Refuses an existing active assignment or pending request. Inserts an expiring 24-hour pending decision and append-only audit. **Does not grant access.**
- `POST /api/v1/staff/security/ads/assignment-approvals/{approval}/approve`: requires a **different** currently authorized administrator, an unexpired request, current requester authorization and eligible target/organization. Within one transaction, locks the pending request and target, creates or reactivates only the selected assignment, records the approving administrator, and appends the security audit. Failures roll everything back.
- `POST /api/v1/staff/security/ads/assignment-approvals/{approval}/reject`: requires a different authorized administrator, closes the request without creating or activating an assignment. Rejected/expired requests are not silently approved.

PostgreSQL enforces foreign keys, separate initiator/reviewer/target identities, one pending request per target/organization and a one-way terminal decision trigger. Deleting or truncating approval records is blocked for the application role. The audit ledger uses fixed allowlisted `requested`, `approved`, `rejected` action names, server-generated request UUIDs and organization IDs; no password, MFA factor, token or raw creative is logged. Client-supplied actor, role and status fields are rejected.

A previously relinquished assignment may only be reactivated by **a new two-person approval**. This cannot be done by staff self-service. Current assignment revocation and organization deactivation continue to fail closed.

## Operational and release constraints

This is a **technical dual-control slice**, not proof that a legally or organizationally authorized security administrator has actually been designated. A separate reviewed bootstrap/revocation runbook is required. In production, DB grants and migrations must be applied only after operator approval, least-privileged database separation, change control and backups. The implementation does not create security admins, ad approvals, publication, payment permissions or external audits. Production deployment remains unverified; `P03-007` stays `in_progress`.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffAdsAssignmentDualApprovalTest
php artisan test
```

Tests run against disposable PostgreSQL/Redis, including two-person authorization, wrong principals, replay/duplicate/expiry, disabled grants and tenants, post-relinquish reapproval, audit atomicity, immutability, and refusal of arbitrary grants.
