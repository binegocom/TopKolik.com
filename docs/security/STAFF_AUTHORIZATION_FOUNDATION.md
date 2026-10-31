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

`StaffResourceContext` is a typed internal context and must be constructed from a verified, repository-scoped persisted resource after loading it by authorized scope. **Never build it from API caller-provided IDs, state or owner attributes alone.** Actual articles/tickets/ads/invoices are not implemented in this repository, so no resource mutation API is exposed by this PR.

## Pending before P03-007 completion

- Actual article/ticket database models and backend policies with ownership checks after scoped SQL loads.
- Persistent advertiser team memberships/tenant isolation (P03-008), finance reconciliation approvals and four-eyes controls.
- Authenticated staff controller endpoints calling the defined Gate; BOLA/IDOR tests against real records and state transitions.
- Audit logging for authorization decisions and sensitive transitions (P03-010).
- No web/admin UI and no production rights transfer completed. Maintain P03-GATE open.

Policy unit/feature tests use isolated PostgreSQL test records and internal typed resource snapshots only, not fake production records.
