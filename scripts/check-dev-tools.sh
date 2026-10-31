#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/infrastructure/.env"
if [[ ! -f "$ENV_FILE" ]]; then
  echo 'Missing infrastructure/.env. Run: python3 scripts/dev-env.py init' >&2
  exit 1
fi
compose=(docker compose --env-file "$ENV_FILE" -f "$ROOT/infrastructure/compose.dev.yaml")
"${compose[@]}" --profile tools up -d mailpit
web_port="$(sed -n 's/^MAILPIT_WEB_PORT=//p' "$ENV_FILE")"
web_port="${web_port:-58025}"
curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused "http://127.0.0.1:${web_port}/api/v1/info" >/dev/null
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
  curl --fail --silent --show-error --retry 20 --retry-delay 2 --retry-connrefused "http://127.0.0.1:${aws_port}/_localstack/health" >/dev/null
  echo 'Healthy: LocalStack gateway (S3 operations still require separate integration test).'
fi
