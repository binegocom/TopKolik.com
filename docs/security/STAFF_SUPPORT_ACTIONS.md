# Support conversation writes — scoped P03-007 slice

**Status:** Under CI review. This is one incremental part of P03-007; the entire staff RBAC gate is not complete.

- `POST /api/v1/staff/support/tickets/{ticket}/replies`: requires staff MFA bearer token, support role, active staff, persisted assignment scope and open/pending state. Accepts `message` (1-4000 characters) and a client-generated UUID `idempotency_key`. Same key+message+actor within the ticket returns prior ID (200); changed payload with same key is rejected (409); initial success returns 201. The ticket transitions from `open` to `pending`.
- `POST /api/v1/staff/support/tickets/{ticket}/escalate`: requires additional short-lived `staff:step-up` MFA scope. Only assigned support staff on `open` tickets can escalate; stores actor and timestamp, transitions to `pending` in one PostgreSQL transaction.
- `GET /api/v1/staff/support/tickets/{ticket}`: authorized support ticket includes decrypted conversation history, never advertiser or other staff tickets.
- Replies persist encrypted at rest using Laravel `encrypted` model cast; a SHA-256 digest of the body supports idempotent replay (not content extraction). Composite unique key prevents repeated processing; per-ticket row lock serializes concurrent writes.
- All resource IDs and assigned ownership come from authenticated staff, scoped PostgreSQL queries and fresh persisted Laravel authorization; never from client payload.
- Unauthorized, unassigned and nonexistent ticket objects are indistinguishable 404 for already-authenticated staff. Role changes, inactive accounts, expired/reader tokens and resolved tickets fail closed.
- No customer ticket creation or outbound notification is implemented; supporting customers, staff audit trail and escalation dispatch are separate tasks. Test fixtures exist only inside isolated disposable databases.
- Migrations are additive and forward-only. Do not execute on production without approved migration plan.

Verification: CI uses isolated PostgreSQL, Redis, Laravel tests, actual HTTP/SMTP smoke, 17 editorial guard cases, and Composer audit.
