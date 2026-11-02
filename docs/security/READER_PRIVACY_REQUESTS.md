# P03-009 — Reader privacy data portability and deletion request handling

## Authenticated, reverified privacy operations

- `POST /api/v1/me/privacy/export` (body: `password`) returns the authenticated reader's verified account identity, preference snapshot, canonical sport/team favorite identifiers, consent change history and any existing deletion request. This is a read-only, in-memory JSON response, never a public file or shared cache key.
- `POST /api/v1/me/privacy/deletion-requests` (body: `password`) writes at most one pending review request per reader and returns HTTP 202. Replays return the same request ID. It **does not delete data, revoke sessions or claim a request has been fulfilled**.
- `GET /api/v1/me/privacy/deletion-request` shows only the authenticated reader's request state or null.

Sensitive POST operations require an expiring presented Sanctum bearer token bound to a verified `User` principal, `reader:write`, fresh account lookup, and password confirmation; IP and account rate limits apply. Reader-only read requires `reader:read`. IDs, status, email, and retention bypasses from clients are rejected. Data is locked/scoped by authenticated user ID. Responses use private no-store/no-cache headers.

## Self-service consent history

- `GET /api/v1/me/privacy/consent-history` returns **only the authenticated verified reader's** recorded consent transitions; no client-supplied user or actor scope is permitted.
- Requires an expiring, presented `reader:read` Sanctum token bound to a `User` principal. Staff and advertiser tokens are denied even when they present a `reader:read` ability.
- Descending ID keyset pagination: optional `limit` (1–50; default 20), `before_id` (positive integer), and `consent_key` (`analytics_consent` or `personalized_news_enabled`). Unknown query keys are rejected with HTTP 422 rather than silently applied.
- The response contains only `id`, `consent_key`, `granted`, `recorded_at` and a pagination cursor. No identity, email, credentials, IP, device data, or cross-account events are returned.
- Reads do not write to the append-only ledger and always return `Cache-Control: no-store, private`. No initial default opt-out record is manufactured. The password-reverified full JSON export remains available independently.

This is a partial P03-009 transparency feature; legal retention decisions and execution of erasure requests remain blocked. It introduces no new migration, deletion, role, locale, third-party integration or production data change.

## Consent evidence

Existing `PATCH /api/v1/me/preferences` remains compatible. It writes an append-only consent change record for real transitions of `analytics_consent` and `personalized_news_enabled` in the same transaction as preferences, serialized via a user row lock. Initial privacy-safe default is false and creates no tracking event. Unchanged submissions and locale-only updates create no event. PostgreSQL rejects updating or deleting past consent records.

No reading history is created. User interface locales remain `de`, `fr`, `it`, `en` with `en` fallback; unresolved ADR-004 is unchanged.

## Legal/production blockers

The deletion request state is deliberately `pending_legal_review`. Finance/legal retention periods, scope of personal-data erasure versus accounting/audit legal holds, backups, third-party exports, secure reauthentication strategy for web/mobile UX, notification templates, and verified destructive-processing workflow are **not approved or implemented**. No automated deletion, user record cascade or irreversible production action is introduced. Application DB owners can bypass audit triggers; production DB role separation remains required.

P03-009 remains **in_progress**: implemented data export, consent evidence and pending deletion requests do not satisfy the full export/delete acceptance criteria. Do not represent a pending request as completed erasure.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=ReaderPrivacyRequestsTest
php artisan test
```

Integration tests run only against disposable PostgreSQL, including IDOR/role isolation, password requirements, consent immutability, replays, revocation, and absence of destructive writes.
