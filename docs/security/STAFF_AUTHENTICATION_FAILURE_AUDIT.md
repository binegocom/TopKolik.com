# Staff authentication failure audit (P03-010)

The API now records denied **validly formed** staff login attempts for both recognized and unknown identities and denied authenticated MFA step-up attempts. A new `security_auth_failures` table is separate from the successful-action `security_audit_events` ledger so unknown identities do not require fake staff records or nullable identities in the established ledger.

Rows contain only a fixed allowlisted action (`staff.login.failed` or `staff.step_up.failed`), an optional existing staff ID, a keyed HMAC-SHA256 identity fingerprint, a server-generated request UUID and timestamp. The HMAC key is the configured Laravel `APP_KEY`, never an empty constant. The raw email, password, MFA challenge, recovery code, bearer token, and source IP are not stored. Different spellings of the same normalized staff email correlate without revealing it. Secret rotation changes fingerprints; production operational runbooks must account for that. Failed authentication responses do not distinguish known from unknown staff.

Entries are inserted on credential refusal (inactive, unverified or invalid password) and when a valid identity fails the MFA factor, **outside any rolled-back authentication transaction**; this preserves failures while successful token issuance continues to use the existing transactional success audit. The database function, triggers and forward-only migration make these rows DML append-only for the application role.

**Coverage limits:** Schema-invalid requests rejected by Laravel validation, throttled requests rejected before controller execution, and missing/invalid staff bearer tokens rejected by auth middleware are not included in this specific ledger. A future central security telemetry/alerting pipeline must cover those edge cases. Authentication audit data has no public API or general staff query endpoint; only an approved incident/auditor authorization model should expose unknown-principal events.

**Release constraints:** Database owners/superusers can bypass DML triggers. Independent tamper-evident offsite shipping, least-privilege DB role, retention/legal policy, global security-analyst authorization and production drills remain unverified. `P03-010` stays `in_progress` despite this incremental authenticated-failure coverage.

**Check:** `php artisan migrate --force && php artisan test --filter=StaffAuthenticationFailureAuditTest && php artisan test` on isolated PostgreSQL/Redis. No destructive production data operations or sports content are involved.
