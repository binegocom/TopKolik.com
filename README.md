# Topkolik — Autonomous Sports News Platform

> Planning and architecture phase. No application, API, mobile/web UI, production deployment or sports data provider is implemented yet.

Topkolik is a Türkiye-focused sports news platform for web, iOS and Android, powered by licensed sports data, evidence-checked AI editorial workflows, automated publishing, and staff/advertising/finance/support operations.

## Planned stack
- Flutter + Dart (iOS / Android)
- Next.js + React + TypeScript (consumer web and admin)
- Laravel + PHP (modular API, ingestion, editorial jobs)
- PostgreSQL + Redis (data / cache / queues)
- Cloudflare R2, CDN/WAF, FCM/APNs

## Non-negotiable publishing rules
- No unauthorized scraping or republication of third-party sports data, imagery, logos or articles.
- No auto-publishing claims without verifiable evidence or reproduction rights.
- Failed or conflicting data is quarantined, not published.
- All admin and publication privileges are server-enforced and audited.

Project scope, status, implementation plan and design system will be added on a dedicated planning branch and reviewed via pull request.
