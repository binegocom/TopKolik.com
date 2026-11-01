# P03-007 — Ads assignment approval / security grant revocation race

## Scope

An already pending two-person advertiser assignment approval must not grant
access based on a stale requester security-administrator grant. The approver
and the original requester must both retain independent, active authority at
the time of the decision. This change does not introduce a grant-creation
endpoint, a role mutation, campaign publication or advertiser onboarding.

## Authorization and lock order

The approval transaction first locks the acting approver's security grant,
then the pending decision row, the original requester staff row and the
original requester's security grant. Only after those checks does it lock the
ads target and advertiser organization and write the assignment, decision and
append-only audit. The existing grant revocation transaction locks its actor
grant before the target grant.

A requester revocation that wins the row lock causes approval to fail with
HTTP 409, leaving the request pending and no assignment. An approval that
commits first is a valid earlier authorization; a subsequent revocation
cannot retroactively change its committed decision. Concurrent duplicate
approvals remain exactly-once. PostgreSQL transaction deadlocks are retried
by the existing bounded transaction retry mechanism.

## CI verification

`TOPKOLIK_CONCURRENCY_TEST=1 php scripts/check-ads-assignment-concurrency.php`

The script starts four real PHP HTTP workers, creates isolated random staff
identities and one advertiser organization, and races two authenticated
approval requests against an independent administrator's requester-grant
revocation. It asserts serializable outcomes, persisted assignment state,
exactly-once audit attribution, unique request IDs, rejected replays and
actual ads staff HTTP visibility. The Laravel feature suite separately
checks a completed requester-grant revocation followed by an attempted
approval.

The harness refuses to run unless `CI=true`, `GITHUB_ACTIONS=true`, Linux,
explicit opt-in, a non-production Laravel environment and a PostgreSQL
connection to `127.0.0.1` using `topkolik_dev` as both database and user.
It does not migrate, reset, truncate or delete database records.

## Limits

This is an isolated backend concurrency regression, not a production load
test. Commercial advertiser agreements, approved operator provisioning,
full finance resource permissions, external sports-data licenses, real
production environment isolation and deployment remain unverified.
`P03-007` remains `in_progress` until its complete RBAC matrix is accepted.
