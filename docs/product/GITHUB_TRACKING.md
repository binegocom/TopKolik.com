# GitHub execution and tracking

## Repository
- Main repository: https://github.com/binegocom/TopKolik.com
- Default branch: `main`
- Planning branch: `docs/topkolik-master-blueprint`
- Planning PR: https://github.com/binegocom/TopKolik.com/pull/1
- **Visibility:** PUBLIC (verified 2026-10-31); do not upload secrets, private contracts or credentials.

## Phase issues

| Phase | GitHub issue | Scope |
|---|---|---|
| P00 | [#2](https://github.com/binegocom/TopKolik.com/issues/2) | Project blueprint and governance |
| P01 | [#3](https://github.com/binegocom/TopKolik.com/issues/3) | Rights, provider discovery and legal foundations |
| P02 | [#4](https://github.com/binegocom/TopKolik.com/issues/4) | Repository and engineering foundation |
| P03 | [#5](https://github.com/binegocom/TopKolik.com/issues/5) | Authentication, roles and privacy |
| P04 | [#6](https://github.com/binegocom/TopKolik.com/issues/6) | Sports catalog and licensed ingestion |
| P05 | [#7](https://github.com/binegocom/TopKolik.com/issues/7) | Match center, discovery and search API |
| P06 | [#8](https://github.com/binegocom/TopKolik.com/issues/8) | Autonomous editorial and fact validation |
| P07 | [#9](https://github.com/binegocom/TopKolik.com/issues/9) | Automated media production |
| P08 | [#10](https://github.com/binegocom/TopKolik.com/issues/10) | Consumer web (SEO-first) |
| P09 | [#11](https://github.com/binegocom/TopKolik.com/issues/11) | Flutter mobile iOS and Android |
| P10 | [#12](https://github.com/binegocom/TopKolik.com/issues/12) | Newsroom admin and staff workflows |
| P11 | [#13](https://github.com/binegocom/TopKolik.com/issues/13) | Ads sales, serving and reporting |
| P12 | [#14](https://github.com/binegocom/TopKolik.com/issues/14) | Billing, accounting and finance |
| P13 | [#15](https://github.com/binegocom/TopKolik.com/issues/15) | Support, personalization and analytics |
| P14 | [#16](https://github.com/binegocom/TopKolik.com/issues/16) | Security, performance, accessibility and compliance |
| P15 | [#17](https://github.com/binegocom/TopKolik.com/issues/17) | Release and operation |

## Status protocol
1. Read `AGENTS.md`, `ROADMAP.json` and `PROJECT_STATUS.md` at the **latest merged revision**.
2. Inspect real code and open PRs; never assume the planning PR or a checklist item is merged or deployed.
3. Work on a feature branch; cite exact roadmap task IDs and source evidence.
4. Implement minimal safe changes; run reproducible local tests, capture command/result/commit.
5. Update `ROADMAP.json` evidence/status and mirror the phase issue; use `scripts/status.py check` and `scripts/validate_blueprint.py`.
6. Submit a reviewable PR; do not mark deployment or launch complete without actual production verification.

**Source of truth:** `ROADMAP.json`. Issues provide navigation, not independent proof of completion. A checked task can mean documentation only; status interpretation must follow the task's acceptance criteria.

## Known gaps
- The approved brand reference PNG remains in the separate downloadable planning package, not committed to the repository. App icon and vector exports pending.
- No functional app/API/automation was developed in this planning PR.
- Provider contracts, publisher/legal entity, image rights, deployment, financial services and launch readiness are unresolved.
