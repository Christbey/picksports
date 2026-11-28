# API V2 Reference

Route inventory checkpoint: November 25, 2026, 89 local V2 route entries (GET includes HEAD). Authentication policy updated November 26; complete workflow certification remains separate.

This is the human and agent reference for `/api/v2`: route surface, expected
filters, frontend client ownership, and contract-test coverage. Use
`routes/api-v2.php` and its request classes to verify the current implementation.
V1 was removed on September 25, 2026; its former routes are not the current API.
Superseded migration plans have been removed; API operating policies live in
[api/v2/README.md](api/v2/README.md).

The generated [OpenAPI artifact](openapi-v2.json) contains named request and response schemas. The [endpoint contract index](api-v2-endpoint-contracts.md) maps every method and path to those schemas. This reference
adds the operational context that a route-level schema cannot fully express.

## Global Conventions

- Base path: `/api/v2`.
- Route source: `routes/api-v2.php`.
- Frontend client source: `resources/js/composables/useApiV2Client.ts`.
- Sport route context: `app/Services/Api/V2/SportContext.php` and related
  query/resource classes under `app/Services/Api/V2` and
  `app/Http/Resources/Api/V2`.
- Standard sport responses include a `meta.version` value of `v2`, a
  `meta.contract` name, selected filters, freshness context, and warnings when
  data is stale or incomplete.
- Collection endpoints should paginate or return bounded datasets. Request
  classes generally cap `per_page` at 100; games and team metrics allow 500.
  Boards, injuries and availability routes use their own limits.
- Date filters must use the shared backend date-window helpers. Do not compare
  user-selected dates directly against raw UTC timestamps in controllers or
  Vue.

## Authentication And Access

| Route family | Authentication and access | Rate limits |
| --- | --- | --- |
| `GET /sports`, `GET /sports/{sport}` | Public metadata | No route-specific limiter |
| `POST /auth/login`, `POST /auth/device-sessions/refresh` | Public; password or refresh-token validation | `api-v2-auth-login`: default 10/minute/IP, shared bucket |
| `POST /auth/passkeys/options` | Public challenge creation | `api-v2-auth-passkey-options`: default 20/minute/IP |
| `POST /auth/passkeys/verify` | Public credential verification | `api-v2-auth-passkey-verify`: default 10/minute/IP |
| Auth session management and app product routes | `v2.auth` | Mutations marked in the contract index use `api-v2-writes`: default 60/minute/user |
| Admin inspector | `v2.auth`, `admin` | No route-specific limiter |
| Sport data | `v2.auth`, `v2.sport-api-access`; research also requires four prediction permissions | No route-specific limiter |
| `GET /developer/sandbox` | `auth:developer-api`, developer product `developer-sandbox`, scope `sandbox:read`, entitlement policy | Developer entitlement policy applies |
| `POST /security/reports/csp`, `POST /security/reports/integrity` | Public, CSRF exempt | 1,000/minute in production; 10,000/minute in local/testing |

Paths above are relative to `/api/v2`. `v2.auth` accepts the application session,
Sanctum tokens, or Passport OAuth bearer tokens. Scoped Sanctum bearer tokens
and Passport OAuth tokens require `mobile:read` for safe methods and
`mobile:write` for mutations. A missing Sanctum ability returns 403 with
`insufficient_token_scope`; missing Passport scope retains
`insufficient_oauth_scope`. Legacy Sanctum wildcard tokens remain supported,
and application sessions use their normal authorization and CSRF controls.
Sport entitlement and resource
ownership checks still apply after authentication. Developer credentials use the
separate sandbox guard; they do not grant application API access.

Send `Accept: application/json`; send `Content-Type: application/json` for JSON
bodies. Browser session mutations require CSRF state: the shared client reads
`XSRF-TOKEN` into `X-XSRF-TOKEN`, falls back to the page's CSRF meta token, or
initializes cookies through `/sanctum/csrf-cookie`. That bootstrap URL is outside
V2. Authenticated bearer clients use `Authorization: Bearer <token>`.

Limits are configurable in `config/api.php`. A 429 response includes
`Retry-After`; clients should respect it.

Access rules should stay in middleware, policies, request classes, and
dedicated access services. Controllers and resources should not duplicate tier
or permission checks.

## Authentication flows and request examples

Examples use placeholders. Set `API_BASE` to your deployment's `/api/v2` URL and
`API_TOKEN` to a token issued to your account. These calls do not run as part of
documentation verification.

```bash
API_BASE='https://your-host.example/api/v2'
API_TOKEN='<your-access-token>'

# Public capability metadata.
curl --fail-with-body "$API_BASE/sports/nfl" -H 'Accept: application/json'

# Authenticated game listing. Dates use the configured sports business timezone.
curl --fail-with-body --get "$API_BASE/sports/nfl/games" \
  -H 'Accept: application/json' -H "Authorization: Bearer $API_TOKEN" \
  --data-urlencode 'season=2026' --data-urlencode 'per_page=25'

# Research requires all four prediction permissions in addition to sport access.
curl --fail-with-body "$API_BASE/sports/nfl/games/<game-id>/research" \
  -H 'Accept: application/json' -H "Authorization: Bearer $API_TOKEN"
```

Password login accepts `email`, `password` and optional `device_name` and `token_profile`; success
returns `token_type`, `access_token`, `user`, granted `token_profile`/`abilities`, and nullable `expires_at`, `expires_epoch`, `expires_in`. `GET /auth/me` returns the
current user resource. `POST /auth/logout` revokes the current token/device
session; `POST /auth/logout-all` revokes all of the user's tokens/device
sessions. Both return 204 with no body.

Passkey login first calls `POST /auth/passkeys/options` with optional `email`.
Use the returned challenge in the platform WebAuthn flow, then send
`challenge_id`, `credential_id`, `client_data_json`, `authenticator_data` and
`signature` to `POST /auth/passkeys/verify`; `device_name` and `token_profile` are optional. The
verification response has the same token envelope as password login.

