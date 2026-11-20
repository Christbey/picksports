# Local development

Scope: a developer-owned checkout and database. Reviewed against repository configuration on November 17, 2026; these steps were not executed against a fresh machine.

## Requirements

- PHP 8.5.x and Composer. `composer.json` requires `~8.5.0`.
- Node.js 24 and npm, matching `.github/workflows/tests.yml`. The root manifest does not declare a Node engine; the lockfile records frontend dependency requirements.
- SQLite for the default example setup, or MySQL 8.4 for production-compatible database work.
- Optional provider credentials only for the integrations you need. A local checkout does not require production credentials.

Verify `php -v`, `composer --version`, and `node --version`. The helper `bash scripts/php85.sh --version` locates PHP 8.5 or accepts an explicit `PHP_BINARY`.

## Install

1. In a fresh checkout, copy `.env.example` to `.env`. Preserve an existing `.env`. Ensure Laravel's writable runtime directories exist before Composer package discovery:

   ```bash
   mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs
   ```
2. Install dependencies with `composer install` and `npm ci`. Composer hooks run package discovery and may install optional ML packages if their runtime is available.
3. Set local `APP_URL`, database, cache, session, and queue values. The example uses database-backed cache, sessions, and queues, whose tables are installed by migrations. Confirm no production connection or inherited `DB_URL` is active. See [database access](operations/database-access.md).
4. For a new local environment, run `php artisan key:generate` once. Create `database/database.sqlite` if using SQLite and the file is absent.
5. Run `php artisan migrate` against the verified local database.
6. Start `composer run dev`. It runs the PHP server, a queue listener, log viewer, and Vite. Open the URL printed by the server.

With Herd, use its configured site URL for `APP_URL` and run `npm run dev` for assets. Run a local queue listener separately only if the task needs background jobs. Review enabled jobs and credentials first: workers execute queued work and can call external services.

`composer run setup` is a convenience script that generates a key, runs forced migrations, and builds assets. Use it only on a fresh, verified local environment; it is not a production connection check.

A new database has schema but does not contain production sports history, predictions, or market quotes. Empty sports screens after installation are expected until an approved local dataset or task-specific fixture is supplied. Do not start all provider syncs to resolve an empty local database.

## Frontends

- `resources/js/`: primary Inertia application. Use `npm run dev` or `npm run build`.
- `frontend/`: separate Vue workspace. Use `npm run frontend:dev`, `npm run frontend:build`, and `npm run frontend:typecheck` from the root. Copy `frontend/.env.example` to `frontend/.env` if absent. Leave `VITE_API_ORIGIN` empty for the local same-origin proxy and set `FRONTEND_DEV_BACKEND` to the local PHP server (the example is `http://127.0.0.1:8000`). Start that backend, then run `npm run frontend:dev` in another terminal. The standalone Vite server uses port 5174 and proxies API, CSRF, and login requests to the backend; its API is not a separate data service. For Herd, point the proxy at the local Herd site. These values are local configuration examples, not production addresses.

## Docker alternative

`compose.yaml` defines a Sail PHP 8.5 application, MySQL 8.4, and Redis, but also expects an external `web` network and Traefik routing for `picksports.local`. It is not a self-contained replacement for the native/Herd steps above. Check the Compose network and environment requirements before using it; the root `deploy.sh` is a destructive deployment workflow, not a local install shortcut.

## Checks

Run checks relevant to the change, using the local environment:

```bash
php artisan test --compact --filter=RelevantTest
composer run test:lint
npm run quality:check
npm run build
```

`phpunit.xml` configures SQLite in memory, array cache/session/mail, and synchronous queues. Clear a stale local config cache before testing and ensure exported environment variables do not override the test database. Full `composer test` also clears local configuration and checks PHP formatting.

For missing frontend changes, check Vite is running or rebuild assets. For database connection errors, check the selected driver, `DB_URL`, and cached configuration before changing credentials. Do not run production syncs, research generation, or a full scheduler simply to populate a local screen.
