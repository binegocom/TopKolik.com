#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT=18888
TEMP="$(mktemp -d)"
SERVER_PID=""

cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  rm -rf "$TEMP"
}
trap cleanup EXIT

cd "$ROOT/services/api"
php artisan serve --host=127.0.0.1 --port="$PORT" >"$TEMP/serve.log" 2>&1 &
SERVER_PID="$!"

base="http://127.0.0.1:$PORT"
ready=0
for ((attempt=1; attempt<=30; attempt++)); do
  if curl --fail --silent --show-error --max-time 2 "$base/health" >"$TEMP/health" 2>"$TEMP/error"; then
    ready=1
    break
  fi
  sleep 1
done
if [[ "$ready" != 1 ]]; then
  cat "$TEMP/serve.log" >&2
  echo 'Laravel HTTP liveness failed.' >&2
  exit 1
fi

curl --fail --silent --show-error --dump-header "$TEMP/headers" "$base/ready" >"$TEMP/ready.json"
curl --fail --silent --show-error "$base/api/v1/status" >"$TEMP/status.json"
python3 - "$TEMP/ready.json" "$TEMP/status.json" <<'PY'
import json
import sys
from pathlib import Path
ready = json.loads(Path(sys.argv[1]).read_text())
status = json.loads(Path(sys.argv[2]).read_text())
assert ready == {"status": "ready"}, ready
assert status == {"service": "topkolik-api", "status": "operational"}, status
PY
grep -Eqi '^X-Request-ID: [0-9a-f-]{36}' "$TEMP/headers"
echo 'PASS: HTTP /health, /ready, /api/v1/status and request correlation header.'
