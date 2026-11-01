# P03-007 — Held support escalation reviewer recovery

## Bounded recovery operation

`POST /api/v1/staff/security/support/escalations/{ticket}/recover-reviewer`

This API repairs only an existing **pending**, **escalated**, **not yet reviewed**, **currently unassigned** support escalation. It cannot submit, approve, resolve, reject, re-open or rewrite a ticket, replace an existing reviewer, or change another staff role. It never returns ticket text, user identity, notes, reviewer IDs or financial data.

A current active, verified, MFA-enrolled staff member with an **independently issued active** `staff_security_admin_grants` capability and a valid short-lived `staff:step-up` token may invoke recovery. Support/finance/editor roles alone are not privileged. Reader, advertiser and staff bearer-token identities cannot be confused. Authorization is checked before ticket lookup, and the calling administrator's grant is locked and rechecked in a PostgreSQL transaction so concurrent emergency revocation fails closed.

The transaction locks the exact held ticket, then chooses the first eligible currently active, verified, MFA-enrolled `support` reviewer in stable ID order with a row lock. It excludes the calling administrator, assigned support staff and originating escalator. **No client reviewer selection** is permitted. If no independent reviewer is eligible, HTTP 409 leaves the ticket held without appending a new audit event. A successful recovery inserts the fixed `support.ticket.escalation_reviewer_recovered` audit event, with acting staff, ticket and server request UUID, inside the same transaction as the reviewer assignment. If the audit insert fails, recovery rolls back. No extra database migration is required.

After assignment, existing independent review remains mandatory and the assigned support agent still cannot resolve until a different eligible reviewer explicitly approves using MFA. Multiple submissions do not overwrite reviewers. The endpoint is private/no-store and rate-limited.

## Operational constraints

This does **not** create the first security-admin grant, expose a general unassigned support inbox, provide rejected-review or reviewer fairness algorithms, auto-run background recovery, create finance approval or authorize production operations. A human must know the target ticket ID and already hold an independently approved security-admin grant. Production DBA role segregation, offsite audit anchoring and incident on-call authorization remain unverified. `P03-007` stays `in_progress`.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffSupportEscalationRecoveryTest
php artisan test
```

PostgreSQL integration tests cover real escalation → held → recovery → independent approval → resolution, security administrator exclusion, no eligible reviewer, replay, non-escalated/reviewed cases, MFA/role/grant revocation, reader/advertiser principal boundaries, disallowed actor-supplied IDs and atomic audit rollback.
