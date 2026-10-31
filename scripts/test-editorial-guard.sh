#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
find "$ROOT/services/api/app/Domain/Editorial" "$ROOT/services/api/tests/Unit" \
  -type f -name '*.php' -print0 | xargs -0 -n 1 php -l
php "$ROOT/services/api/tests/Unit/PublicationGuardTest.php"
