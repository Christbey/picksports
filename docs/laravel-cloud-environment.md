# Laravel Cloud production environment contract

This document defines the environment surface for the `picksports · production`
Laravel Cloud environment. It intentionally omits every secret value.

## Principles

- Let Laravel Cloud inject `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`,
  `LOG_CHANNEL`, and all `DB_*` variables.
- Keep one stable `APP_KEY`. Rotating it requires an explicit application-key
  rotation plan and `APP_PREVIOUS_KEYS` during the transition.
- Do not copy the full `.env.example` into Cloud. Formula and tuning values use
  version-controlled defaults; only operational overrides belong in Cloud.
- Cloud compute storage is ephemeral. Durable provider inputs, datasets, model
  artifacts, exports, and mirrored assets use private S3-compatible storage.
- Feature activation is staged. A configured credential does not imply that the
  associated job, reader, or public feature is enabled.

## Current infrastructure profile

The environment currently has managed MySQL, web compute, a private Laravel
Valkey cache named `picksports-cache`, and a private Laravel Object Storage
bucket named `picksports-storage`. Deployment #5 attached the cache,
deployment #6 switched the application drivers to Redis, and deployment #7
attached object storage as the default `s3` disk. Deployment #8 made
`picksports.app` the primary production domain and injected it as `APP_URL`:

```dotenv
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
APP_MAINTENANCE_DRIVER=cache
APP_MAINTENANCE_STORE=database
```

Laravel's live driver report confirms Redis for cache, queue, and sessions. A
production cache write/read round trip also passed. Queue workers, session
continuity across deploys, and scheduler single-server locks still require
their operational checks when workers and the scheduler are enabled.

Laravel Cloud injects the bucket through `LARAVEL_CLOUD_DISK_CONFIG`; separate
`AWS_*` credentials are neither required nor exposed. A production object
write/read/SHA-256 verification passed on the managed `s3` disk. Deployment #9
saved `PROVIDER_DATA_DISK=s3` and `ML_FILESYSTEM_DISK=s3`. Laravel Cloud's
environment editor confirms both values, and the live ML configuration resolves
to `s3`. An ML-prefix object write/read/SHA-256 verification also passed at
`ml/verification/storage-alias-1788475807.txt`.

The deployed commit `c67c107` predates the new provider-data configuration
file, so `config('provider-data.storage.disk')` remains unavailable in this
release even though its environment value is ready. Re-verify that configuration
key and perform a provider-prefix object round trip after deploying the lifecycle
code.

## Required custom variables

### Runtime and browser security

```dotenv
APP_KEY=<existing Laravel Cloud value; do not rotate>
APP_TIMEZONE=America/Chicago
LOG_LEVEL=info
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

### Durable storage

```dotenv
FILESYSTEM_DISK=s3
PROVIDER_DATA_DISK=s3
PROVIDER_DATA_PREFIX=providers
ML_FILESYSTEM_DISK=s3
ML_STORAGE_PREFIX=ml
ML_CACHE_DISK=ml-cache
```

Use the one private managed disk with separate `providers/`, `ml/`,
`evaluations/`, and `exports/` prefixes. Dedicated S3 aliases and credentials
are only needed later if compliance or scaling requires separate buckets.

### External services

Configure only services with an active account and verified credential:

- SMTP: `MAIL_MAILER`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`,
  `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
- Sports providers: `ODDS_API_KEY`, `COLLEGEFOOTBALLDATA_API_KEY`.
- AI: `OPENAI_API_KEY`, `OPENAI_BASE_URL`, `OPENAI_MODEL`.
- Billing: `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, active
  `STRIPE_PRICE_*` identifiers, and `VITE_STRIPE_KEY`.
- Google OAuth: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and the callback
  corresponding to the final production domain.
- Web push: `WEB_PUSH_VAPID_SUBJECT`, `WEB_PUSH_VAPID_PUBLIC_KEY`, and
  `WEB_PUSH_VAPID_PRIVATE_KEY`.

The historical production file used `COLLEGE_FOOTBALL_DATA_API_KEY`; the
application reads `COLLEGEFOOTBALLDATA_API_KEY`. Laravel Cloud must use the
application spelling.

## Forge environment migration audit

The local `.env.forge` file was audited against Laravel Cloud on September 3,
2026 without exposing secret values. It contains 128 keys; 61 overlap the
Cloud custom environment. After normalizing dotenv quotes, 38 overlapping
values match exactly. The remaining differences are intentional Cloud-safe
replacements, including managed database and Redis connections, managed `s3`
storage, encrypted sessions, `info` logging, and disabled ML, AI, OAuth, and
scheduled-report launch gates.

Do not copy `.env.forge` wholesale. In particular, do not restore its Forge
database/Redis endpoints, DigitalOcean Spaces credentials, local filesystem
driver, database queue driver, disabled session encryption, debug logging,
Forge-only Python paths, or enabled ML training/promotion flags. Blank price,
recipient, and artifact identifiers and unused legacy aliases are also omitted.

