# Staff onboarding — P03-005

Status: Staff invitation issuance and token redemption implemented as a **backend-only onboarding slice**. Staff login, MFA, RBAC, editor permissions and production admin UI **do not exist**.

## Identity and authorization boundaries

- `staff_members` is isolated from consumer `users`; a reader email matching a staff invite grants no privilege without the invite secret.
- No public endpoint can issue invitations, assign a staff role, authenticate a staff member or activate an admin account.
- Issuance is **privileged CLI only** (`php artisan staff:invite ...`), with operator identifier recorded; shell access controls are an infrastructure responsibility.
- Invitation roles limited to `editor`, `support`, `ads`, `finance` at service and PostgreSQL CHECK constraint. Superadmin is deliberately not available through this enrollment mechanism.
- Invite tokens are 64 random alphanumeric characters; only SHA-256 of a high-entropy token is retained. Expiration: 24 hours. One-time redemption uses a PostgreSQL transaction and row-level lock.
- Invite possession proves email control; password is hashed. Every accepted `staff_member` has `is_active=false`, `mfa_enrolled_at=null`, and **no staff authentication endpoint**.
- HTTP acceptance requires an explicit POST with password confirmation; GET scanners cannot activate an account. Client-supplied role/email/active/MFA status/user IDs are forbidden.
- HTTP acceptance is throttled by client IP and invitation identifier. An invalid/expired/reused token receives a generic rejection.
- No invitation token or password is written in terminal output. CLI refuses the logging mail transport to avoid sending secrets to logs.

## Production prerequisites

The trusted `STAFF_INVITATION_ACCEPT_URL` must point to a deployed **HTTPS** staff onboarding frontend with an explicit path, no pre-existing query/fragment. The HTTP localhost exception exists only for local/testing. An unset or unsafe value blocks issuance, not merely email delivery. No production mail service has been verified.

From a privileged server console after deployment and mail configuration:

```bash
php artisan staff:invite editor@example.org --role=editor --operator=operator-identifier
```

The example command is a documentation shape, not permission to invite that address. No role grant or employee account should be created without an authorized business operator.

## Follow-on tasks

P03-006: mandatory TOTP MFA setup and recovery before any staff session can be issued.
P03-007: backend policy/permission mapping with complete role matrix and default-deny tests.
P03-010: immutable security audit entries for invite issuance and acceptance.
P08/P10: staff onboarding frontend and editor/admin areas.

Acceptance checks use disposable PostgreSQL and real Mailpit SMTP in GitHub Actions. Never run this migration against production without reviewed backups and approvals.
