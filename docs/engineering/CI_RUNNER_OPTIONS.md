# Topkolik — Continuous Integration & Runner Infrastructure Protocol

## 1. Overview and Security Boundaries

Topkolik's monorepo uses GitHub Actions as its primary CI orchestrator, structured into partitioned, least-privilege workflows. To prevent supply chain and credential leakage attacks, all automated PR runs operate under strict permissions boundaries:

- **Token Least Privilege**: All workflows enforce `permissions: { contents: read }`. Writing to repository contents or triggering external deployments requires protected branch merges or explicit manual dispatch with environment approvals.
- **Pull Request Secrets Isolation**: Untrusted fork pull requests and third-party contributions have zero access to production secrets or deployment tokens. Tests use ephemeral in-memory or containerized mocks and local PostgreSQL/Redis instances with dynamically generated one-time credentials (`scripts/dev-env.py`).
- **Dependency Sandboxing**: CI runs enforce frozen lockfiles (`npm ci`, `composer install --prefer-dist`, `flutter pub get`) followed by mandatory security audits (`composer audit`, `npm audit --audit-level=high`).

---

## 2. CI Workflow Matrix

| Workflow File | Triggers | Environments | Purpose |
|---|---|---|---|
| `.github/workflows/editorial-guard.yml` | All PRs & `main` pushes | `ubuntu-24.04` (PHP 8.4) | Verifies editorial truth guard, claim contracts, and blueprint metadata consistency (`validate_blueprint.py`, `status.py check`). |
| `.github/workflows/laravel-api.yml` | Changes in `services/api/**`, scripts, roadmap | `ubuntu-24.04` (PHP 8.4, Docker) | Boots real PostgreSQL 16 & Redis 7 containers, runs migrations, verifies 9 concurrency race conditions, reader verification email SMTP smoke, and HTTP contracts. |
| `.github/workflows/dev-infrastructure.yml` | Changes in `infrastructure/**`, scripts | `ubuntu-24.04` (Python 3.12, Docker) | Validates compose schema, port isolation (loopback only), named volumes safety, and health checks. |
| `.github/workflows/clients-ci.yml` | Changes in `apps/**`, scripts | `ubuntu-24.04` (Node 20, Flutter stable, PHP 8.4) | Verifies Next.js 15 web & admin compilation, Flutter 3 mobile analysis & test suites, and multi-project dependency security audits. |

---

## 3. Local and Self-Hosted Runner Options

To accelerate developer cycles and enable offline testing or air-gapped CI execution, developers and automated self-hosted runners can execute the exact identical verification steps locally without depending on GitHub cloud runners.

### 3.1 Local Reproducibility Command Matrix

```bash
# 1. Blueprint & Governance Audit
python3 scripts/validate_blueprint.py
python3 scripts/status.py check

# 2. Editorial Truth & Rights Guard
bash scripts/test-editorial-guard.sh

# 3. Security & Dependency Audit
bash scripts/audit-dependencies.sh

# 4. Public Web Client (Next.js 15)
cd apps/web && npm ci && npm run build

# 5. Operations Console (Next.js 15)
cd apps/admin && npm ci && npm run build

# 6. Mobile Application (Flutter 3)
cd apps/mobile && flutter pub get && flutter analyze --no-fatal-infos && flutter test

# 7. Backend API (Laravel 13 with Real PostgreSQL & Redis)
python3 scripts/dev-env.py init
bash scripts/check-dev-infra.sh
cd services/api && php artisan test
```

### 3.2 Self-Hosted Runner Hardening Guidelines

When self-hosted runners (e.g. Linux bare-metal or macOS Apple Silicon builders for iOS TestFlight CI) are attached to the repository:
1. **Runner Isolation**: Each job runs inside an ephemeral, non-root runner container or ephemeral virtual machine that terminates and wipes state upon job completion.
2. **Docker Socket Protection**: The host Docker socket (`/var/run/docker.sock`) must never be mounted directly into untrusted PR containers.
3. **Environment Segregation**: Self-hosted runners dedicated to production signing and deployment must be assigned to the `production` GitHub environment with mandatory reviewer sign-off.