The active Stripe, Odds API, OpenAI, Google OAuth client, CollegeFootballData,
and web-push key-pair values already match the Forge source. The current Cloud
`APP_KEY` intentionally remains primary; the Forge key should be retained as
`APP_PREVIOUS_KEYS` instead of replacing it. A production audit found no
encrypted webhook secrets, device tokens, or two-factor secrets requiring an
immediate key swap.

Deployment #10 completed the minimal safe Forge merge:

```dotenv
APP_PREVIOUS_KEYS=<existing Forge APP_KEY>
MAILGUN_DOMAIN=<existing Forge value>
MAILGUN_SECRET=<existing Forge value>
MAILGUN_ENDPOINT=<existing Forge value>
GOOGLE_REDIRECT_URI=https://picksports.app/auth/google/callback
ADMIN_EMAIL_REPORT_DAILY_TIME=07:30
```

Mail remains on the verified SMTP transport; the Mailgun API values are kept as
a dormant fallback. Google OAuth and the admin report remain disabled until
their separate launch checks pass. Live configuration verification confirmed
one previous encryption key, populated Mailgun fallback credentials, the final
Google callback, the `07:30` report time, and disabled NFL/MLB shadow, training,
and auto-promotion gates. `/up` and `/api/v2/sports` passed after deployment.

## Safe launch gates

Keep these disabled until their individual readiness checks pass:

```dotenv
PREDICTION_LIFECYCLE_CBB_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_CBB_CANONICAL_READS=false
PREDICTION_LIFECYCLE_CFB_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_CFB_CANONICAL_READS=false
PREDICTION_LIFECYCLE_MLB_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_MLB_CANONICAL_READS=false
PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_NBA_CANONICAL_READS=false
PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false
PREDICTION_LIFECYCLE_WCBB_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_WCBB_CANONICAL_READS=false
PREDICTION_LIFECYCLE_WNBA_CANONICAL_PIPELINE=false
PREDICTION_LIFECYCLE_WNBA_CANONICAL_READS=false

NFL_ML_SHADOW_ENABLED=false
NFL_ML_WEEKLY_TRAINING_ENABLED=false
NFL_ML_AUTO_PROMOTE_ENABLED=false
MLB_ML_SHADOW_ENABLED=false
MLB_ML_WEEKLY_TRAINING_ENABLED=false
MLB_ML_AUTO_PROMOTE_ENABLED=false
MLB_PERIOD_ML_ENABLED=false

AI_DAILY_PREDICTION_ANALYSIS_ENABLED=false
AI_NFL_GAME_CONTEXT_RESEARCH_ENABLED=false
AI_DATA_FRESHNESS_REVIEW_ENABLED=false
AI_MARKET_READINESS_REVIEW_ENABLED=false
AI_MODEL_AUDIT_REVIEW_ENABLED=false
AI_PUBLISHING_GUARDRAIL_REVIEW_ENABLED=false
AI_PUBLISHING_GUARDRAILS_ENFORCED=false
```

Canonical writes are enabled one sport at a time, then compared and evaluated.
Canonical reads are enabled only after the sport's write comparison and strict
lineage checks pass. ML promotion remains a separate decision from shadow
inference. AI automation remains separate from deterministic prediction
generation.

## Domain-dependent values

The primary production domain is `picksports.app`. Cloudflare proxies the apex
and `www` records to Laravel Cloud with Full (strict) SSL; Laravel Cloud
redirects `www.picksports.app` to the apex domain. Keep the Laravel Cloud domain
`picksports-production-z1cnxz.laravel.cloud` as the platform fallback. Retain
both `_acme-challenge` validation CNAME records for certificate renewal.

Deployment #8 activated `APP_URL=https://picksports.app`. The homepage, `/up`,
`/api/v2/sports`, and the `www` redirect passed after deployment. Google OAuth
and passkeys remain disabled until the Google provider allowlist, credentials,
and callback are verified. At that point set:

```dotenv
GOOGLE_REDIRECT_URI=https://picksports.app/auth/google/callback
PASSKEYS_RP_ID=picksports.app
PASSKEYS_ORIGIN=https://picksports.app
PASSKEYS_ENABLED=true
```

Passport requires one shared private/public key pair in Laravel Cloud secrets.
Never generate a different pair per replica or deployment.

## Verification after an environment change

1. Redeploy so build-time `VITE_*` values and cached Laravel configuration use
   the new environment.
2. Run `php artisan optimize:clear` and `php artisan config:cache` as part of
   deployment.
3. Check `/up`, a public `/api/v2` read, login/session persistence, SMTP, object
   storage put/get, and provider connectivity.
4. Confirm queue workers and the scheduler use the intended connections.
5. Run `php artisan schedule:list` and verify every costly or publishing task is
   gated as expected.
6. Do not enable canonical reads while strict prediction lineage is incomplete.
