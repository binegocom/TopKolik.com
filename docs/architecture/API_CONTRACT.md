# API Contract — `/api/v1`

## 1. Health & Readiness
- `GET /health`, `GET /ready`, `GET /api/v1/status`

## 2. Reader Authentication, Profile, Localization & Privacy
- `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`
- `POST /api/v1/auth/password/forgot`, `POST /api/v1/auth/password/reset`, `GET /api/v1/auth/email/verify/{id}/{hash}`
- `GET /api/v1/me`, `PATCH /api/v1/me`
- `GET /api/v1/me/preferences`, `PATCH /api/v1/me/preferences` (`preferred_locale`: `de|fr|it|en|tr|null`, `effective_locale` resolved via `Accept-Language` with `en` fallback)
- `GET /api/v1/me/favorites`, `PUT /api/v1/me/favorites` (`sport_ids`, `team_ids`)
- `POST /api/v1/me/privacy/export`, `POST /api/v1/me/privacy/deletion-requests`, `GET /api/v1/me/privacy/deletion-request`, `GET /api/v1/me/privacy/consent-history`

## 3. Social Match Arenas, Polls, Reactions, Reporting & Blocking
- `GET /api/v1/matches/{fixture}/arena` (`reader:read`): Returns arena state, fixture header, reaction totals, polls with vote counts, and visible comments in `home|away|neutral` (excluding mutually blocked users).
- `POST /api/v1/matches/{fixture}/arena/comments` (`reader:write`): Posts a comment to `home`, `away` (requires following the club in `reader_favorite_teams`), or `neutral`. Enforces arena open state, slow-mode cooldown (`429`), block checks (`403`), and automated safety filter (`201` if `visible`, `202` if `quarantined`).
- `PUT /api/v1/matches/{fixture}/arena/reaction` (`reader:write`): Upserts reader reaction (`fire|goal_hype|tactical_applause|shock|heartbreak|whistle_protest`).
- `POST /api/v1/matches/{fixture}/arena/polls/{poll}/votes` (`reader:write`): Casts a single vote in an open arena poll (`409` on duplicate vote).
- `POST /api/v1/arena/comments/{comment}/reports` (`reader:write`): Submits a structured moderation report (`abuse|hate_speech|harassment|spam|misinformation|impersonation|illegal_content`).
- `PUT /api/v1/me/blocks/{targetUser}` and `DELETE /api/v1/me/blocks/{targetUser}` (`reader:write`): Blocks/unblocks another reader.

## 4. Free-to-Play Score Predictions & Fan Rankings
- `GET /api/v1/matches/{fixture}/prediction` (`reader:read`): Returns the authenticated reader's prediction slip, lock status (`locks_at`), and settled points breakdown if finished.
- `PUT /api/v1/matches/{fixture}/prediction` (`reader:write`): Creates or updates a free-to-play prediction before `starts_at` while `fixture.status === 'scheduled'` (`409` after kick-off). Rejects any wagering/stake fields (`422`).
- `GET /api/v1/rankings/users` (`reader:read`): Returns deterministic individual fan leaderboard with points, accuracy, and achievement badges (`score_sniper`, `sharp_analyst`, `matchday_regular`).
- `GET /api/v1/rankings/clubs` (`reader:read`): Returns club fan rankings normalized by `min_club_ranking_sample_size`.

## 5. Staff Operations, MFA, RBAC & Arena Moderation
- `POST /api/v1/staff/invitations/accept`, `POST /api/v1/staff/mfa/setup`, `POST /api/v1/staff/mfa/confirm`, `POST /api/v1/staff/auth/login`, `POST /api/v1/staff/auth/step-up`, `POST /api/v1/staff/auth/logout`, `GET /api/v1/staff/auth/me`
- `PATCH /api/v1/staff/moderation/arenas/{arena}/control` (`staff.session` + `staff:step-up`, `support|editor`): Updates arena state (`pre_match_open|live_open|post_match_open|archived|suspended`), `posting_enabled`, `slow_mode_seconds`, and emergency lock reason with append-only security audit logging.
- `POST /api/v1/staff/moderation/comments/{comment}/decision` (`staff.session` + `staff:step-up`, `support|editor`): Sets comment state (`visible|quarantined|hidden|removed`) with audit trail.
- `POST /api/v1/staff/moderation/reports/{report}/resolve` (`staff.session` + `staff:step-up`, `support|editor`): Resolves or dismisses a moderation report and optionally moderates the reported comment.
- Existing scoped endpoints for `/api/v1/staff/editorial/drafts`, `/api/v1/staff/support/tickets`, `/api/v1/staff/support/escalations`, `/api/v1/staff/ads/*`, `/api/v1/staff/security/*`, `/api/v1/staff/finance/*`, and `/api/v1/advertiser/*`.
