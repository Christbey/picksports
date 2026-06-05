# API V2 Contracts And V1 Retirement

This document is the operating companion to `docs/api-v2-modernization-plan.md`.
It lists the Vue-facing contracts that have moved to `/api/v2`, where to change
them, and how to validate the remaining `/api/v1` usage before retirement.

## Current V2 Vue Surfaces

| Surface | V2 endpoint | Frontend owner | Backend owner | Contract tests |
| --- | --- | --- | --- | --- |
| Sport metadata | `GET /api/v2/sports` | `resources/js/composables/useApiV2Client.ts` | `app/Http/Controllers/Api/V2/SportController.php` | `tests/Feature/Api/V2/SportMetadataEndpointTest.php` |
| Sport games | `GET /api/v2/sports/{sport}/games` | `resources/js/composables/useApiV2Client.ts` | `app/Http/Controllers/Api/V2/SportGameController.php` | `tests/Feature/Api/V2/SportGameEndpointContractTest.php` |
| Sport predictions | `GET /api/v2/sports/{sport}/predictions` | `resources/js/composables/useApiV2Client.ts` | `app/Http/Controllers/Api/V2/SportPredictionController.php` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| Live scoreboard rail | `GET /api/v2/live-scoreboard` | `resources/js/components/AppLiveScoreRail.vue` | `app/Http/Controllers/Api/V2/LiveScoreboardController.php` | `tests/Feature/Api/V2/LiveScoreboardEndpointContractTest.php` |
| Saved/tracked picks | `GET/POST/PUT/DELETE /api/v2/user-bets` | `resources/js/pages/MyBets.vue`, `resources/js/components/predictions/*` | `app/Http/Controllers/BetTrackerController.php` | `tests/Feature/BetTrackerTest.php` |
| March Madness brackets | `GET/POST/PATCH /api/v2/cbb-brackets` | `resources/js/pages/MarchMadnessBracket.vue` | `app/Http/Controllers/Api/CBB/BracketController.php` | `tests/Feature/CbbBracketApiTest.php` |
| Bracket groups | `GET/POST/PATCH /api/v2/groups` | `resources/js/pages/MarchMadnessBracket.vue` | `app/Http/Controllers/Api/GroupController.php` | `tests/Feature/GroupApiTest.php` |
| Payload inspector | `GET /api/v2/admin/payload-inspector` | `resources/js/composables/usePayloadInspector.ts`, `resources/js/pages/settings/Admin.vue` | `app/Http/Controllers/Api/V2/Admin/PayloadInspectorController.php` | `tests/Feature/Api/V2/Admin/PayloadInspectorTest.php` |

## Contract Shapes

### Standard Sport Collection

```json
{
  "data": [],
  "meta": {
    "version": "v2",
    "sport": "mlb",
    "contract": "sports.games.index",
    "filters": {},
    "pagination": {},
    "tier": {},
    "freshness": {},
    "warnings": []
  }
}
```

### Standard Sport Item

```json
{
  "data": {},
  "meta": {
    "version": "v2",
    "sport": "mlb",
    "contract": "sports.predictions.show",
    "tier": {},
    "freshness": {},
    "warnings": []
  }
}
```

### App-Level Compatibility Resources

`/api/v2/user-bets`, `/api/v2/cbb-brackets`, and `/api/v2/groups` currently
preserve their legacy resource wrappers while Vue is migrated:

```json
{
  "data": {}
}
```

`GET /api/v2/user-bets` is the exception because it returns the bet tracker
dashboard payload:

```json
{
  "bets": { "data": [] },
  "statistics": {},
  "tracking": null
}
```

Do not reshape these app-level contracts until the frontend and tests move
together. They are v2 route aliases with v1-compatible payloads by design.

## Where To Change Things

- Add or rename v2 routes in `routes/api-v2.php`.
- Add frontend methods in `resources/js/composables/useApiV2Client.ts`.
- Regenerate Wayfinder routes with `php artisan wayfinder:generate`.
- Add sport contract tests under `tests/Feature/Api/V2`.
- Add app-level compatibility tests in the existing feature test for that
  domain, such as `tests/Feature/BetTrackerTest.php`.
- Keep Vue pages calling the v2 client instead of hard-coded `fetch` or Axios
  URLs for product data.

## Production Validation

Use the admin payload inspector for sport-facing payloads:

```bash
php artisan route:list --path=api/v2/admin/payload-inspector
```

From an authenticated admin session, inspect:

```txt
/api/v2/admin/payload-inspector?profile=dashboard&sport=mlb
/api/v2/admin/payload-inspector?profile=live-scoreboard
/api/v2/admin/payload-inspector?profile=sport-predictions&sport=mlb
/api/v2/admin/payload-inspector?profile=player-props&sport=nba
/api/v2/admin/payload-inspector?profile=admin-healthcheck-cards&sport=mlb
/api/v2/admin/payload-inspector?profile=user-bets&include_payload=true
/api/v2/admin/payload-inspector?profile=cbb-brackets&include_payload=true
/api/v2/admin/payload-inspector?profile=settings-admin&include_payload=true
/api/v2/admin/payload-inspector?profile=alert-preferences&include_payload=true
```

The payload should include:

- `meta.contract`
- `meta.version`
- selected request filters
- freshness metadata
- warnings or missing-field findings when data is stale or incomplete

## V1 Retirement Logging

Legacy product `/api/v1` routes now pass through `v1.api-usage`. This excludes
v1 auth and security report routes.

Enable production usage logging with:

```env
API_V1_USAGE_LOGGING_ENABLED=true
```

Deprecation headers are enabled by default:

```env
API_V1_DEPRECATION_HEADERS_ENABLED=true
```

Legacy product responses include `X-API-Deprecated: true` and an
`X-API-Replacement` header. App-level migrated routes point at their exact v2
prefix, such as `/api/v2/user-bets`; sport routes point at
`/api/v2/sports/{sport}/...`.

When logging is enabled, the app writes `api.v1.usage` records containing:

- method
- path
- route name
- user id when authenticated
- IP address
- user agent

Use this to prove no external or internal clients still depend on legacy
product routes before removal.

Summarize logged usage with:

```bash
php artisan api:v1-usage-report
```

Useful variants:

```bash
php artisan api:v1-usage-report --limit=50
php artisan api:v1-usage-report --path=storage/logs/laravel.log
php artisan api:v1-usage-report --json
```

## Retirement Checklist

1. Run production with `API_V1_USAGE_LOGGING_ENABLED=true`.
2. Run `php artisan api:v1-usage-report` and review usage grouped by `path`
   and `route_name`.
3. Confirm Vue scans have no product-data `/api/v1` calls:

```bash
rg -n "api/v1|axios\\.|fetch\\(" resources/js --glob '!routes/**' --glob '!actions/**'
```

4. Keep v1 auth routes until token/passkey API clients have a separate v2 auth
   migration plan.
5. Remove only v1 product routes with zero observed usage.
6. Re-run:

```bash
php artisan test tests/Feature/Api/V2
npm run build
```
