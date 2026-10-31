# Topkolik local infrastructure

**Status:** development-only infrastructure files. The Laravel API and clients have not yet been scaffolded. No local infrastructure can publish a story.

## macOS prerequisites

- Docker Desktop with Compose v2.
- Python 3 for generating local credentials.
- Docker daemon started, enough free storage for persistent named volumes.

## First setup (from repository root)

```bash
python3 scripts/dev-env.py init
bash scripts/check-dev-infra.sh
bash scripts/check-dev-tools.sh
```

The first command refuses to overwrite an existing `.env` and generates independent cryptographically random PostgreSQL and Redis passwords. The file is ignored by Git and permissioned `0600`.

## Local endpoints

- PostgreSQL TCP `127.0.0.1:55432` (database/user from `.env`)
- Redis TCP `127.0.0.1:56379` (authentication from `.env`)
- Mailpit UI `http://127.0.0.1:58025`, SMTP `127.0.0.1:51025`
- Optional LocalStack `http://127.0.0.1:54566`, profile `aws`, requires `LOCALSTACK_AUTH_TOKEN` in local `.env`.

Ports may be overridden in `.env`. All host-published ports bind **only to 127.0.0.1**. Containers communicate via `backend` bridge network using service DNS names (`postgres`, `redis`, `mailpit`, `localstack`).

## Docker commands

```bash
docker compose --env-file infrastructure/.env -f infrastructure/compose.dev.yaml config --quiet
docker compose --env-file infrastructure/.env -f infrastructure/compose.dev.yaml ps
docker compose --env-file infrastructure/.env -f infrastructure/compose.dev.yaml down
```

**Never use `down -v`** unless explicit authorization is given: it deletes named volume data. `down` alone retains data. PostgreSQL major-version upgrades need migration/backup planning.

## Optional S3 emulator

LocalStack 2026 requires a developer auth token even for local start; this is a vendor credential and must not be put in Git. Add it to `infrastructure/.env` and run:

```bash
bash scripts/check-dev-tools.sh --aws
```

A healthy LocalStack HTTP endpoint does **not** verify S3/R2 compatibility. That requires an actual create/read/delete object integration test with a configured storage client. Use Cloudflare R2 only after separate production credential, CORS, signed URL, private-media and rights testing.

## Security notes

- `.env` contains sensitive development passwords; never upload it or paste `docker compose config` output in public issues, as rendered Compose values include secrets.
- Compose passwords are injected as environment variables; use a dedicated secret manager for production, not this local configuration.
- No production data is permitted in local volumes or Mailpit.
- LocalStack is optional because its 2026 release requires an external credential.

## Acceptance tracking

P02-004 is **in progress** until Compose smoke tests run in an actual Docker environment for PostgreSQL, Redis, Mailpit and licensed LocalStack S3 operations. Offline YAML validation alone is insufficient.
