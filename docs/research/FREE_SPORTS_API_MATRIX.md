# Zero-Cost-First Sports Data API Evaluation Matrix — P01-003

## 1. Objective & Governance Scope
Before any paid commercial contract is approved (`P01-004` / `ADR-007`), Topkolik evaluates zero-cost, open-data, and free-tier sports data sources for Türkiye-focused football (`Süper Lig`, `1. Lig`, `Türkiye Kupası`, UEFA club competitions) and multi-sport expansion (`Basketball`, `Volleyball`, `Tennis`, `Formula 1`).

**Mandatory Governance Rule:**
- A free tier with personal/non-commercial license terms, scraping prohibitions, or a `100 requests/day` hard ceiling **cannot** be treated as a production-licensed commercial feed.
- Client devices (iOS, Android, Web browsers) must **never** call external sports providers directly. All external ingestion must flow through centralized Laravel worker adapters (`services/api`), normalized into PostgreSQL canonical tables, and served to clients from Redis/PostgreSQL caches.

---

## 2. 20-Criterion Provider Comparison Matrix

| # | Evaluation Criterion | API-Football (API-Sports v3 Free Tier) | football-data.org (v4 Free Tier) | TheSportsDB (v1 Free / Patreon Tier) | TFF (`tff.org`) / UEFA Public Web Pages | Open Data (`openfootball` / StatsBomb Open Data) |
|---|---|---|---|---|---|---|
| 1 | **Pricing Model & Free Tier** | Free plan (`$0/mo`, 100 req/day); Pro starts ~$19–$39/mo | Free plan (`$0/mo`, 10 req/min, limited competitions) | Free key (`123` / v1, 30 req/min); $3–$9/mo supporter | Public HTML websites (`$0`, no public commercial JSON API) | 100% open-source static datasets (CC0 / CC-BY-NC) |
| 2 | **Request Limits & Rate Windows** | **100 requests/day** across all endpoints; 10 req/min | 10 requests/minute | 30 requests/minute (v1 free tier) | Aggressive WAF / Cloudflare anti-bot throttling | Unlimited via Git / static hosting (no live API) |
| 3 | **Commercial Usage Rights** | Free tier is **development/testing only**; paid plan required for commercial apps | Free tier restricts commercial redistribution without tier upgrade | Metadata crowd-sourced; commercial media/logo rights not indemnified | **Strictly prohibited**; copyright & database rights reserved by TFF/UEFA | `openfootball` is Public Domain (Unlicense/CC0); StatsBomb is non-commercial research |
| 4 | **Redistribution & Caching Rights** | Caching allowed for app display on paid plans; raw feed reselling prohibited | Caching allowed within licensed app; raw API proxying prohibited | Caching permitted; user-contributed artwork requires independent verification | Scraping/redistribution prohibited without written federation license | `openfootball` allows unrestricted storage/redistribution of historical fixtures |
| 5 | **AI Derivative Editorial Rights** | Factual match data may ground summaries on paid tiers; no contractual indemnity on free tier | Factual scores/fixtures only; no contractual AI journalism warranty | Not contractually warranted for automated journalism | Unlicensed scraping for AI news generation creates severe legal exposure | `openfootball` factual schedules/results usable; no live match facts |
| 6 | **Attribution Requirements** | None on paid tiers | Attribution recommended on free tier | Community attribution encouraged | Official media accreditation / license required | StatsBomb requires logo/source attribution (`CC-BY-NC-SA 4.0`) |
| 7 | **Türkiye Süper Lig & 1. Lig Coverage** | **Yes** (`League ID 203` Süper Lig, `204` 1. Lig, `206` Türkiye Kupası) | **No** in free tier (`TR1` requires paid plan) | **Partial** (Süper Lig teams/schedules present; lower divisions incomplete) | **Authoritative** for domestic fixtures/referees, but no licensed public API | Historical/static schedules only; no real-time domestic feed |
| 8 | **UEFA Champions / Europa / Conference League** | **Yes** (qualifiers, league phase, knockouts) | **Yes** (`CL` included in free tier; `EL`/`ECL` paid) | **Yes** (schedules and basic scores) | Official UEFA match center (HTML/unlicensed endpoints) | Historical seasonal archives only |
| 9 | **Major European Leagues** | **Yes** (EPL, LaLiga, Serie A, Bundesliga, Ligue 1) | **Yes** (top 5 European leagues in free tier) | **Yes** (schedules and basic results) | N/A | **Yes** (`openfootball` covers major European historical/seasonal fixtures) |
| 10 | **Basketball, Volleyball, Tennis, Motorsport** | Separate API-Sports endpoints (each capped at 100 req/day on free tier) | **Football only** | Partial basketball/motorsport metadata; weak live events | Federation-specific sites only | Football only |
| 11 | **Fixture Schedules & Kickoff Precision** | UTC timestamps, venue, referee, status codes (`NS`, `1H`, `HT`, `2H`, `FT`) | UTC timestamps, matchday, stage, status (`SCHEDULED`, `IN_PLAY`, `FINISHED`) | Basic date/time strings; occasional timezone inconsistencies | Official TFF fixture announcements | Static date/matchday records; delayed kick-off updates |
| 12 | **Live Score Latency** | ~15–60s polling latency (paid); unusable for all-day live polling on 100 req/day free tier | ~60–120s update cadence on free tier | ~2–5 min community/crowdsourced delay | Real-time on web, but scraping is brittle and prohibited | **None** (batch post-match git commits) |
| 13 | **Match Events (Goals, Cards, Substitutions, VAR)** | **Rich** (minute, extra time, player ID, assist, card type, VAR decision) | Goals/cards/substitutions available only on higher tiers for most leagues | Basic goal scorers in text fields; no structured VAR/timeline | HTML match sheets only | Historical event logs (StatsBomb selected competitions only) |
| 14 | **Lineups, Formations & Bench** | **Yes** (starting XI, substitutes, grid coordinates, coach, shirt numbers) | Paid tiers only for most competitions | Text/partial lineups on major matches | Published 60 min pre-match on HTML pages | StatsBomb historical JSON only |
| 15 | **Standings & Tie-Break Accuracy** | **Yes** (points, GD, form, home/away splits, H2H rank) | **Yes** (total, home, away standings for included leagues) | Basic league table endpoint | Official TFF table (including disciplinary point deductions) | Computed from static results |
| 16 | **Team & Player Statistics** | **Yes** (shots, possession, passes, fouls, ratings) | Limited on free tier | Minimal | Minimal on public HTML | High-granularity xG/pass vectors (StatsBomb historical only) |
| 17 | **Club Logos, Player Photos & Crest Rights** | CDN URLs returned, but **intellectual property remains with clubs/leagues** (`ADR-008`) | Crest URLs returned; no commercial trademark license granted | User-uploaded badges/cutouts; **no trademark clearance** | Protected federation/club trademarks | Text-only (no logos/photos) |
| 18 | **Correction / VAR / Postponement Handling** | Status transitions (`PST`, `CANC`, `ABD`) and score revisions via polling | Status updates (`POSTPONED`, `CANCELLED`); no push webhook on free tier | Manual community edits; no revision counter | Official TFF disciplinary/postponement bulletins | Manual repository commits |
| 19 | **Authentication, Uptime & SLA** | Header `x-apisports-key`; no SLA on free tier | Header `X-Auth-Token`; no SLA on free tier | URL key parameter; no SLA | No API contract; HTML DOM changes break scrapers without notice | GitHub/raw static files; 99.9% static availability |
| 20 | **Vendor Lock-In Risk & Mitigation** | Numeric provider IDs; mitigated by Topkolik `provider_entity_mappings` table | Numeric/code IDs; mitigated by canonical adapter layer | String/numeric IDs; mitigated by canonical adapter layer | High legal & technical fragility (rejected) | Clean canonical seed format for development/testing |

