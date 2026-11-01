# Independent support escalation review (P03-007)

Escalation is not a resolution. An assigned, active MFA-verified support operator may escalate an **open** ticket with a fresh step-up. The server selects a distinct active, verified, MFA-enrolled support operator as reviewer; the request cannot supply a reviewer ID. Selection is currently the lowest eligible staff ID, not a workload scheduler.

- Escalation atomically persists the reviewer assignment, pending state, original escalator and an allowlisted audit event. If no eligible reviewer exists, reviewer ID stays null and the ticket remains held. An operator cannot approve their own escalation.
- GET /api/v1/staff/support/escalations/{ticket} exposes the encrypted-at-rest ticket conversation only to its assigned reviewer with a current MFA step-up.
- POST /api/v1/staff/support/escalations/{ticket}/approve accepts only a review_note (1–2,000 characters). A PostgreSQL row lock, current role/assignment checks and an append-only audit insert atomically record one approval. Notes are encrypted at rest and not returned in the approval response.
- Only after independent approval may the original assigned support operator resolve the pending ticket with their own current MFA step-up. The reviewer does not acquire the original assignee's resolution permission.
- Missing/foreign reviewer assignments return 404; invalid role, revoked staff, expired or missing MFA step-up are denied. Responses are private and non-cacheable.

**Limitations:** This is a backend-only approval slice, not a complete escalation lifecycle. Escalations created before this migration and escalations with no eligible reviewer remain held; secure reassignment and rejection workflows, fair scheduling, user notifications, true parallel HTTP-worker tests and production audit shipping are not yet implemented. There is no public reviewer assignment or privileged bypass endpoint. P03-007 remains in_progress. No live sports data, new UI locale or destructive migration is introduced.
