# P03-010 — Staff security audit events

## Implemented

The Laravel API writes allowlisted staff authentication, MFA, activation and support mutations to `security_audit_events` within the corresponding PostgreSQL business transaction. Audit events contain only server-authoritative staff ID, fixed action and resource identifiers, request UUID and timestamp; no email, token, MFA code, request body or ticket text is retained in the ledger. DML triggers reject UPDATE, DELETE and TRUNCATE. The migration is forward-only.

`GET /api/v1/staff/security/audit-events` is a **self-only, read-only audit search**, not a global administrator endpoint. Both `auth:sanctum` and `staff.session` must succeed, followed by an explicit `staff-audit-self` Gate on the freshly resolved presented token. The token must have `staff:session` and an unexpired five-minute `staff:step-up` privilege obtained via the existing TOTP/recovery reauthentication flow. Fresh persisted staff identity must still be active, verified, MFA-enrolled and in an approved staff role.

- Every query is unconditionally scoped to the authenticated `staff_member_id`. Client-supplied actor identifiers are rejected (422). No cross-staff or advertiser/reader access is possible.
- Optional filters are allowlisted `action`, `subject_type`, `subject_id`, `request_id`; `before_id` is an exclusive keyset cursor. Maximum `limit` is 50 (default 20), descending event ID, without a total count.
- Response returns only `id`, `action`, `subject_type`, `subject_id`, `request_id` and `created_at`; no staff email or actor ID. Responses include `Cache-Control: no-store, private` and `Pragma: no-cache`. Route throttling limits to 30 requests per minute.
- No audit event is deleted or changed by searches. No shared security administrator or global search role is granted.

## Remaining release constraints

This ledger is **DML append-only**, not independently immutable against privileged PostgreSQL users. A database owner or superuser can bypass triggers. Before production: separate DB roles, independent append-only offsite export/anchoring, verified restoration and retention policy, authorized global security oversight with approved role model, and failed-attempt coverage for both known and unknown principals. Those features are not claimed as finished. `P03-010` remains `in_progress` until the complete security and release acceptance gates pass.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffSecurityAuditSearchTest
php artisan test
```

GitHub Actions validates on isolated real PostgreSQL and Redis, plus HTTP/SMTP smoke, Composer audit and editorial guard. No destructive production database change or third-party sport content is needed.
