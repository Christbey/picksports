# API V2 Auth Migration Plan

This is the follow-up plan for the auth routes intentionally left out of the
product API v1 retirement work.

## Current State

Token and passkey API auth routes remain under `/api/v1/auth`:

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/passkeys/options`
- `POST /api/v1/auth/passkeys/verify`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/logout-all`

These routes are not wrapped in `v1.api-usage` and do not receive product API
deprecation headers yet. Keep them stable until API clients have a v2 auth
rollout path.

## Target V2 Shape

Add equivalent routes under `/api/v2/auth`:

- `POST /api/v2/auth/login`
- `POST /api/v2/auth/passkeys/options`
- `POST /api/v2/auth/passkeys/verify`
- `GET /api/v2/auth/me`
- `POST /api/v2/auth/logout`
- `POST /api/v2/auth/logout-all`

Use the existing auth controllers at first so response payloads stay compatible.
Only reshape auth payloads after mobile/API consumers are updated together.

## Implementation Order

1. Add `/api/v2/auth` aliases in `routes/api-v2.php`. Done in
   `routes/api-v2.php`.
2. Add v2 auth contract tests that mirror:
   - `tests/Feature/Api/Auth/TokenAuthTest.php`
   - `tests/Feature/Api/Auth/PasskeyTokenAuthTest.php`
   Done in `tests/Feature/Api/V2/AuthEndpointAliasTest.php`.
3. Add `X-API-Replacement` headers for v1 auth routes after v2 auth tests pass.
   Done via `v1.api-deprecation` middleware.
4. Add opt-in v1 auth usage logging separately from product API logging.
   Done via `API_V1_AUTH_USAGE_LOGGING_ENABLED=true` and `api.v1.auth.usage`
   log records.
5. Update external/mobile clients to call `/api/v2/auth`.
6. Retire `/api/v1/auth` only after production usage logs show zero v1 auth
   traffic for the agreed observation window.

## Client Notes

Browser passkey management and two-factor setup use web routes such as
`/passkeys`, `/passkeys/registration/options`, and generated two-factor route
helpers. Do not migrate those as part of API token auth unless the client is an
external API consumer.

## Validation

Before retiring v1 auth:

```bash
php artisan test tests/Feature/Api/Auth
php artisan test tests/Feature/Api/V2
npm run build
```

In production, confirm no `/api/v1/auth/*` traffic remains after v2 auth
aliases are deployed and client updates are complete.

```bash
php artisan api:v1-auth-usage-report
php artisan api:v1-auth-usage-report --json
```
