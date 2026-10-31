# Editorial draft workflow — scoped P03-007 implementation

**Status:** Implementation in review. The repository now has private, revisioned editorial draft persistence; publishing remains forbidden and P03-007 is still incomplete.

## API
- `GET /api/v1/staff/editorial/drafts`: paginate at most 20 non-public drafts belonging to the authenticated editor, without body text in list.
- `POST /api/v1/staff/editorial/drafts`: create a draft for the authenticated MFA-enrolled active editor, never a client-selected owner or state.
- `GET /api/v1/staff/editorial/drafts/{draft}`: SQL owner scope and a current role/state Gate check before reading encrypted content.
- `PATCH /api/v1/staff/editorial/drafts/{draft}`: requires current revision and permitted field changes, PostgreSQL row lock, conflict 409 for stale writes. Only draft state is editable.
- `POST /api/v1/staff/editorial/drafts/{draft}/submit`: moves only a matching revision in draft state to in_review; submitted_at recorded, revision incremented. No publishing, search indexing, external notification or AI generation is invoked.

## Security and limitations
- Only `staff:session` token belonging to the active, verified, MFA-enrolled staff editor. Other roles or reader sessions are denied. Current persisted role is rechecked by the policy.
- Draft title/body are encrypted at rest; authorization and private/no-store responses keep unpublished content private.
- Owner scope is in SQL before decrypting or evaluating the authorization context. No client-supplied owner ID, state, revision override or mass-assignment.
- The database contains no fabricated sports fixtures, provider events or published articles. All test data are isolated disposable PostgreSQL fixtures.
- Server-side publication must first connect real licensed provider evidence to the publication guard, pass claim/licensing checks and preserve version history (P06).
- Editorial review staff separation, full audit ledger, advertiser scopes, CMS UI, provider integrations, cross-object BOLA suite and legal approval remain pending. No production migration has been run.