Password and passkey token responses use `Cache-Control: private, no-store`.
Finite issuance is staged behind `API_TOKEN_FINITE_ISSUANCE_ENABLED=false` by default. While off, existing interactive issuance returns `legacy_interactive`, wildcard abilities and null expiry; requesting `read_only` is rejected. After verified activation, `interactive` grants only `mobile:read`/`mobile:write`; `read_only` grants only `mobile:read`. The default lifetime is 60 minutes, configurable from 5 to 1,440; exact UTC/epoch expiry applies at admission. A read-only credential cannot POST logout; revoke it through an authorized account-management flow. An expired credential returns 401; reauthenticate or use the native refresh flow, and never automatically replay a failed mutation. Browser sessions, OAuth and unmarked native/legacy credentials retain their existing policy. Existing permanent credentials are not automatically revoked. See [finite API token operations](operations/api-token-policy.md) for fleet, rollback, pruning and legacy migration gates.

For native applications:

1. Use a browser session or, with native epoch admission enabled, a finite server-issued
   interactive password/passkey API login. Then `POST /auth/device-sessions` with `device_name` (max 120),
   `platform` (`ios` or `android`) and optional `device_identifier` (max 255).
   A 201 response contains `access_token`, `refresh_token`, both expiry
   timestamps, `token_type` and `device_session` (`id`, `device_name`, `platform`).
2. Rotate credentials through `POST /auth/device-sessions/refresh` with
   `refresh_token` (max 512). Store the newly returned pair. This endpoint
   validates the refresh token without requiring an access token; an invalid
   token returns 401 with code `invalid_refresh_token`.
3. Register push delivery at
   `POST /auth/device-sessions/{deviceSession}/push-registrations`: `provider`
   is `apns` or `fcm`, `device_token` is required (max 4096), and optional
   `environment` is `sandbox` or `production`. It returns `data` containing
   `device_session_id`, `provider`, `environment`, `last_registered_at`.
   New registrations return 201; existing registrations return 200.
4. Delete push registrations with the matching `DELETE` route and provider,
   or revoke the device session with `DELETE /auth/device-sessions/{deviceSession}`.
   Both return 204. `{deviceSession}` is the returned public session ID, and
   must belong to the authenticated user.

An existing native-session-linked bearer cannot bootstrap another family (403); rotate its
refresh token instead. Passport OAuth stays on its own refresh protocol and is not converted
here. Unmarked, unlinked legacy bearer bootstrap is retained only while native epoch admission
is OFF. API finite login issuance and all authentication runtimes must be verified before
activating native epoch admission; a device name never proves credential provenance.

Native issuance and refresh responses use `Cache-Control: private, no-store`.
Push registration rechecks the current session under a database lock; revoked
sessions and tokens retained by another account are refused. The same account
may move a registration between its active sessions. See [native lifecycle
boundaries](operations/native-device-sessions.md), including account-switching
and connection requirements, before enabling a device workflow.

## Mutation retries, errors and request IDs

The [endpoint contract index](api-v2-endpoint-contracts.md) marks the mutations
that support `Idempotency-Key`. It is optional server-side; send it when a
mutation may be retried. Keys contain 1–255 visible ASCII characters without
spaces and are scoped to the authenticated principal and named route. Reuse a
key only for the same method, route parameters and payload. The default lifetime
is 24 hours (`API_V2_IDEMPOTENCY_TTL_HOURS`).

A completed replay returns the original status/body with
`Idempotency-Replayed: true`. Initial responses use `false`; both include
`Idempotency-Key-Expires-At`. A changed payload returns 409
`idempotency_key_reused`. Concurrent requests for the same key wait for the local
transaction, then replay its completed receipt. Business writes and successful
response receipts commit atomically for the explicitly classified local-database
routes. Exceptions and returned HTTP errors roll back those local writes; their
safe reservations may resume with the original fingerprint. Incomplete legacy
outcomes fail closed with 409 `idempotency_outcome_unknown`, even after TTL, and
are excluded from pruning. Invalid key syntax returns 400
`invalid_idempotency_key`; an unclassified keyed operation returns 503
`idempotency_operation_unsupported`. Only completed expired receipts can be
recycled or pruned. See the [atomic idempotency contract and cutover
requirements](production-readiness/api-idempotency-2026-11-25.md).

The Vue client supplies CSRF and idempotency headers, retries a network failure
once with the same key/body, and exposes `ApiError` with `status`, `data`, `code`,
`requestId`, and `retryAfter`. It does not automatically retry HTTP errors or
aborted requests. Explicit retries can pass a stable `idempotencyKey` option.

V2 responses include `X-Request-ID`. Errors use this shape (validation errors
also include `error.fields` and the compatibility `errors` field):

```json
{
  "error": {
    "code": "unauthenticated",
    "message": "Unauthenticated.",
    "request_id": "example-request-id"
  },
  "request_id": "example-request-id",
  "message": "Unauthenticated."
}
```

| Status | Meaning and client action |
| --- | --- |
| 400 | Invalid request/header; correct it before retrying. |
| 401 | Missing, expired or invalid authentication; authenticate or refresh. |
| 403 | Missing scope, entitlement, permission or ownership authorization. |
| 404 | Missing resource, wrong-sport identifier, or unsupported sport endpoint. |
| 409 | Idempotency conflict; inspect the specific error code. |
| 419 | Browser CSRF/session mismatch; refresh CSRF/session state. |
| 422 | Validation failure; inspect field errors. |
| 429 | Rate limit reached; respect `Retry-After`. |
| 5xx | Server failure; retain the request ID for diagnosis. |

Successful empty collections are different from errors. Do not convert rejected
requests to an empty dataset. Response wrappers also differ: sport resources
usually have `data`/`meta`, login/device credentials are top-level, security
reports return `ok`, CSV export uses `text/csv`, and 204 responses have no JSON.

## Supported Sport Slugs

The v2 sport API is designed around these slugs:

```txt
nba, wnba, mlb, nfl, cbb, wcbb, cfb
```

Not every sport has the same provider coverage at every point in the season.
If a provider does not support a market, the API should return an empty or
warning-bearing contract instead of throwing provider-specific errors to Vue.

## App And Admin Routes

