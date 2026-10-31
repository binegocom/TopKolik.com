#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/infrastructure/.env"
COMPOSE_FILE="$ROOT/infrastructure/compose.dev.yaml"
if [[ ! -f "$ENV_FILE" ]]; then
  echo 'Missing infrastructure/.env. Run: python3 scripts/dev-env.py init' >&2
  exit 1
fi
if ! command -v docker >/dev/null 2>&1; then
  echo 'Docker CLI is required; install Docker Desktop on macOS.' >&2
  exit 1
fi
compose=(docker compose --env-file "$ENV_FILE" -f "$COMPOSE_FILE")
"${compose[@]}" config --quiet
"${compose[@]}" up -d --wait postgres redis
"${compose[@]}" exec -T postgres sh -ec 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
"${compose[@]}" exec -T redis sh -ec 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping | grep -qx PONG'
echo 'Healthy: PostgreSQL and Redis. No volumes were reset.'
