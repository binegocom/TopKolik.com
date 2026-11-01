# P03-007 — Security administrator self-relinquishment

## Current authorization model

Security administration is an independently provisioned capability recorded in `staff_security_admin_grants`, **not** a staff role or a user-selected privilege. An approved and active, email-verified, MFA-enrolled staff member with a valid five-minute `staff:step-up` bearer token may voluntarily surrender **only their own** currently active grant:

`POST /api/v1/staff/security/admin-grant/relinquish`

The route requires `auth:sanctum` and `staff.session`, is throttled to 6 requests/minute and returns a private, uncached status without identifiers or other grants. Server-side authentication explicitly verifies the current staff principal and the exact Sanctum token type/owner/expiry. A fresh `staff-ads-assignments-admin` Gate resolves persisted role, verification, MFA and grant state; no client-supplied actor or privilege flags can be accepted.

A PostgreSQL transaction locks **only the caller's grant**, checks authorization again after acquiring the lock, conditionally updates `is_active` to false and appends `staff.security_admin_grant.relinquished` to the existing insert-only security ledger, subject type `staff_member`, subject ID equal to the caller. Any audit failure must roll back the grant change. Repeat calls fail closed. No delete, other-user mutation, grant creation, reactivation or expanded role permission is introduced.

The existing two-person assignment approval controller now locks and revalidates the *deciding/current initiating admin's* grant **inside** its transaction before creating a pending request or approving/rejecting one. This shares a lock with self-revocation, so a grant revoked before a pending mutation commits cannot be reused based on a prior request-scope authorization check. The existing approval logic already rejects pending requests after the original requesting administrator's grant is revoked. Other active administrators' grants and assignments are unaffected.

## Limitations

This self-relinquishment path does **not** provision the first security administrator, create a third-party emergency revocation operator, replace staff account deactivation, automatically revoke already issued tokens, or provide approved global security analyst access. Operational administrator bootstrap/revocation and privileged DB role separation are independent release gates. Database owners may bypass application controls; offsite tamper-evident log archival is still missing. No advertising publication, finance dual approval, or production deployment is added. `P03-007` remains **in_progress**.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffSecurityAdminGrantRelinquishmentTest
php artisan test
```

CI integration tests on disposable PostgreSQL/Redis cover self-only revocation, MFA token, wrong principal types, inactive staff, injection attempts, repeated calls, pending requestor/approver privilege loss and audit ledger atomicity. No new schema migration or destructive change is required.
