# Sports Data Provider Assessment — P01-003

> **Status:** `in_progress` — Comprehensive desk research and zero-cost-first evaluation completed (`docs/research/FREE_SPORTS_API_MATRIX.md`). Commercial provider credentials, signed redistribution/AI-editorial contracts (`P01-004`), and club/player visual asset rights (`P01-005`) remain explicit owner/legal gates before public live-score launch.

## 1. Required Capability Matrix
Topkolik requires canonical, provider-independent ingestion across:
- **Priority 1 (Launch Core):** Football (`Süper Lig`, `1. Lig`, `Türkiye Kupası`, UEFA Champions League, UEFA Europa League, UEFA Conference League, and major European leagues).
- **Priority 2 (Multi-Sport Expansion):** Basketball (`BSL`, `EuroLeague`), Volleyball (`Sultanlar Ligi`, `Efeler Ligi`, `CEV`), Tennis (`ATP/WTA`), Motorsport (`Formula 1`).
- **Data Domains:** Competitions, seasons, teams, venues, scheduled fixtures, live scores, match events (goals, cards, substitutions, VAR revisions), lineups, standings, and normalized team/player statistics.

## 2. Zero-Cost-First & Commercial Provider Summary
See [`docs/research/FREE_SPORTS_API_MATRIX.md`](FREE_SPORTS_API_MATRIX.md) for the complete 20-criterion evaluation and quota calculations.

| Provider / Source | Türkiye Football Coverage | Multi-Sport | Free / Open Tier Viability | Commercial Production Status |
|---|---|---|---|---|
| **API-Football / API-Sports** | Süper Lig, 1. Lig, Türkiye Kupası, UEFA | Basketball, Volleyball, F1 (separate APIs) | 100 req/day free tier suitable **only** for non-commercial adapter contract testing | Requires paid commercial subscription & written terms check (`P01-004`) |
| **football-data.org** | `TR1` excluded from free tier (CL/top-5 EU included) | Football only | 10 req/min free tier usable for EU competition schema testing | Requires paid plan + commercial redistribution review |
| **TheSportsDB** | Partial Süper Lig metadata; crowd-edited | Partial | 30 req/min free v1 key; crowd-sourced artwork lacks trademark indemnity | Not sufficient as authoritative live match or licensed crest source |
| **Sportradar / Stats Perform (Opta)** | Official enterprise grade | Full multi-sport | No public free tier | Enterprise commercial contract (`ADR-007`) |
| **TFF (`tff.org`) / UEFA Public Pages** | Official HTML pages | Football only | **Rejected** — no public commercial API; scraping violates terms | Unlicensed scraping strictly prohibited |
| **`openfootball` / StatsBomb Open Data** | Historical / static only | Football only | Public domain (`openfootball`) / non-commercial (`StatsBomb`) | Suitable for local seed schemas only; zero live coverage |

## 3. Architectural Enforcement (`services/api`)
1. **Provider-Independent Canonical Schema:**
   - All client endpoints (`/api/v1/matches/{fixture}/arena`, `/api/v1/matches/{fixture}/prediction`, `/api/v1/rankings/*`) reference internal canonical PostgreSQL IDs (`sports`, `competitions`, `seasons`, `teams`, `fixtures`), never external provider IDs.
2. **Centralized Ingestion & Quota Protection:**
   - External API keys are never exposed to `apps/mobile` or `apps/web`.
   - When a licensed provider is enabled (`P04-005` / `P04-006`), background workers poll within configured rate budgets, record `source_revision` on `fixtures`, and trigger deterministic downstream settlement (`PredictionScoringEngine::settleFixture`).
3. **Separate Visual Rights (`ADR-008` / `P01-005`):**
   - Even when a sports data API returns image URLs for club crests or player headshots, Topkolik renders vector monogram badges and licensed brand typography (`#0B5D3B` / `#7CFF4D` / `#1F2933`) unless explicit visual asset rights are registered in the asset rights registry.
