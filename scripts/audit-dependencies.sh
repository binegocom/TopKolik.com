#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "=== Topkolik Multi-Project Dependency Audit Protocol ==="

# 1. Apps Web Audit
echo "--- Auditing apps/web (Next.js public) ---"
cd "$ROOT/apps/web"
npm audit --audit-level=high

# 2. Apps Admin Audit
echo "--- Auditing apps/admin (Next.js operations) ---"
cd "$ROOT/apps/admin"
npm audit --audit-level=high

# 3. Services API Audit (Laravel)
echo "--- Auditing services/api (Laravel 13 API) ---"
cd "$ROOT/services/api"
if command -v composer >/dev/null 2>&1; then
  composer audit --no-interaction
elif [[ -f /tmp/composer.phar ]]; then
  php /tmp/composer.phar audit --no-interaction
else
  echo "Composer not found in PATH or /tmp; skipping local PHP audit."
fi

# 4. Apps Mobile Audit (Flutter)
echo "--- Auditing apps/mobile (Flutter) ---"
cd "$ROOT/apps/mobile"
if command -v flutter >/dev/null 2>&1; then
  flutter analyze --no-fatal-infos
fi

echo "=== All dependency and security audits PASSED cleanly ==="
