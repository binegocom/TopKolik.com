# Reader profile, language, privacy and favorites — P03-004

## Implemented API contracts

- `GET /api/v1/me`: reader account details, preferences, and favorites.
- `PATCH /api/v1/me`: validated own display name only; no email, role or user ID changes.
- `GET /api/v1/me/preferences` and `PATCH /api/v1/me/preferences`: locale preference, personalization preference and analytics consent.
- `GET /api/v1/me/favorites` and `PUT /api/v1/me/favorites`: read or atomically replace sets of canonical sport/team IDs.

All endpoints use Laravel Sanctum `auth:sanctum`. GET requires `reader:read`; mutations require `reader:write`. New mobile login tokens include only those two reader abilities, **not staff/admin**. Previously issued read-only tokens must reauthenticate to write. Browser bearer-token storage remains prohibited; web BFF/session integration is a future task.

## Privacy and localization

- Default personalization and analytics consent are **false**.
- Reading history collection is not implemented. `reading_history: {supported:false,enabled:false}` is explicit; attempts to set `reading_history_enabled` fail validation.
- `preferred_locale: null` uses quality-weighted `Accept-Language`; supported `de`, `fr`, `it`, `en`; region variants reduce to base language, unsupported falls back to `en`.
- The Türkiye-first Turkish UI locale decision is still open (ADR-004). Do not silently add a localization language; Turkish sports reporting and UI localization are separate decisions.
- No personal reading history, last-viewed articles, IP address, location or device fingerprint is stored in these preference tables.
- Consent fields record user choices; instrumentation and consent enforcement in future analytics/news feeds must honor them before collecting or personalizing anything.

## Referential favorites

Schema includes initially **empty** canonical `sports` and `teams` tables required for non-orphan favorites. Real sports/team ingestion, provider mapping, translations, season/competition data and complete catalog APIs are **P04** work and are not implemented. No real or fake team/sport entries are seeded in application runtime. Test fixtures in isolated disposable PostgreSQL are limited to feature verification.

- Favoriting a missing team or sport fails validation with 422.
- Composite primary keys enforce no duplicates; FKs enforce referential integrity.
- Favorite replacement requires **both** `sport_ids` (max 30) and `team_ids` (max 100); validates first, then changes both sets in a transaction locking the authenticated user row. Explicit empty lists clear favorites.
- User IDs come strictly from authenticated sessions/tokens; caller-selected `user_id` is rejected.

## Follow-up dependencies

- P03-009: complete privacy export, deletion, consent evidence and retention requirements.
- P04-001/002: populate/extend licensed canonical sports and team models.
- P08/P09: premium web/mobile UI design, user locale presentation, settings/favorites onboarding and secure device token storage.
- P13: personalized news delivery and consent-aware analytics. Do not claim personalization feed exists.
- Staff admin RBAC/MFA remains unfinished.

## Verification

Run `php artisan migrate --force && php artisan test` on an isolated PostgreSQL database. CI executes migrations and all tests. No production schema/data migrations have been executed.
