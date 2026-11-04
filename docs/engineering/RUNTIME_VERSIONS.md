# Topkolik — Language Runtimes & Dependency Versions Protocol

## 1. Overview & Reproducibility Guarantees

Topkolik enforces deterministic, reproducible builds across development, CI, and production environments by pinning language runtime baselines and locking transitive dependency trees.

### Pinned Baseline Runtimes

| Component | Pinned Version | Constraint Manifests | Verification Command |
|---|---|---|---|
| **Node.js** | `20.18.0 LTS` (supports Node 20.x / 22.x) | `.nvmrc`, `.node-version`, `.tool-versions` | `node -v` |
| **PHP** | `8.4.1` (supports PHP 8.4.x) | `.php-version`, `.tool-versions`, `services/api/composer.json` | `php -v` |
| **Flutter** | `3.47.x` (stable) | `.tool-versions`, `apps/mobile/pubspec.yaml` | `flutter --version` |
| **PostgreSQL** | `16-alpine` | `infrastructure/compose.dev.yaml` | `pg_isready` |
| **Redis** | `7-alpine` | `infrastructure/compose.dev.yaml` | `redis-cli ping` |

---

## 2. Frozen Lockfiles & Install Policies

All sub-projects maintain strict lockfiles checked into version control. Package additions or updates must use non-mutating install commands in automated pipelines:

1. **Next.js Web (`apps/web`)**:
   - Manifest: `package.json`
   - Lockfile: `package-lock.json`
   - CI Install: `npm ci`
2. **Next.js Operations Admin (`apps/admin`)**:
   - Manifest: `package.json`
   - Lockfile: `package-lock.json`
   - CI Install: `npm ci`
3. **Laravel API (`services/api`)**:
   - Manifest: `composer.json`
   - Lockfile: `composer.lock`
   - CI Install: `composer install --prefer-dist --no-interaction --no-progress`
4. **Flutter Mobile (`apps/mobile`)**:
   - Manifest: `pubspec.yaml`
   - Lockfile: `pubspec.lock`
   - CI Install: `flutter pub get`

---

## 3. Runtime Verification Script

Automated local and CI verification of manifests and lockfiles is executed via:
```bash
bash scripts/check-runtime-versions.sh
```
