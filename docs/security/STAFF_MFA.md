# Staff MFA and session boundary (P03-006)

**Backend verified:** https://github.com/binegocom/TopKolik.com/actions/runs/41250991386 — 67 Laravel tests, 486 assertions, SMTP/HTTP and editorial guard passed. P03-006 is done at the backend slice; P03-GATE and release are not.

TOTP is the mandatory second factor for approved staff. The RFC 6238 implementation uses the maintained `pragmarx/google2fa:^9.1` package, not custom OTP cryptography. Staff secrets and pending secrets use Laravel encrypted model casts, backed by `APP_KEY`. Never log or display them outside a verified MFA setup response.

## Enrollment

Acceptance of a valid 24-hour emailed staff invitation returns an ephemeral `mfa_setup_token` (15-minute expiry), **not an access token**. Only a SHA-256 token hash is stored; it is never included in URLs. The client POSTs it to `/api/v1/staff/mfa/setup`, receives a TOTP secret and provisioning URI over HTTPS, then POSTs the setup grant plus a live TOTP to `/api/v1/staff/mfa/confirm`. The grant is deleted; the validated TOTP time step is recorded to prevent replay. Exactly 10 high-entropy single-use recovery codes are displayed once; only their SHA-256 hashes persist.

Staff identity remains inactive after MFA enrollment. A privileged operator must approve the account using `staff:activate` with an operator ID. No reader registration, invite acceptance or MFA setup can activate a staff member.

## Login and step-up

The `POST /api/v1/staff/auth/login` endpoint requires verified, MFA-enrolled and operator-approved staff credentials plus a fresh TOTP or one unused recovery code. Every TOTP is accepted at most once, with a ±1 time-step clock skew. Session bearer tokens have `staff:session` ability and expire after 15 minutes. A second fresh factor can mint a 5-minute token with `staff:step-up` (plus session). Recovery codes are permanently consumed in a PostgreSQL transaction with a staff-row lock.

Staff routes check that the token belongs to a `StaffMember`, is a Sanctum personal access token, has the expected ability and its staff account remains active/enrolled. **A reader's bearer token never grants access to staff routes.** Neither `staff:session` nor `staff:step-up` by itself grants any administration privilege. Role/object authorization remains blocked until P03-007.

## Operational boundaries

- All sensitive responses use `Cache-Control: no-store`.
- Strict IP/account/token rate limits and Laravel validator rules protect guesses.
- TOTP secret recovery/reset requires a separate authorized operator workflow; there is deliberately no public "disable MFA" endpoint.
- Backend bearer flows require a trusted server-side admin BFF; no token should be stored in browser localStorage. No admin UI or production access is implemented by this code.
- Account recovery remains an emergency-only workflow with audit and verification; P03-010 formalizes the immutable audit ledger.
- TOTP is susceptible to real-time phishing. WebAuthn/passkeys may be added in a reviewed future step; do not downgrade factors silently.
- Deployment/secret rotation, real SMTP provider and operational security controls remain release gates.
- Never run forward-only staff schema migrations on production without review and backup.

References: https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html and https://laravel.com/docs/13.x/eloquent-mutators
