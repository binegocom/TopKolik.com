# P03-007 — Staff advertising assignment self-service

## Supported operations

The API provides strictly self-scoped operations for already-assigned advertising staff:

- `GET /api/v1/staff/ads/assignments?limit=20&before_id=...` — only this authenticated staff member's active assignments for active advertiser organizations, as organization ID/name and assignment ID. Maximum page size 50, descending keyset pagination; no other staff IDs, advertiser contacts, creative bodies or inactive assignments.
- `POST /api/v1/staff/ads/assignments/{organization}/relinquish` — opt out of one's **own active assignment** after reauthentication with a current five-minute MFA step-up token. It sets `is_active=false`; it does not delete the assignment or organization. Repeated requests return 404 and do not add audit entries.

Both endpoints require persisted, active, verified, MFA-enrolled **`ads` staff** with a current bounded `staff:session` bearer token. The write operation additionally requires `staff:step-up`, an active organization and current assignment role authorization. A row lock and conditional SQL update guard against role or assignment revocation races. The same transaction appends the real `staff.advertiser_assignment.relinquished` / `advertiser_organization` metadata event to the protected security audit ledger. If audit insertion fails, the state change is rolled back.

Session grant, assignment creation, activation, role delegation, approval, campaign publishing and finance rights **are not available**. Staff who relinquish access cannot re-enable it themselves. Client-selected actor and organization selectors are rejected. Private non-cacheable responses and route throttling apply.

This is application-scoped authorization, not a PostgreSQL RLS policy. Existing privileged database users can bypass audit triggers; external tamper-evident audit export, dedicated security administration and legal retention policies remain release prerequisites. `P03-007` stays `in_progress`: full role matrix, reviewed assignment provisioning and finance dual-approval are not complete.

## Verification

```bash
cd services/api
php artisan migrate --force
php artisan test --filter=StaffAdsAssignmentSelfServiceTest
php artisan test
```

The GitHub CI job uses isolated real PostgreSQL/Redis with negative tests for cross-staff IDOR/BOLA, wrong token principals, role changes, expired sessions, MFA step-up, self-revocation replays, no grant route and ledger evidence. No new schema migration or destructive data operation is needed.
