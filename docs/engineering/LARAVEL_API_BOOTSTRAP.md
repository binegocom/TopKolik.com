# Laravel 13 API foundation — implementation scope

The project retains `services/api/app/Domain/Editorial` and its existing evidence-based safety guard. The independent Laravel skeleton is created from the official `laravel/laravel` 13.x Composer project, with a committed `composer.lock`. First bootstrap is performed on an isolated development branch; no framework migration or package installer should delete existing domain code.

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

GitHub Actions checks PHP 8.4, required PHP extensions, `composer install` with committed lock, Laravel routes, PostgreSQL/Redis connectivity, migrations on disposable CI volumes, health feature tests, and the original standalone publication guard tests. Its bootstrap generation workflow is a one-shot operation for the dedicated feature branch and is **not** a deployment workflow.

## Completion gate

P02-005 remains **in_progress** until official Laravel framework code and lock are committed, `composer install`, health/ready tests, real DB/Redis integration and dependency audit pass in CI. The existence of this design document alone is not completion.
