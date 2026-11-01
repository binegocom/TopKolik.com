# P03-007 — Workload-aware support escalation reviewer selection

## Scope

All three existing reviewer assignment paths now use the same server-side
`SupportEscalationReviewerSelector`:

- Initial assigned-staff escalation.
- Current reviewer's MFA-protected relinquishment.
- Independently granted security administrator's held-case recovery.

Eligible reviewers must currently be active, email-verified, MFA-enrolled
support staff. The selector excludes the ticket assignee, original escalator,
current relinquishing reviewer and, on recovery, the security administrator.
No caller may choose a reviewer ID. If there is no eligible reviewer, existing
hold/recovery behavior remains unchanged.

The selector counts only assigned tickets that are pending, escalated and
not yet independently reviewed. The lowest pending workload wins; staff ID
breaks ties deterministically. The count is not exposed in API responses.

## Transaction and security behavior

A fixed PostgreSQL `pg_advisory_xact_lock` key serializes reviewer selection
among all three assignment paths within their existing ticket transactions.
The chosen staff row is also locked before mutation. This prevents competing
assignment requests using this selector from observing the same old workload
and preserves existing transaction retry and audit rollback semantics.

No schema migration, external provider data, new privileges or background
auto-assignment were introduced. Existing MFA, RBAC, IDOR, private response
headers, assignment separation constraints and append-only audit behavior
remain authoritative.

## Verification and limitations

The PostgreSQL-backed `StaffSupportReviewerWorkloadTest` exercises initial
escalation, reviewer relinquishment, security recovery and exclusion of
completed reviews. Existing security and inbox tests remain required.

```bash
cd services/api
php artisan migrate --force
php artisan test
```

The advisory-lock protocol only coordinates writers that use this selector;
manual SQL updates and future assignment code must follow the same protocol.
A true simultaneous multi-process HTTP worker contention test is still
required before claiming concurrency acceptance. P03-007 remains
`in_progress` and production release is not authorized.
