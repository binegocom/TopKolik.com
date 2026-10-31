# Identity, RBAC and account lifecycle

## Public users
Anonymous reading is default. Optional email registration with verification, secure password reset, rate-limited login, session/device list, optional passkeys or social sign-in only after explicit requirement, consent management, and self-service data deletion/export processes. Avoid collecting birth date, address or phone unless justified.

## Staff and advertisers
Separate auth scopes for staff, publisher, editor, data operations, bot operations, ad sales, ad operations, finance, support, security admin and analytics. Mandatory MFA for privileged accounts, short lived privileged sessions, revocation and audit, strong recovery process. Advertisers are scoped to their organization; never share data across advertisers.

## Authorize on server
Policies for every resource and transition. Never trust UI hidden controls or client-provided organization IDs. Scope SQL records, cached keys, storage object prefixes, queued jobs and notifications. Test cross-role and cross-organization IDOR, mass assignment, staff permission escalation and session fixation.

## Password and email flows
Use built-in Laravel secure hashing and password broker with one-time expiring tokens, generic email enumeration-safe responses, transactional reset, invalidation of sessions where required, throttling and security notifications. Verification links and reset URLs allowlisted. Email sending via queued providers with signed outbound URLs.

## Security operations
Audit privileged changes, handle IP/device signals in privacy-compliant way, block brute force, rotate secrets, review dependencies, prevent storage path traversal, apply CSP, HTTPS and CSRF/CORS correctly; threat-model webhook and AI prompt injections.
