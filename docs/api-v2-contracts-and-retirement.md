# API V2 Contracts And V1 Retirement

This document is the operating companion to `docs/api-v2-modernization-plan.md`.
It lists the Vue-facing contracts that have moved to `/api/v2`, where to change
them, and records the completed removal of the application-only V1 API.

For the full current route matrix, supported filters, authentication rules, and
contract-test ownership, see `docs/api-v2-reference.md`. The generated
OpenAPI artifact lives in `docs/openapi-v2.json` and can be refreshed with
`php artisan api:v2-openapi-generate`.

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
| Alert preferences | `GET/POST/PUT /api/v2/alert-preferences` | `resources/js/composables/useApiV2Client.ts` | `app/Http/Controllers/AlertPreferenceController.php` | `tests/Feature/Api/V2/AlertPreferenceApiTest.php` |
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

`/api/v2/user-bets`, `/api/v2/cbb-brackets`, `/api/v2/groups`, and
`/api/v2/alert-preferences` currently preserve their legacy resource wrappers
while Vue is migrated:

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

## V1 removed (2026-09-25)

The owner confirmed V1 was used only by this application and authorized its
removal. All 337 V1 route entries are removed, including auth and security
reports. `/api/v1/*` now returns 404; there are no compatibility redirects.
The V1 usage/deprecation middleware, configuration, report commands, replacement
resolver and route files are removed. Production log collection is no longer a
retirement prerequisite for this application-only surface.

The remaining callers moved to V2:

- The NFL research panel uses `GET /api/v2/sports/nfl/games/{game}/research`.
  It requires V2 authentication, API/sport access and the existing spread,
  win-probability and betting-value permissions. The payload is under `data`.
- Browser CSP/integrity reporting uses `POST /api/v2/security/reports/{csp|integrity}`.
  Reporting remains public, throttled and CSRF exempt. The Reporting-Endpoints
  header now advertises the V2 URLs.
- Public sport pages retrieve leaderboards from `SportPlayerLeaderboardQuery`,
  removing the last direct dependency on the old sport API controllers.

92 obsolete sport API controllers were removed. Shared sport resources,
models, actions and services remain because web pages and V2 still use them.
Existing auth tokens and application data are unchanged; token/passkey routes
are under `/api/v2/auth`. No database migration is needed.

V1 contract-only capability, numeric-route and bullpen endpoint tests were
retired with their routes. Relevant domain tests now exercise V2. Player-futures
projection and prediction-access inspection tests exercise their retained
services directly. Retirement tests enforce that no V1 routes or commands
remain, and current frontend code contains no V1 calls.

Deploy backend and compiled frontend together using the normal deployment
process, including rebuilding the route cache. No deployment was performed as
part of this source change. Browsers running an old bundle may need a reload.

## Browser request behavior

`useApiV2Client` sends CSRF tokens on browser mutations and an `Idempotency-Key`
on supported product writes. One automatic retry of a network failure reuses
the exact key and body. Pass `idempotencyKey` in request options to retain an
operation identity across explicit retries; do not reuse it for a new action.
Completed HTTP errors and aborted requests are not automatically retried.

Reads and writes reject with `ApiError`, preserving `status`, `data`, `code`,
`requestId`, and `retryAfter`. Consumers should catch errors rather than treating
all failures as empty data.

Regenerate `docs/openapi-v2.json` with `php artisan api:v2-openapi-generate`
whenever routes or contracts change. The OpenAPI tests enforce artifact parity.
