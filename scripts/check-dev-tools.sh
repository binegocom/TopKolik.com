#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/infrastructure/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo 'Missing infrastructure/.env. Run: python3 scripts/dev-env.py init' >&2
  exit 1
fi
compose=(docker compose --env-file "$ENV_FILE" -f "$ROOT/infrastructure/compose.dev.yaml")
wait_http() {
  local url="$1"
  local service="$2"
  local max_attempts=30
  for ((attempt=1; attempt<=max_attempts; attempt++)); do
    if curl --fail --silent --show-error --max-time 3 "$url" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  echo "Health check failed after ${max_attempts} attempts: ${service}" >&2
  "${compose[@]}" logs --tail 50 "$service" >&2 || true
  return 1
}
"${compose[@]}" --profile tools up -d mailpit
web_port="$(sed -n 's/^MAILPIT_WEB_PORT=//p' "$ENV_FILE")"
web_port="${web_port:-58025}"
wait_http "http://127.0.0.1:${web_port}/api/v1/info" mailpit
echo 'Healthy: Mailpit HTTP API.'
if [[ "${1:-}" == '--aws' ]]; then
  token="$(sed -n 's/^LOCALSTACK_AUTH_TOKEN=//p' "$ENV_FILE")"
  if [[ -z "$token" ]]; then
    echo 'LocalStack 2026 requires LOCALSTACK_AUTH_TOKEN. Not starting aws profile.' >&2
    exit 1
  fi
  "${compose[@]}" --profile aws up -d localstack
  aws_port="$(sed -n 's/^LOCALSTACK_PORT=//p' "$ENV_FILE")"
  aws_port="${aws_port:-54566}"
  wait_http "http://127.0.0.1:${aws_port}/_localstack/health" localstack
  echo 'Healthy: LocalStack gateway (S3 operations still require separate integration test).'
fi
