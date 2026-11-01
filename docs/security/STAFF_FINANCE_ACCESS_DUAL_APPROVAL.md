# P03-007 — Finance staff access dual-control foundation

## Current capabilities

A staff role of \`finance\` alone confers **no** financial access. \`staff_finance_entitlements\` records a distinct, inactive-by-default entitlement tied to a real staff member; the live entitlement is checked against persisted staff role, active state, email verification, MFA enrollment, exact Sanctum principal and an unexpired step-up token.

These APIs manage **permission only**, not money, invoices, payouts, bank details, financial reporting or accounting exports:

- \`GET /api/v1/staff/security/finance/access-requests\` — pending requests, bounded descending keyset pagination. Available only to independently authorized security administrators with MFA step-up.
- \`POST /api/v1/staff/security/finance/access-requests\` — requester supplies only \`target_staff_member_id\`. The target must be an active verified MFA-enrolled \`finance\` staff member, different from requester. Creates a pending 24-hour request and audit event; grants nothing.
- \`POST /api/v1/staff/security/finance/access-requests/{approval}/approve\` — a **different** currently authorized security administrator decides. Revalidates the requesting administrator's retained privileges and target eligibility, then activates only the target entitlement and appends audit within one PostgreSQL transaction.
- \`POST /api/v1/staff/security/finance/access-requests/{approval}/reject\` — a different administrator closes a pending request without granting access. Expired requests may be rejected, never approved.
- \`GET /api/v1/staff/finance/access\` — protected self-only proof of active privilege; returns only \`{"access":"active"}\` and no personal/financial data.
- \`POST /api/v1/staff/finance/access/relinquish\` — an MFA-confirmed finance employee can deactivate **only their own** active entitlement; same-transaction security audit. Access cannot be self-restored. A new two-person approval is needed.

## Security properties

Persisted current actor grants, staff identity, explicit MFA step-up and conditional entitlement state checks protect each action. The actor grant is locked in administrative mutations; requester authorization and relevant records are rechecked inside approval transactions. PostgreSQL foreign keys, a one-pending-request-per-target unique index, three-person separation-of-duties constraint (requester, approver, target), terminal one-way decision trigger and delete/truncate guards prevent accidental replay or approval rewrites. Existing audit events remain append-only and contain only real staff/target IDs, fixed allowlisted action and the server's request ID. The private responses cannot be cached. Client-supplied actor, grant state, role or financial amounts are rejected.

The operational administrator grant still requires independently reviewed provisioning outside this API. This feature **must not** be used as proof that payment or financial processing has been enabled. Future financial endpoints require separate resource-scoped authorization, payment-provider signatures, accounting rules, source data contracts, human-approved double entry/dual-control, and legal retention decisions.

## Release limitations

- No payment, invoice, ledger, reconciliation, pricing, tax or publication endpoints exist here.
- No production security-admin bootstrap, emergency finance access revocation by another operator, external tamper-evident audit, or approved finance retention.
- PostgreSQL application triggers are not protection against database superusers.
- No proof of production deployment or licensed sports-data access.
- \`P03-007\` remains **in_progress**, and \`P12-001\` to \`P12-008\` remain \`todo\`.

## Verification

CI with disposable PostgreSQL/Redis:

\`\`\`bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffFinanceAccessDualApprovalTest
php artisan test
\`\`\`

Test scenarios cover inactive default, two independent administrators, current MFA and role, reader/advertiser principal isolation, expiry, duplicate/replayed decisions, revoked requester or target, self-relinquishment, reapproval, private pagination, cross-staff field injection, audit failure rollback and terminal database immutability.
