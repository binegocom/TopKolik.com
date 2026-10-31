# Reader mobile authentication — initial implementation

## Scope

This slice implements an anonymous-first mobile reader API via Laravel Sanctum 4.3.x, email verification, token-based login, restricted profile read, and token revocation.

- Public news and sports-data read APIs remain unauthenticated.
- Only verified reader accounts can mint limited `reader:read` bearer tokens.
- Tokens are valid for up to 30 days and are stored hashed by Sanctum; the raw bearer token is delivered only at login.
- No staff, editor, administrator, advertiser or billing privileges are attached to reader enrollment. This is a **separate staff-auth and MFA feature**.
- Email verification link is short-lived, signed and sent to the registered email; it does not sign in a reader.
- Resend and registration use generic replies for registered/unknown email; registration and login are throttled.
- All authorization is enforced by middleware, not UI visibility.

## Browser and mobile security

Mobile bearer tokens should only be stored in Android Keystore/iOS Keychain using a vetted Flutter secure-storage integration. **Never store bearer tokens in browser localStorage or expose them in URLs.** Next.js web access must use Sanctum SPA cookie+CSRF or a secured server-side BFF after explicit deployment-domain decisions.

Email verification links are valid for an hour and use a signed GET URL. Email scanners can visit links; the future web confirmation flow should require explicit user interaction before confirmation. No frontend exists yet.

## Pending

Backend auth features tested here do not mean a complete end-user app; production SMTP and polished verification presentation are part of the web/mobile launch gates.


Password reset/revocation of all issued tokens, device management, staff invite-only identities, mandatory MFA, security event ledger, privacy/export/delete, and cross-advertiser authorization. Do not treat P03-GATE as complete.

## Verification

CI evidence: https://github.com/binegocom/TopKolik.com/actions/runs/41246890524 — 15 Laravel tests (52 assertions) and 17 stand-alone publication guard tests passed. Both P03-001 and P03-002 are backend-verified. Email delivery confirmed by real Mailpit SMTP in Actions run https://github.com/binegocom/TopKolik.com/actions/runs/41247084594. Production mail delivery and UI flows remain pending.


See `services/api/tests/Feature/ReaderAuthenticationTest.php`. CI must confirm actual PostgreSQL-backed migrations and Sanctum token persistence, email notification intent, signed URL protection, wrong credentials, logout revocation, anonymous access, and weak password rejection.