| Method | Path | Route name | Frontend client | Contract tests |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v2/sports` | `v2.sports.index` | `sports.index()` | `tests/Feature/Api/V2/SportMetadataEndpointTest.php` |
| `GET` | `/api/v2/sports/{sport}` | `v2.sports.show` | `sports.show()` | `tests/Feature/Api/V2/SportMetadataEndpointTest.php` |
| `POST` | `/api/v2/auth/login` | `v2.auth.login` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `POST` | `/api/v2/auth/passkeys/options` | `v2.auth.passkeys.createOptions` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `POST` | `/api/v2/auth/passkeys/verify` | `v2.auth.passkeys.verify` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `GET` | `/api/v2/auth/me` | `v2.auth.me` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `POST` | `/api/v2/auth/logout` | `v2.auth.logout` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `POST` | `/api/v2/auth/logout-all` | `v2.auth.logout-all` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
| `GET` | `/api/v2/live-scoreboard` | `v2.live-scoreboard.show` | `liveScoreboard.show()` | `tests/Feature/Api/V2/LiveScoreboardEndpointContractTest.php` |
| `GET` | `/api/v2/user-bets` | `v2.user-bets.index` | `userBets.index()` | `tests/Feature/BetTrackerTest.php` |
| `POST` | `/api/v2/user-bets` | `v2.user-bets.store` | `userBets.store()` | `tests/Feature/BetTrackerTest.php` |
| `PUT` | `/api/v2/user-bets/{bet}` | `v2.user-bets.update` | `userBets.update()` | `tests/Feature/BetTrackerTest.php` |
| `DELETE` | `/api/v2/user-bets/{bet}` | `v2.user-bets.destroy` | `userBets.destroy()` | `tests/Feature/BetTrackerTest.php` |
| `GET` | `/api/v2/user-bets/export` | `v2.user-bets.export` | `userBets.export()` | `tests/Feature/BetTrackerTest.php` |
| `GET` | `/api/v2/cbb-brackets` | `v2.cbb-brackets.index` | `cbbBrackets.index()` | `tests/Feature/CbbBracketApiTest.php` |
| `POST` | `/api/v2/cbb-brackets` | `v2.cbb-brackets.store` | `cbbBrackets.store()` | `tests/Feature/CbbBracketApiTest.php` |
| `GET` | `/api/v2/cbb-brackets/current` | `v2.cbb-brackets.current.show` | `cbbBrackets.current()` | `tests/Feature/CbbBracketApiTest.php` |
| `PUT` | `/api/v2/cbb-brackets/current` | `v2.cbb-brackets.current.upsert` | `cbbBrackets.upsertCurrent()` | `tests/Feature/CbbBracketApiTest.php` |
| `GET` | `/api/v2/cbb-brackets/leaderboard` | `v2.cbb-brackets.leaderboard` | `cbbBrackets.leaderboard()` | `tests/Feature/CbbBracketApiTest.php` |
| `GET` | `/api/v2/cbb-brackets/{publicId}` | `v2.cbb-brackets.show` | `cbbBrackets.show()` | `tests/Feature/CbbBracketApiTest.php` |
| `PATCH` | `/api/v2/cbb-brackets/{publicId}` | `v2.cbb-brackets.update` | `cbbBrackets.update()` | `tests/Feature/CbbBracketApiTest.php` |
| `DELETE` | `/api/v2/cbb-brackets/{publicId}` | `v2.cbb-brackets.destroy` | `cbbBrackets.destroy()` | `tests/Feature/CbbBracketApiTest.php` |
| `GET` | `/api/v2/groups` | `v2.groups.index` | `groups.index()` | `tests/Feature/GroupApiTest.php` |
| `POST` | `/api/v2/groups` | `v2.groups.store` | `groups.store()` | `tests/Feature/GroupApiTest.php` |
| `PATCH` | `/api/v2/groups/{publicId}` | `v2.groups.update` | `groups.update()` | `tests/Feature/GroupApiTest.php` |
| `GET` | `/api/v2/alert-preferences` | `v2.alert-preferences.show` | `alertPreferences.show()` | `tests/Feature/Api/V2/AlertPreferenceApiTest.php` |
| `POST` | `/api/v2/alert-preferences` | `v2.alert-preferences.store` | `alertPreferences.store()` | `tests/Feature/Api/V2/AlertPreferenceApiTest.php` |
| `PUT` | `/api/v2/alert-preferences` | `v2.alert-preferences.update` | `alertPreferences.update()` | `tests/Feature/Api/V2/AlertPreferenceApiTest.php` |
| `GET` | `/api/v2/admin/payload-inspector` | `v2.admin.payload-inspector` | `admin.payloadInspector()` | `tests/Feature/Api/V2/Admin/PayloadInspectorTest.php` |
| `POST` | `/api/v2/auth/device-sessions` | `v2.auth.device-sessions.store` | native clients | `tests/Feature/Auth/NativeDeviceSessionApiTest.php` |
| `POST` | `/api/v2/auth/device-sessions/refresh` | `v2.auth.device-sessions.refresh` | native clients | `tests/Feature/Auth/NativeDeviceSessionApiTest.php` |
| `DELETE` | `/api/v2/auth/device-sessions/{deviceSession}` | `v2.auth.device-sessions.destroy` | native clients | `tests/Feature/Auth/NativeDeviceSessionApiTest.php` |
| `POST` | `/api/v2/auth/device-sessions/{deviceSession}/push-registrations` | `v2.auth.device-sessions.push-registrations.store` | native clients | `tests/Feature/Auth/NativeDeviceSessionApiTest.php` |
| `DELETE` | `/api/v2/auth/device-sessions/{deviceSession}/push-registrations/{provider}` | `v2.auth.device-sessions.push-registrations.destroy` | native clients | `tests/Feature/Auth/NativeDeviceSessionApiTest.php` |
| `GET` | `/api/v2/developer/sandbox` | `v2.developer.sandbox.show` | developer clients | `tests/Feature/DeveloperPlatform/DeveloperApiEnforcementTest.php` |
| `POST` | `/api/v2/security/reports/csp` | `v2.security.reports.csp` | browser Reporting API | `tests/Feature/Api/V2/SecurityReportEndpointTest.php` |
| `POST` | `/api/v2/security/reports/integrity` | `v2.security.reports.integrity` | browser Reporting API | `tests/Feature/Api/V2/SecurityReportEndpointTest.php` |

## Sport Routes

All routes in this section live under `/api/v2/sports/{sport}` and require
`v2.auth` plus `v2.sport-api-access`. Capability and sport-specific restrictions
are described below; registration does not imply support for every sport.

| Method | Path | Route name | Frontend client | Contract tests |
| --- | --- | --- | --- | --- |
| `GET` | `/games` | `v2.sports.games.index` | `games.index()` | `tests/Feature/Api/V2/SportGameEndpointContractTest.php` |
| `GET` | `/games/{game}` | `v2.sports.games.show` | `games.show()` | `tests/Feature/Api/V2/SportGameEndpointContractTest.php` |
| `GET` | `/games/{game}/depth-charts` | `v2.sports.games.depth-charts.show` | `games.depthCharts()` | `tests/Feature/Api/V2/SportDepthChartEndpointContractTest.php` |
| `GET` | `/games/{game}/prediction` | `v2.sports.games.prediction.show` | `predictions.forGame()` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| `GET` | `/games/{game}/player-props` | `v2.sports.games.player-props.index` | `playerProps.forGame()` | `tests/Feature/Api/V2/SportPlayerPropEndpointContractTest.php` |
| `GET` | `/teams` | `v2.sports.teams.index` | `teams.index()` | `tests/Feature/Api/V2/SportTeamEndpointContractTest.php` |
| `GET` | `/teams/{team}` | `v2.sports.teams.show` | `teams.show()` | `tests/Feature/Api/V2/SportTeamEndpointContractTest.php` |
| `GET` | `/teams/{team}/futures` | `v2.sports.teams.futures.index` | `teams.futures()` | `tests/Feature/Api/V2/SportFuturesOddEndpointContractTest.php` |
| `GET` | `/teams/{team}/games` | `v2.sports.teams.games.index` | `teams.games()` | `tests/Feature/Api/V2/SportGameEndpointContractTest.php` |
| `GET` | `/teams/{team}/depth-charts` | `v2.sports.teams.depth-charts.show` | `teams.depthCharts()` | `tests/Feature/Api/V2/SportDepthChartEndpointContractTest.php` |
| `GET` | `/teams/{team}/metrics` | `v2.sports.teams.metrics.show` | `teams.metrics()` | `tests/Feature/Api/V2/SportTeamMetricEndpointContractTest.php` |
| `GET` | `/teams/{team}/players` | `v2.sports.teams.players.index` | `teams.players()` | `tests/Feature/Api/V2/SportPlayerEndpointContractTest.php` |
| `GET` | `/teams/{team}/stats/season-averages` | `v2.sports.teams.stats.season-averages.show` | `teams.statSeasonAverages()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/teams/{team}/trends` | `v2.sports.teams.trends.show` | `teams.trends()` | `tests/Feature/Api/V2/SportTeamEndpointContractTest.php` |
| `GET` | `/players` | `v2.sports.players.index` | `players.index()` | `tests/Feature/Api/V2/SportPlayerEndpointContractTest.php` |
| `GET` | `/players/{player}` | `v2.sports.players.show` | `players.show()` | `tests/Feature/Api/V2/SportPlayerEndpointContractTest.php` |
| `GET` | `/players/{player}/player-props` | `v2.sports.players.player-props.index` | `players.playerProps()` | `tests/Feature/Api/V2/SportPlayerPropEndpointContractTest.php` |
| `GET` | `/player-props` | `v2.sports.player-props.index` | `playerProps.index()` | `tests/Feature/Api/V2/SportPlayerPropEndpointContractTest.php` |
| `GET` | `/player-props/board` | `v2.sports.player-props.board` | `playerProps.board()` | `tests/Feature/Api/V2/SportPlayerPropEndpointContractTest.php` |
| `GET` | `/forecasts` | `v2.sports.forecasts.index` | `forecasts.index()` | `tests/Feature/Api/V2/SportForecastEndpointContractTest.php` |
| `GET` | `/injuries` | `v2.sports.injuries.index` | `injuries.index()` | `tests/Feature/Api/V2/SportInjuryEndpointContractTest.php` |
| `GET` | `/signals` | `v2.sports.signals.index` | `signals.index()` | `tests/Feature/Api/V2/SportSignalEndpointContractTest.php` |
| `GET` | `/predictions` | `v2.sports.predictions.index` | `predictions.index()` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| `GET` | `/predictions/available-seasons` | `v2.sports.predictions.available-seasons` | `predictions.availableSeasons()` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| `GET` | `/predictions/available-dates` | `v2.sports.predictions.available-dates` | `predictions.availableDates()` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| `GET` | `/predictions/{prediction}` | `v2.sports.predictions.show` | `predictions.show()` | `tests/Feature/Api/V2/SportPredictionEndpointContractTest.php` |
| `GET` | `/markets/futures` | `v2.sports.markets.futures.index` | `markets.futures()` | `tests/Feature/Api/V2/SportFuturesOddEndpointContractTest.php` |
| `GET` | `/markets/player-props` | `v2.sports.markets.player-props.index` | `markets.playerProps()` | `tests/Feature/Api/V2/SportPlayerPropEndpointContractTest.php` |
| `GET` | `/leaderboards/players/available-seasons` | `v2.sports.leaderboards.players.available-seasons` | `leaderboards.playerAvailableSeasons()` | `tests/Feature/Api/V2/SportPlayerLeaderboardEndpointContractTest.php` |
| `GET` | `/leaderboards/players` | `v2.sports.leaderboards.players.index` | `leaderboards.players()` | `tests/Feature/Api/V2/SportPlayerLeaderboardEndpointContractTest.php` |
| `GET` | `/metrics/teams/available-seasons` | `v2.sports.metrics.teams.available-seasons` | `metrics.teamAvailableSeasons()` | `tests/Feature/Api/V2/SportTeamMetricEndpointContractTest.php` |
| `GET` | `/metrics/teams` | `v2.sports.metrics.teams.index` | `metrics.teams()` | `tests/Feature/Api/V2/SportTeamMetricEndpointContractTest.php` |
| `GET` | `/stats/player/available-seasons` | `v2.sports.stats.player.available-seasons` | `stats.playerAvailableSeasons()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/player/available-dates` | `v2.sports.stats.player.available-dates` | `stats.playerAvailableDates()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/player` | `v2.sports.stats.player.index` | `stats.players()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/team/available-seasons` | `v2.sports.stats.team.available-seasons` | `stats.teamAvailableSeasons()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/team/available-dates` | `v2.sports.stats.team.available-dates` | `stats.teamAvailableDates()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/team/season-averages` | `v2.sports.stats.team.season-averages.index` | `stats.teamSeasonAverages()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/stats/team` | `v2.sports.stats.team.index` | `stats.teams()` | `tests/Feature/Api/V2/SportStatsEndpointContractTest.php` |
| `GET` | `/games/{game}/page` | `v2.sports.games.page.show` | `games.page()` | `tests/Feature/Api/V2/SportDepthChartEndpointContractTest.php` |
| `GET` | `/games/{game}/trends` | `v2.sports.games.trends.show` | `games.trends()` | `tests/Feature/Api/V2/SportTeamTrendEndpointContractTest.php` |
| `GET` | `/games/{game}/research` | `v2.sports.games.research.show` | `games.research()` | `tests/Feature/Api/V2/NflResearchEndpointTest.php` |
| `GET` | `/games/{game}/live-snapshot` | `v2.sports.games.live-snapshot.show` | `games.liveSnapshot()` | `tests/Feature/Api/V2/NflLiveSnapshotTest.php` |
| `GET` | `/games/{game}/market-history` | `v2.sports.games.market-history.show` | NFL game component | `tests/Feature/NFL/NflHistoricalMarketEvidenceTest.php` |
| `GET` | `/games/{game}/matchup-signals` | `v2.sports.games.matchup-signals.show` | NFL game component | `tests/Feature/Api/V2/NflMatchupSignalEndpointTest.php` |
| `GET` | `/games/{game}/live-betting` | `v2.sports.games.live-betting.show` | CFB game component | `tests/Feature/CFB/LiveBettingTest.php` |
| `GET` | `/daily-picks` | `v2.sports.daily-picks.index` | `dailyPicks.index()` | `tests/Feature/MLB/MlbDailyPickEngineTest.php` |

CBB team-player reads select the latest season-specific roster observation known now. `season` is an optional ending-year filter (default current basketball season); `/players?season=...` also requires `team_id`. The collection and player fields stay unchanged, including historical team/jersey/position from the selected observation. `meta.roster` reports season, observation ID/time and availability; missing rosters return an empty collection with `season_roster_unavailable`, while stale observations are labeled. The mutable player team pointer is not a fallback roster. Other sports retain their existing player-list behavior.

## Common Filters

| Endpoint family | Supported query filters |
| --- | --- |
| Games | `status`, `completed_only`, `season`, `season_type`, `from_date`, `to_date`, `before_game_at`, `exclude_game_id`, `per_page` |
| Predictions | `season`, `season_type`, `week`, `from_date`, `to_date`, `status`, `team_id`, `game_id`, `include`, `market`, `per_page` |
| Teams | `conference`, `division`, `league`, `search`, `per_page` |
| Players | `team_id`, `position`, `status`, `search`, `per_page` |
| Team metrics | `season`, `season_type`, `team_id`, `per_page` |
| Team depth charts | `season` (1–9999), `season_type` (configured/supported phase name or code; no `all`), `before_date` |
| Player/team stats | `season`, `season_type`, `week`, `from_date`, `to_date`, `game_id`, `team_id`, `player_id`, `stat_type`, `team_type`, `per_page` |
| Team stat averages | `season` (1–9999), `season_type` (configured phase/code/name or explicit `all`) |
| Team trends | `games`, `season`, `season_type`, `before_date` |
| Player props | `date`, `from_date`, `to_date`, `game_id`, `player_id`, `market`, `bookmaker`, `recommended_side`, `per_page` |
| Futures odds | `season`, `market_key`, `bookmaker`, `team_id`, `player_id`, `event_id`, `outcome_name`, `per_page` |
| Forecasts | `season`, `as_of_date`, `require_historical_metrics`, `sort_by`, `sort_direction` |
| Signals | `season`, `as_of_date` |
| Injuries | `active`, `actionable` (`0` or `1`), `team_id`, `status`, `limit` (1–500) |
| Player leaderboards | `season`, `season_type`, `stat_type`, `min_games`, `focus_player_id` |
| MLB daily picks | `date`, `season`, `game_id`, `compact`, `limit` |

Depth-chart team scope is validated before queries. Phase aliases resolve to a canonical code. An omitted season preserves the existing calendar-year fallback; omitted phase remains unfiltered within that season. The game-specific route derives its stat scope from the stored game and accepts no query filters. `before_date` is an exclusive stored game calendar-date cutoff for stat samples, not proof that a mutable depth listing existed at kickoff. Listings are stored context without a verified provider freshness claim. An unresolved side may have `team: null` and empty entries, while the known side remains available. Stat metric `sample_size` reports its actual field or paired-rate observations; all-missing values display `-`, genuine zero is retained, and rates use their matching observations. Stored numeric IP is explicitly labeled decimal innings; no legacy outs-notation reinterpretation is performed.

Team stat averages cover completed games only in the selected phase. An omitted season resolves to the latest stored completed season within that scope, not an assumed current season; an omitted phase uses the configured default. `meta.scope` reports both resolutions. An explicit empty scope never broadens to other history. Per-field `sample_sizes` distinguish missing observations from genuine zero; shooting percentages use paired made/attempted observations and expose `shooting_totals`. The team-specific empty scope returns 404 with its scope metadata, while the league collection returns an empty array.

Injury observation freshness is nullable for legacy projection clocks. `meta.freshness.source_clock_status` distinguishes `unverified_storage_clock` from `no_matching_observations`; neither proves fresh or complete provider coverage. `is_stale`, `stale_rows`, `age_hours`, `last_observed_at` and `latest_source_updated_at` remain `null` until a qualified, currently bound observation can establish them. `unverified_rows` counts the returned unqualified records. `last_stored_at` is only a raw local database mutation marker for the included rows, and raw `data[].source_updated_at` remains clearly labeled stored text with unverified timezone. A local edit or a fresh row on another team cannot establish freshness. The 48-hour policy is retained for a future verified observation adapter; this read repair does not implement that adapter or certify an empty result as a successful complete sync. See [the required provenance plan](production-readiness/injury-observation-provenance-plan-2026-11-26.md).

Use `page` for Laravel-paginated collections. Date ranges use `YYYY-MM-DD`
and require `to_date >= from_date`. Business date windows use
`config(sports.business_timezone)`; timestamps in responses retain their stated
offset. `/daily-picks` is MLB-only and returns a custom board rather than a
paginated collection. Its `summary.pregame_safe_rate` and
`board_health.pregame_safe_rate` are nullable historical stored signal assessments,
not current source/safety revalidation: no candidates or any unknown included
assessment yields `null`. Compact projections omit the detailed stored assessment, so they remain unknown rather than inferring safety from absent flags. The UI labels this Stored Safety Assessment. Candidate
`game.starts_at` is an offset-bearing observed kickoff bound to the current game,
or `null` for unmarked/invalid legacy clocks. Candidate `game.game_date` remains a
serialized stored calendar date and must not be interpreted as kickoff. Genuine
zero hit rates and complete observed zero inning scores remain zero; incomplete
inning scores are unavailable. Availability routes return available dates/seasons rather
than the full resources. Numeric IDs are required unless an endpoint explicitly
supports a sport-event public ID; do not assume public-ID support for every
`{team}`, `{player}`, `{prediction}` or game subresource.

Filter validation belongs in `app/Http/Requests/Api/V2`. If a new filter is
added to a query class, add it to the request class, this document, and the
matching contract test.

## Standard Payload Contracts

### Sport Collection

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

### Sport Item

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

### Compatibility App Payloads

Some v2 app routes intentionally preserve legacy payload wrappers while Vue is
being migrated:

- `/api/v2/user-bets`
- `/api/v2/cbb-brackets`
- `/api/v2/groups`
- `/api/v2/alert-preferences`

Do not reshape these without changing the Vue consumer and tests in the same
change.

## NFL API Contract

NFL uses the shared `/api/v2/sports/{sport}` route family with `sport=nfl`.
The current source of truth is:

- Route registration: `routes/api-v2.php`.
- Capability map: `config/sports.php` under `domains.nfl`.
- V2 context and serializers: `app/Services/Api/V2` and
  `app/Http/Resources/Api/V2`.
- NFL domain models/resources: `app/Models/NFL` and
  `app/Http/Resources/NFL`.
- Contract tests: `tests/Feature/Api/V2`.

### NFL Supported Surface

NFL supports these shared v2 surfaces:

| Surface | Endpoint family | Notes |
| --- | --- | --- |
| Games | `/api/v2/sports/nfl/games` | Filter by `season`, `season_type`, status and date windows; use `/teams/{team}/games` for a team. |
| Teams | `/api/v2/sports/nfl/teams` | Conference and division filters should map to NFL alignment. |
| Players | `/api/v2/sports/nfl/players` | Supports team, position, status, search, and pagination. |
| Predictions | `/api/v2/sports/nfl/predictions` | Includes confidence context, prediction outputs, market summary, grading fields, and live fields when present. |
| Team metrics | `/api/v2/sports/nfl/metrics/teams` | Uses NFL team metric calculations and derived record context. |
| Team stats | `/api/v2/sports/nfl/stats/team` | Includes game/team stat rows and season-average endpoint. |
| Player stats | `/api/v2/sports/nfl/stats/player` | Includes player stat rows and available date/season endpoints. |
| Player leaderboards | `/api/v2/sports/nfl/leaderboards/players` | Defaults to `min_games=4` unless overridden. |
| Injuries | `/api/v2/sports/nfl/injuries` | Should return active injury context when in season; offseason gaps should be explicit. |
| Depth charts | `/api/v2/sports/nfl/games/{game}/depth-charts` and `/teams/{team}/depth-charts` | NFL has depth-chart capability enabled. |
| Player props | `/api/v2/sports/nfl/player-props`, `/markets/player-props`, `/player-props/board` | Provider coverage may be empty outside market windows. Empty contracts should not throw provider errors. |
| Futures odds | `/api/v2/sports/nfl/markets/futures` and `/teams/{team}/futures` | Supports team and player futures where provider rows exist. |
| Forecasts | `/api/v2/sports/nfl/forecasts` | Uses `TeamPlayoffForecastService`; supports playoff, conference, and Super Bowl probability fields. |
| Signals | `/api/v2/sports/nfl/signals` | Uses `NflBettingSignalService`; returns model/market signal groups rather than raw predictions only. |

### NFL-Specific Payload Expectations

NFL prediction payloads should keep the standard v2 fields:

- `game`, `pick`, `projection`, `home_win_probability`,
  `away_win_probability`, `predicted_spread`, `predicted_total`,
  `confidence_score`, `confidence_level`, `confidence_context`,
  `market_summary`, grading fields, and freshness metadata.
- `confidence_score` is the raw model score. UI and publishing logic should
  prefer `confidence_context.label` and `confidence_context.reason_codes`
  before treating a prediction as high quality.
- `depth_chart_context` may appear when injury/depth chart weighting is
  available. Missing depth-chart context should be visible as missing or stale
  operational data, not silently promoted as a clean signal.
- Live fields such as `live_predicted_spread`, `live_predicted_total`,
  `live_win_probability`, and `live_updated_at` are nullable and should only be
  interpreted for live games.

NFL team metrics and leaderboards should expose football-appropriate fields
rather than basketball/baseball aliases. Use the API resources and contract
tests as the schema authority, then verify production payloads through the
payload inspector before changing Vue.

### NFL Offseason And Provider Behavior

NFL has a long offseason. During offseason or pre-schedule windows:

- `games`, `predictions`, `stats`, `props`, `signals`, and weather-dependent
  outputs may return empty `data` arrays with normal `meta` instead of errors.
- Team stats are not expected until completed games exist for the selected
  season.
- Futures may be present before games begin if provider markets exist.
- Injuries and depth charts can be stale or unavailable depending on provider
  coverage. Validation should reflect that stage context rather than blocking
  every offseason page by default.
- Player props should be treated as market-window data. Missing props before
  books post lines should produce warnings or empty payloads, not broken Vue.

### NFL Validation Commands

Use these when validating the API/data path locally or on production:

```bash
php8.5 artisan sports:operations-sentinel --sport=nfl --season=2026 --validate-only --skip-ai-review
php8.5 artisan healthcheck:validate-data --sport=nfl
php8.5 artisan operations:ai-review --sport=nfl --season=2026 --date=2026-06-14
```

Use these when repairing the data path during an active NFL season:

```bash
php8.5 artisan sports:operations-sentinel --sport=nfl --season=2026 --repair --ai
php8.5 artisan espn:sync-nfl-current --skip-teams
php8.5 artisan espn:sync-nfl-games-scoreboard
php8.5 artisan espn:sync-nfl-game-details
php8.5 artisan espn:sync-nfl-depth-charts --season=2026
php8.5 artisan espn:sync-nfl-injuries
php8.5 artisan nfl:sync-game-weather --season=2026 --days-back=0 --days-forward=7 --force
php8.5 artisan nfl:sync-odds
php8.5 artisan nfl:sync-player-props
php8.5 artisan sports:sync-futures-odds --sport=nfl --season=2026
```

After any repair pass, rerun validation and inspect representative v2 payloads:

```txt
/api/v2/admin/payload-inspector?profile=sport-predictions&sport=nfl
/api/v2/admin/payload-inspector?profile=player-props&sport=nfl
/api/v2/admin/payload-inspector?profile=dashboard&sport=nfl
```

### NFL Contract Coverage Checklist

Before changing NFL API behavior, update or add tests for the relevant shared
contract:

- `SportGameEndpointContractTest`
- `SportPredictionEndpointContractTest`
- `SportTeamMetricEndpointContractTest`
- `SportStatsEndpointContractTest`
- `SportDepthChartEndpointContractTest`
- `SportPlayerPropEndpointContractTest`
- `SportFuturesOddEndpointContractTest`
- `SportSignalEndpointContractTest`
- `SportForecastEndpointContractTest`
- `SportPlayerLeaderboardEndpointContractTest`

The API should remain Laravel-first and Vue-friendly: keep query behavior in
query/services, keep resources as serializers, avoid hard-coded Vue fetch URLs,
and document any NFL-specific field or provider caveat in this section.

## Signals Contract

`GET /api/v2/sports/{sport}/signals` returns betting signals for the selected
sport. MLB currently includes pregame signals plus a live-monitoring surface.

The MLB live signal array is operational, not an official-bet feed. It exists
to explain live movement and identify monitoring situations without replacing
pregame predictions.

Expected MLB live signal fields:

```json
{
  "type": "live_movement",
  "game_id": 123,
  "game_date": "2026-06-14T00:00:00.000000Z",
  "matchup": "NYY @ BOS",
  "status": "STATUS_IN_PROGRESS",
  "inning": 5,
  "inning_state": "Top 5th",
  "home_score": 3,
  "away_score": 2,
  "pick_side": "home",
  "team_id": 10,
  "team_name": "Boston Red Sox",
  "pregame_win_probability": 0.54,
  "live_win_probability": 0.63,
  "live_probability_delta": 0.09,
  "live_predicted_spread": -1.2,
  "live_predicted_total": 8.7,
  "live_outs_remaining": 12,
  "live_updated_at": "2026-06-14T20:10:00.000000Z",
  "is_stale": false,
  "signal": "watch",
  "reason_codes": [],
  "risk_flags": []
}
```

Live signal freshness is validated by the healthcheck layer. Stale or missing
live predictions for in-progress games should degrade operational trust, but
they should not be confused with pregame model freshness.

## Production Validation

Route inventory:

```bash
php artisan route:list --path=api/v2
```

Regenerate the OpenAPI artifact:

```bash
php artisan api:v2-openapi-generate
```

Contract tests:

```bash
php artisan test tests/Feature/Api/V2
```

Frontend build:

```bash
npm run build
```

Vue hard-coded product API scan:

```bash
rg -n "api/v1|axios\\.|fetch\\(" resources/js --glob '!routes/**' --glob '!actions/**'
```

Admin payload inspector examples:

```txt
/api/v2/admin/payload-inspector?profile=dashboard&sport=mlb
/api/v2/admin/payload-inspector?profile=live-scoreboard
/api/v2/admin/payload-inspector?profile=sport-predictions&sport=mlb
/api/v2/admin/payload-inspector?profile=player-props&sport=mlb
/api/v2/admin/payload-inspector?profile=admin-healthcheck-cards&sport=mlb
/api/v2/admin/payload-inspector?profile=user-bets&include_payload=true
```

## Maintenance Rules

- Add new route documentation in the same change as the route.
- Add or update `useApiV2Client.ts` in the same change as a Vue-facing route.
- Add a Feature contract test under `tests/Feature/Api/V2` for every new
  Vue-facing route.
- Keep controllers thin. Query logic belongs in service/query classes.
- Keep resources serialization-only.
- Keep date and timezone behavior centralized.
- Keep provider gaps explicit in `meta.warnings` or validation findings.

## Contract Limits

- The OpenAPI artifact includes named payload schemas, but sport-dependent
  aggregates remain extensible. Resources and contract tests define those fields.
- Some generated query schemas are broader or less specific than request
  validation. For example, games and team metrics accept `per_page` up to 500.
  Use the filters and sport restrictions in this reference with the request classes.
- Some app-level routes are v2 aliases over legacy-compatible payload shapes.
- Provider coverage differs by sport and market, especially for college sports
  and futures/player-prop markets.
- The documentation does not replace production payload inspection. Use the
  payload inspector and contract tests when debugging frontend/API mismatches.


## Specialized game endpoints (updated 2026-09-26)

These routes require V2 authentication and the sport API entitlement checks.
Unsupported sports return 404. All paths are relative to `/api/v2`.

| GET path | Sport | Query parameters | Response |
| --- | --- | --- | --- |
| `/sports/{sport}/games/{game}/page` | MLB | None | Composite `data` with game, prediction, recent games, metrics and matchup context; `meta` |
| `/sports/{sport}/games/{game}/trends` | MLB | `games` (defaults to `season`); season, season type and cutoff are derived from the game | `data.home`, `data.away`, and `meta` |
| `/sports/{sport}/games/{game}/research` | NFL | None | Latest 20 revisions in `data.revisions`, `data.game_id`, and `meta`; requires NFL, spread, win-probability and betting-value permissions |
| `/sports/{sport}/games/{game}/live-betting` | CFB | None | Nullable latest snapshot in `data`, up to 25 snapshots in `history`, and `meta` |
| `/sports/{sport}/games/{game}/live-snapshot` | NFL | None | `data` with game state, nullable provisional projection and limitations; `source_updated_at` is null with `source_clock_status=observation_unavailable`; `record_updated_at` is null; `record_updated_at_raw` is local mutation text with `record_clock_status=unverified_storage_clock`, not a successful provider sync; no `meta`; private/no-store |
| `/sports/{sport}/games/{game}/market-history` | NFL | `since` (2009 through game season), optional `home_line` (-60 to 60 in half-point increments) | Historical market evidence in `data`, plus `meta` |
| `/sports/{sport}/games/{game}/matchup-signals` | NFL | `window`: `season_to_date` (default) or `previous_season` | Independent matchup and situational evidence in `data`, plus `meta` |

CFB live betting requires a numeric game ID. The four NFL endpoints and MLB page/trends endpoints accept a
numeric game ID or a sport-event public ID belonging to the selected sport. Canonical
`/sports/{sport}/games/{game}/prediction` lookup also resolves the game before
reading its prediction; malformed, nonexistent, and wrong-sport IDs return 404.

The generated OpenAPI artifact includes named contracts for these endpoints.
Run `php artisan api:v2-openapi-generate` after changing their routes or schemas.


## V1 retirement and final application migrations (2026-09-25)

V1 is removed; there are 84 V2 route entries and no V1 routes. The final
application callers use:

- `GET /api/v2/sports/nfl/games/{game}/research`: numeric game ID or NFL
  sport-event public ID; latest 20 research revisions in `data.revisions`, with
  `data.game_id` and `meta`. V2 authentication, API/sport entitlement and existing
  spread, win-probability and betting-value permission checks apply.
- `POST /api/v2/security/reports/csp` and `/api/v2/security/reports/integrity`:
  public, throttled, CSRF-exempt browser-report ingestion; returns `{"ok":true}`.

The application Reporting-Endpoints header points to the new report URLs.
No compatibility redirects or V1 logging commands remain.

### Standalone Vue browser contracts

`GET /api/v2/auth/context` requires the existing V2 authentication middleware and
returns `data.name`, `data.auth.user` and `data.subscription`. It never returns
session tokens, passwords or two-factor secrets and is private/no-store.
Standalone browser login uses Fortify cookie sessions and CSRF, not token login.

`GET /api/v2/sports/nfl/games/{game}/page` returns `data.game`,
`data.prediction` (nullable), and `data.team_stats`. Prediction fields use the
same subscription gates and canonical-reader selection as `/prediction`.
Supplemental team evidence, research, depth charts, independent analysis and
market history remain separate requests, listed in `meta.deferred`. MLB retains
its existing composite page contract.

Basketball player leaderboards include `three_pointers_made_per_game`, rounded to one decimal over non-null `three_point_made` game records in the selected season and season type. It is null when all qualifying records lack that stat; recorded zeroes are included. The CBB player stats view displays and sorts this field as `3PM/G`.

Game list and team-game list filters apply before pagination (`per_page` 1–500). `completed_only=true` selects stored final/full-time statuses; `before_game_at` is exclusive. Prefer an offset-bearing ISO timestamp for cutoff queries. Game `starts_at` is nullable: the six audited UTC-storage sports derive it only from a valid stored clock; MLB requires its observed-start provenance contract. Unknown or unverified clocks remain unavailable. Prediction `spread_convention` distinguishes `home_margin` (positive favors home) from `home_handicap` (negative favors home); clients must convert once before displaying or saving a pick.

Basketball leaderboard averages use only non-null observations for each field, with counts in `field_samples`; an all-missing field is `null`, not zero. `shooting_samples` counts matched made/attempt pairs used for each percentage. `minutes_per_game` excludes missing or malformed minutes and reports `minutes_samples`. The box-score EPA proxy is only available when `complete_box_score_games` equals `games_played`; EPA/36 also requires a complete matching minutes cohort and a positive minutes denominator. Partial records remain available as independent stored field averages. These are read-time completeness rules, not model calibration, provider coverage or training changes. Existing proxy weights and fully observed arithmetic are unchanged.

### Live scoreboard date and kickoff semantics

`GET /api/v2/live-scoreboard` consumes no caller date-range filters. It returns accessible live/final predictions for the configured sports business date (`sports.business_timezone`), with the existing 24-row display limit. Verified starts are matched to that date's UTC bounds. MLB uses its authoritative observed-start epoch; wholly unmarked legacy MLB rows may remain calendar inventory with `game_time: null`. NFL missing-clock rows likewise remain date-only when supported by the existing clock classifier. A known UTC midnight remains a real instant, not a missing-clock default. Canonical selection retains its publication, input, release and current MLB source-binding gates; event-wall storage is not compared as UTC. Response kickoff fields use the current authoritative game start or null.

For the extra old NFL midnight-carrier candidates only, stored clock metadata must fit a 65,536-byte SQL preflight before classification; oversized extra candidates are not expanded into the slate. This is not a global JSON/body coverage guarantee. `updated_at` remains the cached payload assembly timestamp, not a provider synchronization time.

For NFL prediction detail payloads, `game.kickoff_at` follows the same current strict stored-start classification as `game.starts_at`, in both legacy and canonical reads. Null clocks and identified old imported missing-clock carriers remain null; an explicit real midnight remains a timestamp. The legacy board's `can_retry_research` affordance additionally requires a known future kickoff and the existing administrator/status conditions. This read affordance is not certification of the separate research mutation endpoint.

Player-prop recommendation boards withhold rows whose stored line is missing or nonfinite; a real line of zero remains valid. The underlying raw quote, statistics and stored assessment are retained. The raw `/player-props` collection still returns a missing line as null. A board processing-policy cache namespace prevents older false-zero responses from being reused without purging historical cache entries.

NFL prop date options and single-date filters use the same strict current clock classification as their card labels. Identified old imported missing-midnight carriers remain on their stored calendar date with `Time TBD`; a real UTC midnight uses its actual business date. The read adapter projects at most 65,536 metadata bytes per row in small chunks before model JSON casts. If a possible imported midnight cannot be classified within that processing budget, its clock remains unavailable rather than being invented. This inventory adapter does not create a source observation, model approval or transactional source-admission receipt, and does not certify every other JSON hydration path.


Embedded active injury observations on player detail and NFL game-page team payloads expose `source_updated_at: null`, `source_updated_at_raw` (exact stored legacy text or null), and `source_clock_status: unverified_storage_clock`. These rows lack a qualified observation clock; local `updated_at` is a record mutation time, not a successful provider refresh. Clinical injury/return DATE values remain calendar dates. Empty or unloaded injury coverage does not establish a fresh report. No legacy timestamp conversion or backfill is performed.
