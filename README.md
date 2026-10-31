# Topkolik — Autonomous Sports News Platform

> **Not a working public product.** The repository contains a tested Laravel 13 API foundation, reader-only Sanctum mobile authentication, reader profile/locale/favorites API, and an isolated editorial policy guard. Reader password reset is implemented and CI-tested on the backend (with a trusted frontend URL prerequisite); invite-only inactive staff enrollment, mandatory MFA and staff authorization, customer web/browser session integration, sports-data providers, AI publishing, public web/mobile apps, advertising and production deployment are **not** implemented or verified.

**Mission:** Build an original, legally sourced, AI-assisted, largely autonomous sports-news publication for web, iOS and Android, with particular emphasis on Turkish sports competitions across all accessible disciplines.

**Publishing contract:** No verified facts = no publication. Automated news is allowed only if source rights, event integrity, provenance, claim validation, media rights and quality gates pass. Exceptions are quarantined for editorial review; no automatic invented facts or generated news photographs.

## Platform decisions

| Concern | Planned solution |
|---|---|
| Mobile | Flutter / Dart; iOS HIG and Android Material 3 adaptive navigation |
| Consumer web | Next.js App Router, React, TypeScript, server-rendered news pages |
| Admin web | Separate Next.js App Router application, private, roles + MFA |
| API and automation | Laravel 13 modular monolith; PHP 8.4 CI; Composer lock committed |
| Main database | PostgreSQL; durable relational constraints and provenance |
| Cache & jobs | Redis; Laravel queues, Horizon and Scheduler |
| Storage | S3-compatible object storage; Cloudflare R2 preferred, subject to tests |
| CDN/WAF/DNS | Cloudflare; does **not** imply deploying PHP to Cloudflare Workers |
| Notifications | FCM and APNs with subscriptions and explicit consent |
| Analytics | Event-based first-party analytics, privacy-friendly aggregation |
| Ads | Direct-sold campaigns and policy-compliant network mediation, separate ledgers |
| Deployment | Containerized API/worker/scheduler on a PHP-capable host; Next.js web/admin on compatible hosting |

**User authentication:** Public reading and live scores work without login. Reader accounts are optional for synchronized preferences, favorites and saved content. Admin/editor/staff login is mandatory, separately scoped and protected by MFA.

**Languages:** UI localization decision is currently `de → fr → it → en`, with English fallback, per owner preferences. Editorial output may cover Turkey in Turkish; Turkish **UI localization is not approved** and remains a blocking product decision for the Turkey-first release. Do not silently add `tr` to UI locales.

## Getting started

1. Read [docs/product/PRODUCT_REQUIREMENTS.md](docs/product/PRODUCT_REQUIREMENTS.md).
2. Read [docs/architecture/ARCHITECTURE.md](docs/architecture/ARCHITECTURE.md) and [docs/architecture/DATA_MODEL.md](docs/architecture/DATA_MODEL.md).
3. Read [docs/product/ROADMAP.md](docs/product/ROADMAP.md) and [PROJECT_STATUS.md](PROJECT_STATUS.md).
4. Inspect `.github/ISSUE_TEMPLATE/` for task acceptance and evidence fields.
5. Implement milestones strictly in dependency order; do not mark a milestone complete without tests and evidence.

## Status source of truth

- `ROADMAP.json` — machine-readable tasks; each includes ID, status, dependencies and acceptance criteria.
- `PROJECT_STATUS.md` — readable status and verification ledger.
- `docs/product/ROADMAP.md` — phase-level execution plan.
- `AGENTS.md` — implementation standards for coding agents.

Possible task statuses: `todo`, `in_progress`, `blocked`, `in_review`, `done`. A `done` task must contain a PR/commit reference or reproducible test evidence. Documentation-only tasks may reference a validated local file. Never infer completion from an assistant message alone.

## Brand

The approved brand presentation reference is in the conversation's downloadable planning archive; a licensed, production-ready icon master has not yet been committed to the repository. The planned `assets/brand/topkolik-reference.png` path is pending. App Store/Play Store icons must be exported separately with tested size, safe area and accessibility variants. Primary colors: `#0B5D3B`, `#7CFF4D`, `#1F2933`, `#FFFFFF`. See [design system](docs/design/DESIGN_SYSTEM.md).

## Non-negotiable rules

- No production scraping/copying of Maçkolik, Sofascore, broadcaster sites or other third-party services without **written licensed rights**.
- No unlicensed club marks, player photos, news images, quotes or redistributable match feeds.
- No unsupported model-generated scores, injury claims, transfer confirmations, quotes or allegations.
- No real-money wagering functionality or gambling-ad integrations without separate legal/policy approval.
- No destructive migrations or production data deletion without explicit written owner authorization.
- No anonymous user-generated discussion module in first release; future UGC requires moderation, reporting and blocking.

## Useful files

- [Sports data policy](docs/compliance/LICENSING_AND_EDITORIAL_POLICY.md)
- [Identity and access](docs/security/IDENTITY_AND_ACCESS.md)
- [Every consumer and admin screen](docs/product/SCREENS_AND_WORKFLOWS.md)
- [API endpoint blueprint](docs/architecture/API_CONTRACT.md)
- [Open product decisions](docs/product/DECISION_REGISTER.md)
- [Threat model](docs/security/THREAT_MODEL.md)
- [Incident runbooks](docs/operations/INCIDENT_RUNBOOKS.md)
- [Automated publishing pipeline](docs/architecture/AI_NEWS_PIPELINE.md)
- [Ads, finance and support](docs/business/OPERATIONS_AND_REVENUE.md)
- [Testing and release gates](docs/qa/TEST_AND_RELEASE_PLAN.md)
- [Infrastructure and monitoring](docs/operations/INFRASTRUCTURE.md)
- [Official sources reviewed](docs/research/OFFICIAL_REFERENCES.md)
## GitHub execution

- [Phase issues, PR and task status protocol](docs/product/GITHUB_TRACKING.md)
