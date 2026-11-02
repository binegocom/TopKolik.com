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

## Residual grant revocation versus finance offboarding

The same guarded four-worker harness creates a separate active finance identity with a historical active security-administrator grant and advertiser assignment, plus an existing finance MFA bearer token. These rows represent stale cross-role privileges, not authorization for finance to administer advertising.

An independent administrator revokes the target's security grant while a different independent administrator deactivates the finance account. Revocation may commit first (HTTP 200) or observe an already deactivated grant (HTTP 404); offboarding must commit once (HTTP 200). In both serial orders, finance entitlement, administrator grant, advertiser assignment, staff account and all bearer tokens must be inactive. The audit ledger must contain one offboarding event and at most one grant-revocation event, each with correct actor and unique request ID. Replays return 404 without extra audit writes, and the stale finance token returns 401.

This is a CI-only regression for residual grants, not an advertiser assignment approval race: an ads assignment cannot legitimately be approved for a current finance role. No destructive migration, production credentials or new privileges are introduced.

Code/docs verification: PR #72 commit `6805fe51` passed [Laravel CI 41353972644](https://github.com/binegocom/TopKolik.com/actions/runs/41353972644) (313 tests / 2,674 assertions, both real four-worker race harnesses, PostgreSQL/Redis, HTTP/SMTP, Composer audit) and [editorial CI 41353972632](https://github.com/binegocom/TopKolik.com/actions/runs/41353972632). The final evidence commit requires fresh CI before merge.

## Finance request creation versus emergency staff offboarding

The existing four-worker, CI-only harness now also races two authenticated
finance-access request creations against one independent administrator's
one-way emergency deactivation of the same eligible finance staff member.
The two request attempts use the same authenticated administrator, while
offboarding uses a different authorized MFA administrator. Valid serialized
outcomes are one pending request with a duplicate conflict, one pending
request with an inactive-target rejection, or two inactive-target rejections.
The offboarding operation must commit exactly once.

After the race, the staff account must be inactive, the pre-existing MFA
bearer must be revoked, and no active finance entitlement may exist. A
request committed before offboarding remains pending by design, but a
subsequent approval by a third independent administrator must return 409
without changing the request or restoring access. The audit ledger must
contain one offboarding event and exactly one request event if and only if
request creation committed, each with its correct actor and a unique
server-issued request ID. Offboarding replay must return 404 without a
second audit event.

This regression uses disposable CI identities only. It adds no finance
activation API, external provider, production fixture, migration or new
permission. A pending request left behind by emergency offboarding remains
visible for operational review, not silently deleted or approved. Task
`P03-007` stays `in_progress` pending complete RBAC acceptance.
