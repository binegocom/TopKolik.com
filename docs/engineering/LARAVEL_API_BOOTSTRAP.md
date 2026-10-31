# Laravel 13 API foundation — implementation scope

The project retains `services/api/app/Domain/Editorial` and its existing evidence-based safety guard. The independent Laravel skeleton is created from the official `laravel/laravel` 13.x Composer project, with a committed `composer.lock`. The initial non-destructive bootstrap was executed on a dedicated feature branch and the Composer dependency lock is committed. The one-time scaffolding workflow and duplicate source templates are intentionally removed after generation; do not reinitialize an existing API. The original editorial domain remains intact.

## Current contract

- `GET /health`: framework liveness, does not probe data dependencies.
- `GET /ready`: checks PostgreSQL and Redis using backend connections; responds 200 when both are healthy and 503 with generic JSON when unavailable.
- `GET /api/v1/status`: versioned public API information; no fake fixtures, league data or user records.
- Requests carry a generated `X-Request-ID` response header; logs receive the same correlation ID.
- `APP_DEBUG=false` by default; do not return exception details or secrets to clients.

## Local macOS setup

```bash
python3 scripts/dev-env.py init
bash scripts/check-dev-infra.sh
python3 scripts/configure-local-api-env.py
cd services/api
composer install --no-interaction --prefer-dist
php artisan key:generate
php artisan migrate
php artisan test
```

`scripts/configure-local-api-env.py` refuses to overwrite an existing API `.env`; follow least privilege. Do not use production credentials or production datasets. Existing `.env` files should be inspected rather than overwritten.

## Reproducible CI

GitHub Actions checks PHP 8.4, required extensions, locked Composer dependencies, Laravel routes, PostgreSQL/Redis connectivity, migrations on disposable CI volumes, health feature tests, HTTP smoke and the standalone publication guard tests. The one-shot bootstrap generation was performed in run https://github.com/binegocom/TopKolik.com/actions/runs/41245895596 and its privileged workflow was removed from the target tree.

## Completion gate

P02-005 implementation is **done** with evidence from PR #20 and GitHub Actions run https://github.com/binegocom/TopKolik.com/actions/runs/41246075824; final merge is a repository governance step. Five Laravel tests (15 assertions), real DB/Redis and live HTTP probes, 17 standalone editorial tests, and Composer audit passed. Technical acceptance does not imply that the P01 licensing/publisher gate or production release gate is complete.
