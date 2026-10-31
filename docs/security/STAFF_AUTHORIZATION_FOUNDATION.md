# P03-007 — Staff authorization foundation

**Status: in progress** until actual editorial, support, advertising and finance resource repositories implement scoped policies and end-to-end authorization tests. This slice adds no public privileges or administrative actions.

Laravel Gate `staff-resource` delegates to `StaffAuthorizationPolicy`, checking a **fresh database copy** of staff activation, MFA enrollment and role on each decision, plus the token owner/type, valid expiry and `staff:session` ability. High-risk actions additionally require the short-lived `staff:step-up` token issued after an additional factor.

## Initial role boundaries

| Role | Allowed action family in policy | Ownership/assignment scope | State constraints |
|---|---|---|---|
| editor | Draft read/edit/submit; correction proposal | Own editorial resource only | Edit/submit in draft; correction proposal on published with step-up |
| support | Ticket view/reply; escalation | Assigned ticket only | Reply open/pending; escalation open with step-up |
| ads | None yet | Blocked pending approved advertiser membership and media approvals | Deny |
| finance | None yet | Blocked pending finance responsibilities and dual approvals | Deny |
| unknown/superadmin/reader | None | Never self-assignable | Deny |

No editor action authorizes publication; publication must separately satisfy licensing, claim verification and immutable evidence gating. No support ticket access is granted without assignment. No advertising or finance privileges can be inferred from a role string.

## Resource data integrity

`StaffResourceContext` is a typed internal context and must be constructed from a verified, repository-scoped persisted resource after loading it by authorized scope. **Never build it from API caller-provided IDs, state or owner attributes alone.** A minimal persisted support-ticket model and assigned-ticket read endpoint now exist; articles, advertiser resources and invoices are not implemented. No support ticket mutation API is exposed.

## Pending before P03-007 completion

- Actual editorial article records and authorization after scoped SQL loads; support ticket read now uses assignment-scoped SQL and Gate, but reply/escalation remain unimplemented.
- Persistent advertiser team memberships/tenant isolation (P03-008), finance reconciliation approvals and four-eyes controls.
- Extend authenticated Gate enforcement to draft editing and ticket state-changing actions, with BOLA/IDOR tests against real persisted records and transitions.
- Audit logging for authorization decisions and sensitive transitions (P03-010).
- No web/admin UI and no production rights transfer completed. Maintain P03-GATE open.

Policy unit/feature tests use isolated PostgreSQL test records and internal typed resource snapshots only, not fake production records.

## P03-007 support-ticket read slice

- A forward-only migration creates empty `support_tickets` storage with FK assignment to `staff_members`, an indexed assignment/status lookup, encrypted subject/body Eloquent casts, and a PostgreSQL status check. No runtime tickets are fabricated or seeded.
- `GET /api/v1/staff/support/tickets/{ticket}` requires a valid short-lived staff bearer session, verified MFA and an active staff member. The query filters `assigned_staff_id` **before** authorization; another operator's or unassigned ticket yields 404. The Gate additionally checks the persisted support role and allowed status.
- Responses contain only the assigned ticket's content and status, and are marked `Cache-Control: no-store, private`. There is no ticket list, create, reply, reassignment, export, or public API yet.
- Feature tests cover assignment IDOR, role changes, deactivation, expired sessions, reader tokens and non-cacheability against disposable PostgreSQL in CI.
- This is a partial P03-007 deliverable, not RBAC completion. No claim of production readiness, legal retention approval or P03-GATE closure.
