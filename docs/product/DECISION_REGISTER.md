# Decision Register (ADRs)

| ID | Topic | Status / Decision |
|---|---|---|
| ADR-001 | Backend & API stack | **Approved**: Laravel 13, PHP 8.4, PostgreSQL 16, Redis 7, modular monolith (`services/api`) |
| ADR-002 | Client stacks | **Approved**: Flutter (iOS HIG / Android Material 3), Next.js App Router + TypeScript (`apps/web`, `apps/admin`) |
| ADR-003 | Cloud provider, region & monthly budget | **Open**; EU/TR latency, KVKK/GDPR transfer and cost ceilings require owner sign-off |
| ADR-004 | UI localization policy (`de`, `fr`, `it`, `en`, `tr`) | **Approved (2026-11-04)**: Supported UI locales are `de` (source), `fr`, `it`, `en`, and `tr`, with automatic `tr` resolution for `Accept-Language: tr*` and `en` fallback for unsupported locales |
| ADR-005 | Editorial autonomy & safety policy | **Approved**: Evidence-bound automated publishing for low-risk factual stories; mandatory human review for rumors, disciplinary/legal claims, injuries and conflicts |
| ADR-006 | Brand palette & typography | **Approved**: `#0B5D3B` primary, `#7CFF4D` accent, `#1F2933` dark neutral, `#FFFFFF` white, `Montserrat` headings |
| ADR-007 | Commercial sports-data provider license & coverage | **Open**; zero-cost/free-tier matrix documented (`docs/research/FREE_SPORTS_API_MATRIX.md`), commercial contract required before public live launch (`P01-004`) |
| ADR-008 | Club logo, crest, player photo & media rights | **Open**; separate from data feeds (`P01-005`); unlicensed crests/photos prohibited |
| ADR-009 | AI text/image vendor selection & retention terms | **Open**; vendor zero-retention and budget caps require owner approval |
| ADR-010 | Gambling, betting & odds advertising policy | **Open**; default **blocked** across all surfaces (`P01-007`) |
| ADR-011 | Ad-serving & analytics third-party vendors | **Open**; first-party consent-gated architecture only until approved |
| ADR-012 | Legal entity, tax residency & e-invoice jurisdiction | **Open**; required before live billing/invoicing (`P01-008`, `P12`) |
| ADR-013 | Public publisher identity & editorial contact | **Open**; required before public publication (`P01-002`) |
| ADR-014 | Domain, trademark & mobile store identifiers | **Open**; `topkolik.com` target domain; store bundle IDs and trademark check pending (`P01-001`) |
| ADR-015 | Social Match Discussion Arenas & Safety Moderation | **Approved (2026-11-04)**: Segmented match arenas (`home`, `away`, `neutral`), match polls, fan reactions, automated safety filter (`quarantined` state), user blocking, reporting, slow mode, and MFA staff emergency lockdown |
| ADR-016 | Free-to-Play Score Predictions & Deterministic Scoring | **Approved (2026-11-04)**: Strict non-gambling, free-to-play match predictions (`1-X-2`, exact score, goal range, first-scoring team) locked at scheduled kick-off; versioned policy (`3/5/2/2` pts) with deterministic VAR/revision recomputation |
| ADR-017 | Individual & Normalized Club Fan Rankings | **Approved (2026-11-04)**: Individual leaderboards with non-monetary achievement badges (`score_sniper`, `sharp_analyst`, `matchday_regular`) and club rankings normalized by minimum active supporter sample size (`min_club_ranking_sample_size`) |
