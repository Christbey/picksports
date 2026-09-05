# Laravel Cloud rollout runbook

This runbook preserves MySQL 8.4 for the first infrastructure move and treats every schema change in this platform rollout as expand/backfill/compare/contract work.

## Required services

- Laravel Cloud web compute for the Inertia application and `/api/v2`.
- Managed MySQL 8.4. Do not import until the source database fingerprint is approved.
- Valkey for cache, sessions, queues, rate counters, scheduler locks, and unique-job locks.
- Private Laravel Object Storage for provider source files, datasets, model artifacts, evaluation reports, and historical exports.
- Independent worker clusters for `sync`, `predictions`, `ml`, `ai`, `notifications`, and `webhooks` queues.
- One scheduler process. Every scheduled event is guarded by `onOneServer()`.

## Environment contract

Set the normal Laravel application, database, mail, Stripe, and provider variables plus:

```dotenv
DB_CONNECTION=mysql
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

FILESYSTEM_DISK=s3
PROVIDER_DATA_DISK=s3
PROVIDER_DATA_PREFIX=providers

ML_FILESYSTEM_DISK=s3
ML_STORAGE_PREFIX=ml
ML_CACHE_DISK=ml-cache

PASSPORT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n..."
PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n..."
```

Object-storage buckets must be private. Laravel Cloud injects managed bucket
access through `LARAVEL_CLOUD_DISK_CONFIG`; do not duplicate those credentials
as custom `AWS_*` variables. Store provider and ML data on the managed `s3`
disk under separate prefixes. Store Passport keys as multiline secrets and do
not generate a different key pair on each replica.

## Pre-import identity gate

Capture and archive the source fingerprint before taking a backup:

```bash
php artisan db:fingerprint --connection=mysql --exact-counts --output=storage/app/deploy/source-fingerprint.json
php artisan db:schema-health --connection=mysql --expected-database=psa --minimum-mysql=8.4
```

The August 13, 2026 production backup identifies its source database as `psa`. Treat this value as an environment-specific release input and verify it from the backup header before every rehearsal; do not substitute the local `psa2` database or the older `picksports` schema.

After restoring into Cloud but before serving traffic, run the same commands with `--against` the archived fingerprint. Stop if the database name, migration history, tables, constraints, or exact row counts differ unexpectedly.

## Expand deployment order

1. Put the current application into maintenance mode and take a recoverable MySQL backup.
2. Deploy code, run `php artisan migrate --force`, and verify `php artisan db:schema-health --minimum-mysql=8.4`.
3. Provision shared Passport keys, then configure each public native callback exactly:

   ```bash
   php artisan auth:configure-native-oauth-client \
     --name="PickSports Native" \
     --redirect-uri="picksports://oauth/callback"
   ```

4. Run dry-run reports before writes:

   ```bash
   php artisan sports:backfill-event-identities --dry-run --isolated
   php artisan sports:backfill-canonical-predictions --dry-run --isolated
   php artisan user-bets:backfill-prediction-references
   php artisan sports:report-canonical-prediction-lineage --fail-on-incomplete
   ```

5. Resolve every reported identity conflict. Then run the commands in write mode during a monitored window.
6. Re-run dry-run/compare reports until they show no missing or conflicting records.
7. Configure and test provider source archival with a non-production object prefix before enabling `--archive-source` on NFLverse imports.
8. Warm application caches, restart workers, leave legacy columns/routes intact, and restore traffic.

No contract migration or legacy-column deletion is authorized in this rollout.

## Worker separation

Run long-lived workers with explicit queues and bounded memory/time. Example process commands:

```bash
php artisan queue:work redis --queue=sync --sleep=1 --tries=3 --timeout=300 --max-time=3600
php artisan queue:work redis --queue=predictions --sleep=1 --tries=2 --timeout=300 --max-time=3600
php artisan queue:work redis --queue=ml --sleep=2 --tries=1 --timeout=900 --max-time=3600
php artisan queue:work redis --queue=ai --sleep=2 --tries=3 --timeout=180 --max-time=3600
php artisan queue:work redis --queue=notifications --sleep=1 --tries=5 --timeout=120 --max-time=3600
php artisan queue:work redis --queue=webhooks --sleep=1 --tries=8 --timeout=120 --max-time=3600
```

Scale each cluster independently from observed queue latency and job duration. Training stays on the isolated DigitalOcean runner; Laravel queues orchestration and consumes persisted artifacts.

## Cutover and rollback

- Rehearse backup, import, migration, application smoke tests, rollback, and DNS TTL changes against a production-size clone.
- Before DNS cutover, verify `/up`, `/api/v2/sports`, an authenticated v2 read, Valkey locks, object-store put/get/hash verification, and all worker queues.
- The `picksports.app` and `www.picksports.app` DNS cutover to Laravel Cloud is complete. The apex, `www` redirect, `/up`, and `/api/v2/sports` passed after the primary-domain deployment.
- Deployment #9 activated the `s3` provider and ML storage aliases. The saved values, live ML disk configuration, ML-prefix object round trip, `/up`, and `/api/v2/sports` passed. Re-verify the provider configuration after the lifecycle code that defines it is deployed.
- Deployment #10 completed the safe `.env.forge` merge without restoring Forge infrastructure settings. The former production application key is retained in `APP_PREVIOUS_KEYS`; Mailgun is configured as a dormant fallback while SMTP remains active; the Google callback and report time are staged; all OAuth, reporting, ML training/promotion, and canonical launch gates remain disabled. Live configuration, `/up`, and `/api/v2/sports` passed.
- Keep the old environment running, read-only where practical, and recoverable for 48-72 hours after cutover. Do not decommission it until production logs, authentication, scheduled activity, mail, and rollback readiness have been observed through that window.
- Before enabling Laravel Cloud workers or the scheduler, stop their counterparts on the old server to prevent duplicate imports, predictions, notifications, webhooks, or scheduled jobs.
- Application rollback is safe while all new columns/tables remain additive. Do not roll database migrations back after new writes begin; deploy the previous code against the expanded schema.
- If canonical backfill comparison fails, disable the new readers and keep legacy reads active. Do not delete canonical records during incident response.
- If object archival fails, remove `--archive-source` and continue legacy row payloads until storage integrity is restored.

## Promotion gates

Traffic may move only when:

- database fingerprint and schema-health checks pass;
- OpenAPI generation matches `docs/openapi-v2.json`;
- TypeScript, Swift, and Kotlin generated SDKs compile in CI;
- canonical event, prediction, and user-bet dry-runs report no conflicts;
- the canonical prediction-lineage report is complete and consistent;
- scheduler single-server guardrails pass;
- provider license readiness is confirmed for every publicly redistributed field;
- backup restore and application rollback have been timed and rehearsed.
