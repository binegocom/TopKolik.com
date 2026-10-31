# Technical architecture baseline (P00-002)

## Architecture choice
Modular Laravel monolith + isolated worker processes and queues; Flutter client, separate SEO public Next.js site and private Next.js staff/admin. Shared versioned REST contract, PostgreSQL single source of truth, Redis for cache/queues. No premature microservices.

## Repository targets
- `apps/mobile` Flutter iOS/Android.
- `apps/web` Next.js consumer experience.
- `apps/admin` Next.js admin/editor/ads/finance/support.
- `services/api` Laravel app and modules.
- `infrastructure/` Docker, infrastructure as code, deployment manifests.
- `docs/`, `assets/`, `scripts/` architecture, brand and project governance.

These directories are **planned**, not currently implemented. Do not create empty runtime projects and mark them completed.

## Laravel modules
Identity, Authorization, ReaderPreferences, SportsCatalog, Fixtures, Statistics, Providers, Ingestion, Editorial, AI, Media, Publishing, Notifications, Search, Advertising, Billing, Accounting, Support, Audit, Analytics, Operations. Controllers are thin; use FormRequest, Policies, service/actions, repositories only where needed, domain events and idempotent jobs.

## Ingestion and publication
Provider webhooks/pollers -> signed/validated provider adapters -> raw event audit ledger -> normalized sports model -> event lifecycle/versioning -> editorial candidate detection -> claim extraction -> independent evidence validation -> article and cover rendering -> editorial policy gate -> DB transaction + outbox -> website publication + push + search indexing hooks. Replay/backfill must be idempotent.

## Queues and consistency
Separate queues by priority: `ingest-critical`, `normalize`, `editorial`, `media`, `publish`, `notifications`, `maintenance`. Implement bounded retries/exponential backoff, poison handling, dead-letter inspection, provider rate budgets and per-event unique locks. Use DB outbox for publish/notification transitions.

## Database boundaries
PostgreSQL transactionally owns article states, provenance, team/fixture relations, auth/role scopes, ad ledgers and billing. Redis is never canonical source for balances, permission state or published truth. Row-level, scoped authorization in every query; no cross-tenant/organization leakage in financial or advertiser data.

## Security
Public read API uses rate limits and cache safety. Reader tokens have least privilege. Staff MFA and separated cookie/token scopes, strict CSRF/CORS, audit trail, server-side policies, validation of all IDs and uploads, antivirus/image metadata stripping, encrypted credentials, webhook signature checks, secrets out of git.

## Infrastructure
CDN/WAF: Cloudflare; object media: R2; API/worker/scheduler: dedicated PHP-capable containers; Next.js on compatible runtimes. Backup/restore tests and incident runbooks before production.

## Open architecture choices
Sports provider coverage/licensing, regional data residency, push vendor production configuration, media render stack, observability platform, deployment budget, API version and retention policies. Document approvals as ADRs.
