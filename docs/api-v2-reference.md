# API V2 Reference

Last reviewed: 2026-06-14.

This is the current human and agent reference for `/api/v2`. It complements
`docs/api-v2-modernization-plan.md` and
`docs/api-v2-contracts-and-retirement.md` by listing the actual route surface,
expected filters, frontend client ownership, and contract-test coverage.

The generated OpenAPI artifact lives in `docs/openapi-v2.json`. This reference
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
  classes cap `per_page` at 100 unless a route intentionally returns a custom
  board or availability payload.
- Date filters must use the shared backend date-window helpers. Do not compare
  user-selected dates directly against raw UTC timestamps in controllers or
  Vue.

## Authentication And Access

| Route family | Middleware | Notes |
| --- | --- | --- |
| `GET /api/v2/sports` and `GET /api/v2/sports/{sport}` | none | Public sport metadata. |
| `POST /api/v2/auth/login` | `throttle:10,1` | Token login. |
| `POST /api/v2/auth/passkeys/options` | `throttle:20,1` | Passkey challenge options. |
| `POST /api/v2/auth/passkeys/verify` | `throttle:10,1` | Passkey verification. |
| `GET/POST /api/v2/auth/me|logout|logout-all` | `auth:sanctum` | Token session management. |
| App-level product routes | `auth:sanctum` | Live scoreboard, user bets, brackets, groups, alert preferences. |
| Admin inspector | `auth:sanctum`, `admin` | Production payload debugging. |
| Sport data routes | `auth:sanctum`, `v2.sport-api-access` | Games, teams, players, predictions, stats, markets, signals, and forecasts. |

Access rules should stay in middleware, policies, request classes, and
dedicated access services. Controllers and resources should not duplicate tier
or permission checks.

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
| `POST` | `/api/v2/auth/passkeys/options` | `v2.auth.passkeys.options` | auth clients | `tests/Feature/Api/V2/AuthEndpointAliasTest.php` |
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

## Sport Routes

All routes in this section live under `/api/v2/sports/{sport}` and require
`auth:sanctum` plus `v2.sport-api-access`.

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

## Common Filters

| Endpoint family | Supported query filters |
| --- | --- |
| Games | `status`, `season`, `from_date`, `to_date`, `per_page` |
| Predictions | `season`, `season_type`, `week`, `from_date`, `to_date`, `status`, `team_id`, `game_id`, `include`, `market`, `per_page` |
| Teams | `conference`, `division`, `league`, `search`, `per_page` |
| Players | `team_id`, `position`, `status`, `search`, `per_page` |
| Team metrics | `season`, `season_type`, `team_id`, `per_page` |
| Player/team stats | `season`, `season_type`, `week`, `from_date`, `to_date`, `game_id`, `team_id`, `player_id`, `stat_type`, `team_type`, `per_page` |
| Team stat averages | `season`, `season_type`, `team_id`, `per_page` |
| Team trends | `games`, `season`, `season_type`, `before_date` |
| Player props | `date`, `from_date`, `to_date`, `game_id`, `player_id`, `market`, `bookmaker`, `recommended_side`, `per_page` |
| Futures odds | `season`, `market_key`, `bookmaker`, `team_id`, `player_id`, `event_id`, `outcome_name`, `per_page` |
| Forecasts | `season`, `as_of_date` |
| Signals | `season`, `as_of_date` |
| Injuries | `active`, `team_id`, `status` |
| Player leaderboards | `season`, `season_type`, `stat_type`, `min_games` |

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

## Known Gaps

- `docs/openapi-v2.json` is route-level. Detailed payload fields still live in
  API resources and contract tests.
- Some app-level routes are v2 aliases over legacy-compatible payload shapes.
- Provider coverage differs by sport and market, especially for college sports
  and futures/player-prop markets.
- The documentation does not replace production payload inspection. Use the
  payload inspector and contract tests when debugging frontend/API mismatches.
