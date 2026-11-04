# Screens and Workflows — Topkolik

## 1. Consumer Mobile (`apps/mobile`) & Public Web (`apps/web`)
1. **Onboarding & Localization (`de`, `fr`, `it`, `en`, `tr`):**
   - Guest-first browsing for public scores and news; skippable favorite sports and clubs selector (`PUT /api/v1/me/favorites`).
   - Automatic `tr` interface localization for Türkiye device/browser locales (`Accept-Language: tr*`) with user-selectable override (`de`, `fr`, `it`, `en`, `tr`) in Settings (`PATCH /api/v1/me/preferences`).
2. **Home & Live Match Strip:**
   - Sticky live/upcoming match strip, breaking verified stories, and quick entry into active Match Arenas.
3. **Match Detail & Social Match Arena (`ADR-015`):**
   - **Overview & Timeline:** Scoreboard, match status, verified events (goals, cards, substitutions, VAR), lineups, and statistics.
   - **Segmented Fan Tribune (`Home` / `Away` / `Neutral`):**
     - Verified followers of the Home or Away club can post in their dedicated supporter tribune; all verified readers can participate in the Neutral discussion room.
     - Live match polls (single vote per user), one-tap tactical reactions (`fire`, `goal_hype`, `tactical_applause`, `shock`, `heartbreak`, `whistle_protest`), visible slow-mode countdown banner, comment reporting modal, and instant user blocking.
4. **Prediction Center (`ADR-016`):**
   - Free-to-play pre-match prediction slip (`1-X-2`, exact scoreline, goal range `0_1`/`2_3`/`4_plus`, first-scoring team) with live countdown to kick-off lock and post-match settled points breakdown (`3/5/2/2` pts).
5. **Fan Rankings & Club League (`ADR-017`):**
   - Individual analyst leaderboard (`total_points`, `exact_score_hits`, `outcome_accuracy_pct`, achievement badges) and sample-size-normalized Club Supporters League (`is_qualified_for_official_rank`).

## 2. Staff & Moderation Operations (`apps/admin`)
1. **Editorial & AI Verification Queue:** Review held/quarantined drafts, verify source claims, and operate the emergency editorial kill switch.
2. **Social Arena Moderation & Safety Center:**
   - Real-time report queue (`moderation_reports`), one-click comment quarantine/removal, per-arena slow-mode slider (`0–3600s`), and MFA step-up protected emergency arena suspension (`PATCH /api/v1/staff/moderation/arenas/{arena}/control`).
3. **Support, Ads, Finance & Security Audit Consoles:** Scoped by role, dual-control approvals, and append-only audit logs.
