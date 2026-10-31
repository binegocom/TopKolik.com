# Staff security audit events (P03-010)

The Laravel API records successful staff invitation acceptance, MFA enrollment, operator activation, session creation, step-up, logout, support replies and escalations. Each event has an internal staff ID, fixed action, fixed resource type/ID, optional server-generated request UUID and timestamp. No request bodies, credentials, MFA factors, email addresses, tokens or ticket text are stored.

All audit inserts occur in the same PostgreSQL transaction as their protected state mutation. A PostgreSQL trigger rejects UPDATE, DELETE and TRUNCATE, and the migration is forward-only. The ledger has no public or staff read endpoint. Operators should use a separately authorized, read-only incident export path when that capability is designed.

**Security boundary:** This is DML append-only, **not** cryptographically tamper-proof against a PostgreSQL owner/superuser. Production must use a restricted application DB role, independent append-only offsite log shipping, access monitoring, retention/legal review and backup restore drills. Do not mark P03-010 fully done until those acceptance controls and failed-attempt coverage are verified.

Verification: `php artisan migrate --force && php artisan test --filter=SecurityAuditEventsTest` with real PostgreSQL, then full Laravel CI, SMTP smoke and editorial guard. No destructive production migration or unlicensed sports data is involved.
