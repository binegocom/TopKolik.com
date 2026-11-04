# Canonical Data Model — Topkolik

## 1. Core Sports Catalog & Fixtures
- `sports(id, slug, name, timestamps)`
- `competitions(id, sport_id, slug, name, country_code, provider_ref, timestamps)`
- `seasons(id, competition_id, label, starts_on, ends_on, is_current, timestamps)`
- `teams(id, sport_id, slug, name, timestamps)`
- `fixtures(id, competition_id, season_id, home_team_id, away_team_id, status, starts_at, home_score, away_score, first_scoring_team, source_revision, timestamps)`
  - Constraints: `home_team_id <> away_team_id`, `status IN ('scheduled','live','finished','postponed','cancelled')`, `source_revision >= 1`.

## 2. Reader Identity, Localization, Privacy & Favorites
- `users(id, name, email, email_verified_at, password, timestamps)`
- `reader_preferences(user_id PK, preferred_locale, personalized_news_enabled, analytics_consent, timestamps)`
  - Supported `preferred_locale`: `de`, `fr`, `it`, `en`, `tr` (nullable; `null` resolves via `Accept-Language` with `en` fallback).
- `reader_favorite_sports(user_id, sport_id, timestamps)`
- `reader_favorite_teams(user_id, team_id, timestamps)`
- `reader_consent_events(id, user_id, consent_key, granted, created_at)` (append-only trigger)
- `reader_deletion_requests(id, user_id, status, requested_at)`

## 3. Social Match Arenas, Polls, Reactions & Moderation (`ADR-015`)
- `match_arenas(id, fixture_id UNIQUE, state, posting_enabled, slow_mode_seconds, emergency_locked_by_staff_id, emergency_lock_reason, emergency_locked_at, timestamps)`
  - `state IN ('scheduled','pre_match_open','live_open','post_match_open','archived','suspended')`.
- `arena_comments(id, match_arena_id, user_id, parent_comment_id, section, body, moderation_state, moderation_reason, moderated_by_staff_id, moderated_at, timestamps)`
  - `section IN ('home','away','neutral')`, `moderation_state IN ('visible','pending_review','quarantined','hidden','removed')`.
- `arena_reactions(id, match_arena_id, user_id, reaction_type, timestamps)` (`UNIQUE(match_arena_id, user_id)`)
- `arena_polls(id, match_arena_id, question_key, question_text, is_closed, closes_at, timestamps)`
- `arena_poll_options(id, arena_poll_id, option_key, label, sort_order, timestamps)`
- `arena_poll_votes(id, arena_poll_id, arena_poll_option_id, user_id, timestamps)` (`UNIQUE(arena_poll_id, user_id)`)
- `user_blocks(blocker_user_id, blocked_user_id, created_at)` (`CHECK (blocker_user_id <> blocked_user_id)`)
- `moderation_reports(id, reporter_user_id, arena_comment_id, reason_category, details, status, resolved_by_staff_id, resolution_note, resolved_at, timestamps)` (`UNIQUE(reporter_user_id, arena_comment_id)`)

## 4. Score Predictions, Settlement & Fan Rankings (`ADR-016`, `ADR-017`)
- `prediction_policies(id, version_code UNIQUE, outcome_points, exact_score_points, goal_range_points, first_scorer_team_points, min_club_ranking_sample_size, is_active, timestamps)`
- `match_predictions(id, fixture_id, user_id, policy_version, predicted_outcome, predicted_home_score, predicted_away_score, predicted_goal_range, predicted_first_scoring_team, submitted_at, timestamps)` (`UNIQUE(fixture_id, user_id)`)
- `prediction_settlements(id, match_prediction_id UNIQUE, fixture_id, user_id, policy_version, outcome_points_awarded, exact_score_points_awarded, goal_range_points_awarded, first_scorer_points_awarded, total_points, is_exact_score_hit, is_outcome_hit, fixture_source_revision, settled_at, timestamps)`

## 5. Staff RBAC, MFA, Support, Editorial, Ads, Finance & Security Audit
- `staff_members`, `staff_invitations`, `support_tickets`, `support_ticket_replies`, `editorial_articles`, `advertiser_organizations`, `advertiser_accounts`, `advertiser_campaigns`, `advertiser_campaign_review_events`, `advertiser_campaign_review_feedback`, `staff_advertiser_assignments`, `staff_ads_assignment_approvals`, `staff_security_admin_grants`, `staff_finance_entitlements`, `staff_finance_access_approvals`.
- `security_audit_events` & `security_auth_failures` (append-only PostgreSQL triggers).
