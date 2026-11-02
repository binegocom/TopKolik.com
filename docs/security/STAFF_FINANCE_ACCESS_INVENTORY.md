# P03-007 — Finance access security inventory and decision history

## Read-only administrator views

Two new endpoints are protected by \`auth:sanctum\`, \`staff.session\`, and the existing \`staff-ads-assignments-admin\` authorization policy. This policy requires a verified, active, MFA-enrolled staff principal holding an **independently provisioned active security administrator grant** and a current \`staff:step-up\` token. A finance role alone does not authorize either endpoint.

- \`GET /api/v1/staff/security/finance/entitlements\`: returns only **active entitlement records**, with entitlement ID, staff member ID, last approving administrator ID, update timestamp and a \`staff_eligible\` boolean. The flag is false if the staff member's persisted role is no longer \`finance\`, the account is disabled or email/MFA enrollment is missing. Eligibility is **not** proof of a currently authenticated MFA session.
- \`GET /api/v1/staff/security/finance/access-requests/history\`: returns only terminal \`approved\` or \`rejected\` two-person access decisions, with actor/target IDs and created/decided timestamps. Optional \`status=approved|rejected\` narrows the result; pending requests are deliberately excluded and continue to use the existing pending review endpoint.

Both endpoints support descending ID keyset pagination (\`before_id\`, \`limit\` 1–50, default 20), return \`next_before_id\` only when more rows exist and apply \`Cache-Control: no-store, private\`. No client-selected staff, actor, request UUID, hidden rows or arbitrary sort fields are accepted. Responses contain **no** staff emails, names, MFA factors, tokens, contacts, authorization reference, payment, pricing, invoice, banking, transaction or financial amounts.

The inventory is a security-management view, not a payment dashboard. Staff eligibility is calculated from persisted account fields; actual sensitive operations (when introduced and approved) must additionally verify current MFA, resource-level authorization, payment contracts and company tenancy.

## Controls and limitations

- No new migration, role, entitlement grant, reactivation, revocation, finance transaction or pricing operation is added.
- A read cannot alter security entitlements, approval decisions or append-only audit. Existing privilege mutations remain protected by transactions, row locks and separate audit events.
- Database-superuser tampering, offsite immutable audit anchoring, production security-admin onboarding, financial retention, staff/finance resource-level permissions, independent operational approval and deployment remain unresolved.
- \`P03-007\` stays **in_progress**. \`P12-001\` through \`P12-008\` remain **todo**.

## Verification

\`\`\`bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffFinanceAccessInventoryTest
php artisan test
\`\`\`

Use isolated PostgreSQL and Redis in CI. Feature cases cover real two-person approval, active-only inventory, stale-role eligibility, approved/rejected history, omission of pending decisions, keyset pagination and bounded filters, cross-principal denial, MFA/active administrator grant revocation, absence of contact/private financial data and side-effect-free reads.
