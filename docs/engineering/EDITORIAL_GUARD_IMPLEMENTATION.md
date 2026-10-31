# Publication guard — first implementation slice

## Implemented scope

An independent, dependency-free PHP domain guard at `services/api/app/Domain/Editorial`. It evaluates whether automated publication may proceed for approved news classifications using current, independently cleared source rights, expiry, claim evidence, final match status, media authorization, contradiction checks and a global publication switch.

Its decision is **preliminary**; only integration with Laravel transactions/outbox, persistence, provider source records, authentication and media pipeline can enforce these checks end-to-end. Do not expose this as a working publication service.

## Local tests

```bash
find services/api/app/Domain/Editorial services/api/tests/Unit -name '*.php' -print0 | xargs -0 -n 1 php -l
php services/api/tests/Unit/PublicationGuardTest.php
```

2026-10-31 local validation: PHP 8.4.24, 5 syntax passes, 17 unit scenarios pass. No composer dependencies used. Laravel integration **not** built or tested. Provider licenses are **not** approved.

## Integration requirements

- Guard input must be assembled by trusted backend services, never from user-provided Boolean approvals.
- `sourceVerified`, `sourceCurrent` and commercial-license flags must come from audited source and license registry.
- Check time through a server UTC clock and re-evaluate inside a transaction immediately before publication.
- Add unique event/document keys, row-level claim evidence, editorial policy version, immutable article revisions and transactional outbox.
- Sign-off needed on auto-publish classes and final sports-data and media agreements.
