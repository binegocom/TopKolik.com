# P03-007 — Finance entitlement HTTP concurrency regression

## Purpose

Verify that independently authorized finance access activation and emergency
revocation preserve serializable authorization state under simultaneous real
HTTP requests. This is a backend regression harness, not a financial workflow
or a production load test.

## Execution

The `Laravel API checks` GitHub Actions job runs:

```bash
TOPKOLIK_CONCURRENCY_TEST=1 php scripts/check-finance-access-concurrency.php
```

The harness refuses to execute unless all of these conditions hold:

- GitHub Actions on Linux with explicit concurrency-test opt-in.
- Laravel is not in production mode.
- The default connection is PostgreSQL on `127.0.0.1`, with both database
  and user exactly `topkolik_dev`.
- The normal CI migrations and application tests have already passed.

It uses four real PHP HTTP workers and isolated, randomly identified staff
records. It does not migrate, reset, truncate or delete any database content.

## Assertions

1. Three independently granted, verified and MFA-enrolled security
   administrators have distinct identities: requester, approver and revoker.
   A fourth identity has the finance role but no initial entitlement.
2. A pending finance access request is created through authenticated HTTP.
3. Two simultaneous approvals compete for the same request while a third
   request attempts emergency revocation. Exactly one approval must commit;
   the other must return a conflict.
4. A revocation that races *before* activation returns not found, leaving the
   newly approved entitlement active. A revocation that follows activation
   succeeds and leaves it inactive. Either outcome must be consistent with
   the persisted decision and audit ledger.
5. Every successful transition has exactly one append-only audit event with
   the expected actor and a unique request correlation ID.
6. If an early revocation was rejected, a separate explicit retry must
   deactivate the entitlement. A replay cannot change state or append audit.
7. A pre-existing finance MFA bearer token reflects the committed entitlement:
   it can inspect access only while activation remains in effect and receives
   HTTP 403 immediately after revocation, including on replay.
8. A second pending finance access request races two approvals against one
   independent emergency staff deactivation. Either exactly one approval
   commits before offboarding, or both approvals conflict because offboarding
   committed first. The account, entitlement, grants and tokens stay inactive.
9. Persisted request state, actor attribution, unique request IDs, exactly-once
   audit events, rejected deactivation replay and HTTP 401 for the revoked
   finance bearer are asserted after the second race.
10. A separate Laravel feature regression checks a stale finance MFA token
   across persisted role downgrade, emergency revocation and role restoration.
   No new privilege is granted by these tests.

A rejected revocation is never treated as successful. In-flight approval must not undo emergency staff deactivation. The test does not
assume the timing of concurrent PostgreSQL transactions.

## Limits

The fixture creates test-only staff identities and grants in the disposable
CI database. No production administrator provisioning, finance resource
permissions, billing, payout, accounting, licensed sports feed, or external
audit anchoring is implemented by this test. The existing `P03-007` task
remains `in_progress` until its full authorization matrix is verified.
