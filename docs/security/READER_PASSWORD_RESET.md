# Reader password reset — backend slice

## Endpoints

- `POST /api/v1/auth/password/forgot`: accepts an email; always replies HTTP 202 with a generic message if password reset delivery is configured, independent of account existence, broker eligibility or broker throttle. Per-IP/per-email rate limiting returns 429 uniformly.
- `POST /api/v1/auth/password/reset`: email, one-time token, new password, confirmation; returns HTTP 200 on success and the same generic HTTP 422 body for invalid/expired/nonexistent user or token. Laravel's password broker stores reset token hashes and expires after 60 minutes.
- Password complexity matches reader registration: >=12 characters, mixed case, digits, symbols.
- Each successful reset revokes **all** Laravel Sanctum reader bearer tokens and database session records, and rotates the remember token; user-row locks serialize concurrent requests for the same account.
- The API never returns the reset token. Only the account's verified delivery channel receives the token link. No automatic login after password reset.

## Trusted reset URL

The `READER_PASSWORD_RESET_URL` variable must point to an operator-controlled **frontend reset form**. In production it must be HTTPS with an explicit path; no credentials, query, or URL fragments. Localhost HTTP is permitted only in local/testing environment. No request-supplied origin, redirect or Host header is used.

**There is not yet a Topkolik frontend password form.** An unset or unsafe URL causes the forgot API to return HTTP 503 to **every** caller, rather than sending unusable or unsafe links. The eventual frontend must remove the token from browser history, set `Referrer-Policy: no-referrer`, avoid third-party scripts, provide an explicit user action, and POST the token to the backend.

## Security and operational limits

- Reset request rates: max 8/min/IP and 3/hour/email; redemption max 10/min/IP and 5/hour/email.
- Laravel broker also throttles token generation (60s), keeps token hashed and expires token at 60 minutes.
- Existing sessions from user_id are deleted in the same transaction as password update and Sanctum token revocation.
- Account enumeration is mitigated by uniform responses for eligible/unknown/throttled accounts. Identical network timing is not guaranteed; add monitoring and production threat testing.
- Reset email delivery is tested with Mailpit in CI, **not** an actual production SMTP provider.
- Staff MFA, browser cookie/CSRF login, password reset UI, production observability, abuse detection and backup/restore remain outstanding, so P03-GATE is still open.

## Tests

`services/api/tests/Feature/ReaderPasswordResetTest.php` uses a disposable PostgreSQL database via the established CI workflow; verifies token hashing, expiration, replay, cross-account use, revoked sessions/tokens, missing or unsafe links, password strength, generic responses and rate limits.

Official guidance: https://laravel.com/docs/13.x/passwords
