# P03-007 — Emergency security administrator grant revocation

## Scope and authority

A separately authorized staff security administrator can immediately revoke **another staff member's existing active security-admin grant** using:

`POST /api/v1/staff/security/admin-grants/{staff}/revoke`

The endpoint is private, under `auth:sanctum` and `staff.session`, and rate-limited to six attempts per minute. The actor must hold an independently provisioned, currently active `staff_security_admin_grants` record and a valid five-minute MFA step-up token. The Gate reloads the persisted staff role, active state, verified email, MFA enrollment and exact Sanctum principal. Reader or advertiser identities, ungranted staff, expired tokens and ordinary staff sessions cannot invoke it.

The route does not accept a client-selected actor or body-supplied role, grant, status or authorization reference. Revoking one's own grant through this route is prohibited; the existing `/security/admin-grant/relinquish` endpoint handles voluntary self-removal.

## Atomicity and tenant isolation

Inside one PostgreSQL transaction (deadlock retries bounded to 3):

1. Lock the actor's grant and recheck the staff-security-admin Gate under that lock.
2. Lock **only** the route target's currently active grant. Inactive/missing grants are returned as 404 to authorized actors.
3. Update the matching grant from active to inactive with a conditional SQL predicate.
4. Append the allowlisted `staff.security_admin_grant.revoked` security audit event, with real acting staff ID, `staff_member` subject type, target staff ID and server-issued request UUID.

An audit failure rolls back the grant mutation. No credential, MFA material, email or request body is stored in the audit. Both the actor grant lock and the target grant lock synchronize with existing self-relinquishment and dual-control advertising staff assignment decisions. Once revoked, the old admin may continue their non-security role where allowed, but cannot open the security approval queue or authorize assignment requests. Existing unapproved requests from a revoked requester fail before an assignment is activated.

## Limitations

This feature **does not** provision or reactivate administrator grants, revoke all staff sessions, replace staff deactivation, authorize advertising or financial publication, or establish an approved operational on-call/incident procedure. The ability to designate initial emergency administrators still requires independently reviewed access management and separation of DB owners. Application audit triggers are not independently tamper-evident to PostgreSQL superusers; approved retention and offsite archival are missing. `P03-007` remains **in_progress**. No production deployment is claimed.

## Verification

```bash
cd services/api
php artisan test --filter=StaffSecurityAdminEmergencyRevocationTest
php artisan test
```

GitHub Actions runs Laravel against isolated PostgreSQL and Redis. Feature cases cover separate staff principal and target, audit reference, replay, active requester revocation blocking pending approvals, role/token/MFA/expiry denials, disabled target cleanup, forbidden self-revocation and no grant/role mass-assignment.

## Verified merge evidence

- [PR #57](https://github.com/binegocom/TopKolik.com/pull/57) merged after conflict-free rebase onto PR #56's independent support escalation review: `00b66a4f4643bbd453adfd5865751b19529d141e`.
- [Final `main` Laravel API CI](https://github.com/binegocom/TopKolik.com/actions/runs/41299720644): 267 passing tests, 2,166 assertions, isolated PostgreSQL/Redis, HTTP/SMTP smoke and clean Composer audit.
- [Final `main` editorial and roadmap integrity](https://github.com/binegocom/TopKolik.com/actions/runs/41299720703): passed.
- This verifies code and integration behavior, **not** production administrator eligibility, paid sports data rights, finance approval or deployment.
