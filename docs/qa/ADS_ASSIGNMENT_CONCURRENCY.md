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
identities and advertiser organizations in two isolated scenarios. It races
two authenticated approvals against independent revocation of the original
requester's grant, then races two approvals against revocation of the acting
approver's grant. It asserts serializable outcomes, persisted assignment state,
exactly-once audit attribution, unique request IDs, rejected replays and
actual ads staff HTTP visibility. The approver race accepts only the two serialized outcomes: revocation
first rejects both approvals (403); approval first commits exactly once
(200, then 403 or 409). In both cases the approver's old MFA token cannot
approve or reject after revocation. Both scenarios verify assignment state,
audit uniqueness and actual advertiser isolation. The Laravel feature suite
separately checks completed requester and approver grant revocations before
decision attempts.

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

## Verification evidence

[PR #73](https://github.com/binegocom/TopKolik.com/pull/73)
code SHA `489d40945f3a4bca96c73d0228881a26ed081a85`:
[Laravel API CI 41359323946](https://github.com/binegocom/TopKolik.com/actions/runs/41359323946)
passed 314 tests / 2,685 assertions, including the real four-worker ads
approval/revocation harness, PostgreSQL/Redis, HTTP/SMTP, Composer audit and
17 editorial guard checks. [Editorial/roadmap CI 41359323964](https://github.com/binegocom/TopKolik.com/actions/runs/41359323964)
passed. Final evidence commit CI is required before merge.

## Approver-grant race verification

[PR #74](https://github.com/binegocom/TopKolik.com/pull/74), code SHA
`f2c26e79eb574fbcc24e1a1ee199eb00c47119c4`:
[Laravel API CI 41364309164](https://github.com/binegocom/TopKolik.com/actions/runs/41364309164)
passed 315 tests / 2,698 assertions, including both four-worker ads
approval/grant revocation scenarios, PostgreSQL/Redis, real HTTP/SMTP,
Composer audit and 17 editorial guards. [Editorial/roadmap CI 41364309196](https://github.com/binegocom/TopKolik.com/actions/runs/41364309196)
passed. The final evidence commit requires its own successful CI before merge.
No production verification is implied.

## Requester emergency-offboarding race

A finance-role security administrator can also hold a separately approved
security-administrator grant. An independent operator may deactivate that
finance staff account while an advertiser assignment request is being
created. Request creation now locks the current administrator's persisted
staff row after its security grant and rechecks active status, verified email,
MFA enrollment and non-ads role within the transaction. This makes the
account eligibility check part of the same serialization boundary as the
one-way emergency deactivation. No new grant or role mutation is introduced.

The third four-worker HTTP scenario races a new assignment request against
emergency deactivation of its finance-role requester. If creation commits
first, it leaves a pending request; if deactivation commits first, creation
fails closed. In both outcomes the staff account, its security grant and
existing tokens remain revoked. Any pending request is rejected at the
separate second-person approval step. The harness checks actor-attributed,
unique audit events, replay denial and the absence of target advertiser access.

The Laravel feature suite also checks revoked and newly issued tokens after
emergency deactivation. The race harness runs only in isolated CI with the
existing strict environment guards and does not delete production data.

CI verification and final PR SHA must be recorded before this change is
accepted. P03-007 remains in_progress; full RBAC and production validation
are outside this incremental regression.

## Requester-offboarding CI evidence

[PR #76](https://github.com/binegocom/TopKolik.com/pull/76), code SHA
`b3ec83f25934a66234c01990815749ea9316b546`:
[Laravel API CI 41369624509](https://github.com/binegocom/TopKolik.com/actions/runs/41369624509)
passed 325 tests / 2,879 assertions, including the third real four-worker
ads request/emergency-offboarding race and the existing ads, finance and
support concurrency harnesses. Real PostgreSQL/Redis, HTTP/SMTP,
Composer audit and 17 editorial guard cases passed.
[Editorial/roadmap CI 41369624621](https://github.com/binegocom/TopKolik.com/actions/runs/41369624621)
passed. The final evidence commit requires fresh CI before merge.
