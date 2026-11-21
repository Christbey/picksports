# Production access

Scope: identify the active environment and perform authorized read-only inspection. Live read-only verification on November 20, 2026 established **Laravel Cloud** as current production, application `picksports`, environment `production`, primary domain `picksports.app`, release root `/var/www/html`. See the [NBA rollout runbook](nba-research-rollout.md) for verified deployment metadata, staging behavior and redacted preflight evidence.

## Identify the environment first

Use the current Laravel Cloud environment and deployment configuration as the authority. Push-to-deploy is enabled for `main`, and the deploy command runs migrations. The local API key must be mapped privately from `LARAVEL_CLOUD_API_TOKEN` to the CLI's `LARAVEL_CLOUD_TOKEN`; never print either value.

The Forge SSH/tunnel helper and `/home/forge/picksports.app/current` instructions below are **historical**. The saved Forge host contained an old storage-only release during inspection and is not the active application. Do not use it as current production database evidence or deploy there for Cloud rollout.

Access prerequisites:

- Authorized access to the hosting account or the existing provisioned SSH identity.
- The host and SSH user from that account; verify the SSH host fingerprint through a trusted channel.
- An existing account token with appropriate permissions for API reads, or approved database credentials for database inspection.

Do not copy `.env.forge` or `.env.production` over local `.env`. Their filenames do not establish freshness. Do not print full environment files, cached configuration, or tokens in logs or chat.

## Historical: SSH to a verified Forge release

Replace the placeholder with the authorized host. SSH uses your existing agent or configured identity:

```bash
PROD_SSH_HOST='forge@<verified-production-host>'
ssh "$PROD_SSH_HOST"
```

After the remote shell opens, run these commands **inside that SSH session**:

```bash
cd /home/forge/picksports.app/current
pwd -P
git rev-parse --short HEAD
php8.5 --version
```

Confirm the physical release and commit match the deployment dashboard. If the site has a different path or PHP executable, use its configured values. Inspect logs privately and redact customer data and secrets before sharing excerpts.

## Read the application API

Prefer the authenticated API for sports analysis. Set `API_BASE` to the verified primary domain plus `/api/v2` and load an existing `API_TOKEN` into your private shell without placing its value in documentation, shell history, or saved request files.

```bash
API_BASE='https://<verified-production-domain>/api/v2'

curl --fail-with-body "$API_BASE/sports" \
  -H 'Accept: application/json'

curl --fail-with-body --get "$API_BASE/sports/nfl/games" \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $API_TOKEN" \
  --data-urlencode 'from_date=2026-11-17' \
  --data-urlencode 'to_date=2026-11-17' \
  --data-urlencode 'season=2026' \
  --data-urlencode 'page=1' \
  --data-urlencode 'per_page=100'
```

Replace angle-bracket placeholders inside the quoted assignments before running the examples. Keep `API_TOKEN` private: do not enable shell tracing (`set -x`) or curl verbose/trace output for authenticated requests.

The dates are an example; use the requested America/Chicago date range, consume pagination, and discover game/team IDs from responses. The games request permits up to 500 rows per page; increment `page` until the returned pagination indicates the end. Do not assume other endpoints use the same limits. Public metadata succeeding does not prove authenticated sport access works. API v2 accepts supported application sessions, Sanctum tokens, and Passport OAuth bearer tokens subject to scopes and entitlement checks. Passport OAuth reads require `mobile:read`; sport entitlement checks still apply. Developer sandbox credentials do not grant application sport access.

- **401:** missing, expired, or invalid authentication. Do not interpret it as missing data.
- **403:** check scopes, subscription/entitlement, and resource permissions.
- **429:** respect `Retry-After`; do not force retries or provider refreshes.

See [API authentication and contracts](../api-v2-reference.md) and [database access](database-access.md) for the authorized read-only fallback. GET inspection does not authorize POST research retries, syncs, or generation jobs.

## Deployment boundaries

Connecting does not require deploying, migrating, restarting workers, or clearing caches. `scripts/post-deploy.sh` performs migrations, optional ML package installation, cache rebuilding, and queue restart; it is a deployment mutation, not a health check. Use [production performance](../production-performance.md) for its Forge integration.

The root `deploy.sh` targets `/home/lab/docker/picksports`, resets a checkout, starts Docker containers, and runs migrations. It is a separate Docker workflow and must not be used as the Forge production access procedure.

For a deployment, use the active platform's configured release workflow and backup/rollback procedure. Confirm its commands in the dashboard rather than assuming an old rollout document describes the current pipeline.

## Queue and resource inspection

Queue names are separate from the configured queue connection: a queue named `sync` does not imply Laravel's synchronous `sync` driver. Read [queue configuration](../../config/queue.php) and confirm the actual hosting worker commands. Keep live/default consumers separate from bulk/sync work so imports do not block score updates. `queue.worker_contracts` defines default/bulk job limits; the database/Redis reservation lease must exceed the longest worker timeout. Do not copy old platform-specific Redis commands onto a database-backed deployment.

For incidents, compare oldest job age, failures/restarts, PHP peak memory, database/storage headroom, and last successful pipeline heartbeats. A training heartbeat alone does not establish fresh inference or complete grading. Capture before/after measurements for each scoped fix. Do not dispatch unbounded backfills, delete evidence, change retention, or resize infrastructure as a connection check.

For large provider payloads, preserve source hashes and reader compatibility before archival. Verify archive put/read/hash and restoration with a bounded pilot before removing database payloads. Free-space recovery and schema reclamation are separate operations; neither is authorized by a read-only investigation.

## Configuration invariants

Connecting to production does not require rotating `APP_KEY`. Preserve the stable key and review any rotation separately, including existing encrypted data and previous-key compatibility. Passport uses one shared private/public key pair across replicas and releases; never regenerate a pair per deployment to solve an access issue.

The CollegeFootballData credential key read by `config/services.php` is `COLLEGEFOOTBALLDATA_API_KEY`. The legacy spelling `COLLEGE_FOOTBALL_DATA_API_KEY` does not configure that service. Verify selected key presence privately rather than printing secret values.

For AI operational inspection, use the allowlisted report `php artisan ai:status --json`, implemented by `AiStatusCommand`. Avoid full `config:show ai` or environment dumps, which can expose credentials.
