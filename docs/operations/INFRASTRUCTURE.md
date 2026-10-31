# Deployment, monitoring and disaster recovery

Environments: `local`, `staging`, `production` with isolated secrets/database/buckets and explicit approvals. Compose local PostgreSQL/Redis/API/web/admin/worker/scheduler; credentials in env/secret store, not committed. Cloudflare R2 for owned media with private pending area, public only after approval and rights checks. Next.js on compatible SSR hosting; Laravel API/queue on PHP-capable containers. TLS, WAF and conservative cache headers.

Observability: request latency/error budget, provider data age, queue lag/dead letters, publication count, hallucination/exception reports, push failure, ad fraud indicators, storage transfer, spend and billing mismatches. Alerts should route to assigned owners.

Backups and restoration: encrypted automated PostgreSQL and R2 object versioning/retention policy, recovery drills with measured RPO/RTO after approval. Roll-forward and reversible migration strategies; no drop/truncate/reset in production without written authorization. Maintain rollback runbook and high-risk kill switches.