---

## 3. Quota & Central Caching Math (Why Free Tiers Cannot Serve Uncached Live Traffic)

### Scenario: Matchday Peak Load (Süper Lig Derby + 4 Concurrent Fixtures)
1. **Direct Client Calls (Forbidden Anti-Pattern):**
   - `10,000` active fans refreshing a match screen every `15 seconds` = `40,000 requests/minute` (`2,400,000 requests/hour`).
   - Any free tier (`100 req/day` or `10 req/min`) fails within `< 1 second`.
2. **Centralized Backend Polling (`services/api` Worker -> Redis/PostgreSQL -> Clients):**
   - Even with centralized backend polling where **zero** client requests hit the external provider directly:
   - Polling 1 live batch endpoint every `30 seconds` during a `120-minute` match window consumes:
     $$\frac{120 \text{ min}}{0.5 \text{ min/req}} = 240 \text{ requests}$$
   - Fetching pre-match lineups, match events/timelines, and post-match statistics for `9` Süper Lig fixtures in a matchweek (`4` calls per fixture every `2 minutes` during live play) requires:
     $$9 \text{ fixtures} \times 60 \text{ polls} \times 2 \text{ endpoints} = 1,080 \text{ requests/matchweek}$$
   - **Conclusion:** A `100 requests/day` free tier (`API-Football Free`) can only support **development contract verification and low-frequency fixture schedule sync** (e.g., 4 schedule syncs/day + 1 standings sync/day = `5 req/day`), **not** live match-by-minute ingestion or commercial production publishing.

---

## 4. Recommended Zero-Cost-First Phased Strategy

1. **Local & CI Development (`Zero External Quota`):**
   - Use deterministic contract fixtures and isolated disposable PostgreSQL test factories (`SocialMatchArenasPredictionsAndRankingsTest`). Never call external networks in automated CI tests.
2. **Staging / Pre-Commercial Adapter Verification (`$0/month`):**
   - Use `API-Football` Free Tier (`100 req/day`) and `football-data.org` Free Tier (`10 req/min`) strictly inside a non-commercial staging environment with a **hard Redis token-bucket circuit breaker** (`max 80 req/day`, polling schedules at most every `6 hours`, and caching responses in PostgreSQL/Redis).
3. **Production Go-Live Gate (`P01-004` / `ADR-007` & `ADR-008`):**
   - Public commercial launch with live Türkiye `Süper Lig`, `1. Lig`, `Türkiye Kupası`, and UEFA live timelines remains gated on owner sign-off of a commercial sports-data license (`P01-004`) and separate club crest/photo clearance (`P01-005`).
   - HTML scraping of `tff.org`, broadcaster feeds, or unlicensed portals is **strictly prohibited** across all environments.
