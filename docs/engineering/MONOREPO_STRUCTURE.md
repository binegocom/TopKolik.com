# Topkolik — Monorepo Architecture, Branch Protection & Issue Tracking

## 1. Monorepo Directory Architecture

Topkolik organizes all clients, services, infrastructure, and governance documentation within a unified GitHub monorepo (`https://github.com/binegocom/TopKolik.com`):

```
TopKolik.com/
├── apps/
│   ├── mobile/         # Flutter 3.47 iOS & Android cross-platform client
│   ├── web/            # Next.js 15 App Router public supporter web portal
│   └── admin/          # Next.js 15 App Router staff operations console (MFA step-up)
├── services/
│   └── api/            # Laravel 13 PHP 8.4 modular monolith sports & social engine
├── infrastructure/     # Docker Compose dev environment (PostgreSQL 16, Redis 7, Mailpit, S3)
├── docs/               # Auditable system documentation
│   ├── api/            # OpenAPI 3.1 specification (openapi.yaml)
│   ├── architecture/   # Architecture, data model, AI pipeline, contracts
│   ├── engineering/    # Monorepo, runtime, CI runner, and release protocols
│   ├── product/        # PRD, screens, decision register (ADRs), roadmap
│   └── security/       # Threat models, access control, audit logs
├── scripts/            # Automated verification, audit, and dev environment harnesses
├── tests/              # Cross-cutting dev infrastructure test suites
├── ROADMAP.json        # Machine-readable task tracking manifest (154 tasks)
└── PROJECT_STATUS.md   # Auto-rendered roadmap progress & verification log
```

---

## 2. Branch Protection and Security Policies

Topkolik enforces branch protection on `main` to maintain zero-defect software discipline:

1. **Pull Request Gate**: Direct pushes to `main` are strictly forbidden. All modifications must be submitted via feature branches (`feat/*`, `fix/*`, `test/*`) and merged via pull requests.
2. **Mandatory CI Status Checks**: A pull request can only be merged when all required GitHub Actions workflows pass:
   - `Editorial guard checks/PHP editorial guard and roadmap integrity`
   - `Laravel API checks/api` (PostgreSQL 16, Redis 7, migrations, 9 concurrency race tests, SMTP verification)
   - `Clients and Dependency Audit` (Next.js web build, Next.js admin build, Flutter mobile tests/analyze, multi-project dependency security audit)
3. **Squash Merges**: Clean linear history is enforced via squash merges with structured commit messages.
4. **Secrets Boundary**: Untrusted forks and unapproved pull requests have zero access to production credentials or cloud tokens.

---

## 3. Status Tracking & Issue Governance

1. **Roadmap Task IDs**: Every engineering unit of work corresponds 1:1 with an ID in `ROADMAP.json` (e.g. `P02-001`, `P02-004`).
2. **Evidence-Bound Completion**: No task may be marked `done` without recorded verification commands, exact results, and commit/PR links. Tasks outside Phase 00 cannot be certified solely by file presence.
3. **Structured Issue & PR Templates**:
   - Issue template: `.github/ISSUE_TEMPLATE/implementation.yml`
   - PR template: `.github/pull_request_template.md`
   - Automatic blueprint validator: `scripts/validate_blueprint.py`
