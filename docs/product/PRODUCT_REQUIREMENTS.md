# Product Requirements Document (PRD) — Topkolik.com

## 1. Product Vision & Scope
**Topkolik.com** (`https://topkolik.com`) is a Türkiye-focused social sports and football fan platform combining:
- **Match-Centered Social Fan Experience (`ADR-015`):** Pre-match, live, and post-match Match Arenas with segmented discussion rooms (`Home Supporter`, `Away Supporter`, `Neutral`), live match polls, tactical reactions, automated content safety filtering, user blocking, abuse reporting, slow-mode rate controls, and MFA-protected staff emergency lockdowns.
- **Free-to-Play Score Predictions (`ADR-016`):** Strictly non-gambling, zero-stake match prediction challenges (`1-X-2` outcome, exact scoreline, total goal range `0_1` / `2_3` / `4_plus`, and first-scoring team) that lock at scheduled match kick-off and settle deterministically via a versioned scoring policy (`3` pts outcome, `5` pts exact score, `2` pts goal range, `2` pts first scorer; recomputed automatically on official score/VAR revisions).
- **Individual & Club Fan Rankings (`ADR-017`):** Individual analyst leaderboards with non-monetary achievement badges (`score_sniper`, `sharp_analyst`, `matchday_regular`) and club community rankings normalized by a minimum active supporter sample size (`min_club_ranking_sample_size`).
- **Autonomous Evidence-Bound Sports Journalism (`ADR-005`):** Multi-sport schedules, live scores, standings, statistics, and AI-assisted editorial reporting strictly grounded in verified, licensed source facts with an independent publication safety guard.

## 2. Target Platforms
1. **Flutter Mobile Applications (`apps/mobile`):** Native-feeling iOS (Apple HIG) and Android (Material 3) experience.
2. **Public SEO Web Platform (`apps/web`):** Next.js App Router SSR/ISR public portal for news, match centers, arenas, predictions, and leaderboards.
3. **Operations & Moderation Admin Panel (`apps/admin`):** Next.js staff console with mandatory MFA step-up and RBAC for editorial review, social arena moderation, sports source operations, support, ads, and finance governance.
4. **Core API & Workers (`services/api`):** Laravel 13 modular monolith backed by PostgreSQL 16 and Redis 7.

## 3. Brand & Design Constraints (`ADR-006`)
- **Primary Brand Green:** `#0B5D3B`
- **Electric Pitch Accent:** `#7CFF4D` (always paired with dark foreground `#0E1714` / `#1F2933` for WCAG 2.2 AA contrast)
- **Dark Neutral / Graphite:** `#1F2933`
- **Pure White:** `#FFFFFF`
- **Surfaces:** Light `#F4F7F5`, Dark `#111A17`
- **Typography:** `Montserrat` (headings/brand display), `system-ui` (iOS/web body), `Roboto` (Android body), tabular numerals (`font-variant-numeric: tabular-nums`) on all scores, timers, and leaderboard tables.

## 4. Localization Policy (`ADR-004`)
- **Supported UI Languages:** `de` (source language), `fr`, `it`, `en`, and `tr`.
- **Resolution Order:** Explicit user preference (`preferred_locale`) -> quality-weighted `Accept-Language` / device locale (`tr-TR` resolves automatically to `tr`) -> `en` fallback for unsupported locales.
