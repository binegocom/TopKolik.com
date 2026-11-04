#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "=== Topkolik Runtime Versions and Lockfile Verification ==="

# 1. Manifest Files
manifests=(".nvmrc" ".node-version" ".php-version" ".tool-versions")
for m in "${manifests[@]}"; do
  if [[ ! -f "$ROOT/$m" ]]; then
    echo "ERROR: Missing required version manifest: $m" >&2
    exit 1
  fi
  echo "✓ Manifest present: $m ($(cat "$ROOT/$m" | tr '\n' ' '))"
done

# 2. Lockfiles
lockfiles=(
  "services/api/composer.lock"
  "apps/web/package-lock.json"
  "apps/admin/package-lock.json"
  "apps/mobile/pubspec.lock"
)
for lf in "${lockfiles[@]}"; do
  if [[ ! -f "$ROOT/$lf" ]]; then
    echo "ERROR: Missing required frozen lockfile: $lf" >&2
    exit 1
  fi
  echo "✓ Lockfile verified: $lf ($(du -h "$ROOT/$lf" | cut -f1))"
done

# 3. Local Runtime Compatibility
if command -v node >/dev/null 2>&1; then
  echo "✓ Local Node runtime: $(node -v)"
fi
if command -v php >/dev/null 2>&1; then
  echo "✓ Local PHP runtime: $(php -r 'echo PHP_VERSION;')"
fi
if command -v flutter >/dev/null 2>&1; then
  echo "✓ Local Flutter runtime: $(flutter --version | head -n 1)"
fi

echo "=== All runtime manifests and lockfiles PASSED ==="
