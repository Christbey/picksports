# API V2 Endpoint Contracts

Last reviewed: 2026-09-26. All 84 registered route entries are listed below; GET also supports HEAD.

Read the [API reference](api-v2-reference.md) for authentication, supported sports, filters, examples and errors. Schema names resolve under `components.schemas` in [OpenAPI](openapi-v2.json). These are schema identifiers, not literal JSON wrapper names. Extensible sport fields are defined in the resources and contract tests.

`Key` means the route supports an optional `Idempotency-Key`; it is not required. `Write limit` means the shared `api-v2-writes` limiter (default 60/minute/user). Other rate limits are described in the reference. A dash in the body column means no declared JSON body; 204 means an empty response.

This inventory is based on `php artisan route:list --path=api/v2 --json` and `php artisan api:v2-openapi-generate --stdout`. Update it whenever either route methods or named contracts change.

## Authentication and devices

| Method | Full path | JSON request schema | Success response schema | Write behavior |
| --- | --- | --- | --- | --- |
| `POST` | `/api/v2/auth/device-sessions` | `NativeDeviceSessionStoreRequest` | 201 `NativeDeviceTokenResponse` | Write limit |
| `POST` | `/api/v2/auth/device-sessions/refresh` | `NativeDeviceSessionRefreshRequest` | 200 `NativeDeviceTokenResponse` | — |
| `DELETE` | `/api/v2/auth/device-sessions/{deviceSession}` | — | 204 empty | Key, Write limit |
| `POST` | `/api/v2/auth/device-sessions/{deviceSession}/push-registrations` | `NativePushRegistrationStoreRequest` | 200 `NativePushRegistrationResponse`; 201 `NativePushRegistrationResponse` | Key, Write limit |
| `DELETE` | `/api/v2/auth/device-sessions/{deviceSession}/push-registrations/{provider}` | — | 204 empty | Key, Write limit |
| `POST` | `/api/v2/auth/login` | `LoginRequest` | 200 `TokenAuthResponse` | — |
| `POST` | `/api/v2/auth/logout` | — | 204 empty | Write limit |
| `POST` | `/api/v2/auth/logout-all` | — | 204 empty | Write limit |
| `GET` | `/api/v2/auth/me` | — | 200 `AuthUserResponse` | — |
| `POST` | `/api/v2/auth/passkeys/options` | `PasskeyAuthenticationOptionsRequest` | 200 `PasskeyAuthenticationOptionsResponse` | — |
| `POST` | `/api/v2/auth/passkeys/verify` | `PasskeyAuthenticationVerifyRequest` | 200 `TokenAuthResponse` | — |

## Application, metadata and reporting

| Method | Full path | JSON request schema | Success response schema | Write behavior |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v2/admin/payload-inspector` | — | 200 `PayloadInspectorResponse` | — |
| `GET` | `/api/v2/alert-preferences` | — | 200 `AlertPreferenceResponse` | — |
| `POST` | `/api/v2/alert-preferences` | `AlertPreferenceStoreRequest` | 200 `AlertPreferenceResponse`; 201 `AlertPreferenceResponse` | Key, Write limit |
| `PUT` | `/api/v2/alert-preferences` | `AlertPreferenceUpdateRequest` | 200 `AlertPreferenceResponse` | Key, Write limit |
| `GET` | `/api/v2/cbb-brackets` | — | 200 `CbbBracketCollectionResponse` | — |
| `POST` | `/api/v2/cbb-brackets` | `CbbBracketStoreRequest` | 201 `CbbBracketResponse` | Key, Write limit |
| `GET` | `/api/v2/cbb-brackets/current` | — | 200 `NullableCbbBracketResponse` | — |
| `PUT` | `/api/v2/cbb-brackets/current` | `CbbBracketUpsertRequest` | 200 `CbbBracketResponse`; 201 `CbbBracketResponse` | Key, Write limit |
| `GET` | `/api/v2/cbb-brackets/leaderboard` | — | 200 `CbbBracketLeaderboardResponse` | — |
| `DELETE` | `/api/v2/cbb-brackets/{publicId}` | — | 204 empty | Key, Write limit |
| `GET` | `/api/v2/cbb-brackets/{publicId}` | — | 200 `CbbBracketResponse` | — |
| `PATCH` | `/api/v2/cbb-brackets/{publicId}` | `CbbBracketUpdateRequest` | 200 `CbbBracketResponse` | Key, Write limit |
| `GET` | `/api/v2/developer/sandbox` | — | 200 `DeveloperSandboxResponse` | — |
| `GET` | `/api/v2/groups` | — | 200 `GroupCollectionResponse` | — |
| `POST` | `/api/v2/groups` | `GroupStoreRequest` | 201 `GroupResponse` | Key, Write limit |
| `PATCH` | `/api/v2/groups/{publicId}` | `GroupUpdateRequest` | 200 `GroupResponse` | Key, Write limit |
| `GET` | `/api/v2/live-scoreboard` | — | 200 `LiveScoreboardResponse` | — |
| `POST` | `/api/v2/security/reports/csp` | `SecurityReportRequest` | 200 `SecurityReportResponse` | — |
| `POST` | `/api/v2/security/reports/integrity` | `SecurityReportRequest` | 200 `SecurityReportResponse` | — |
| `GET` | `/api/v2/sports` | — | 200 `SportCatalogResponse` | — |
| `GET` | `/api/v2/sports/{sport}` | — | 200 `SportContextResponse` | — |
| `GET` | `/api/v2/user-bets` | — | 200 `UserBetIndexResponse` | — |
| `POST` | `/api/v2/user-bets` | `UserBetStoreRequest` | 201 `UserBetResponse` | Key, Write limit |
| `GET` | `/api/v2/user-bets/export` | — | 200 `UserBetCsvExport` | — |
| `DELETE` | `/api/v2/user-bets/{bet}` | — | 204 empty | Key, Write limit |
| `PUT` | `/api/v2/user-bets/{bet}` | `UserBetUpdateRequest` | 200 `UserBetResponse` | Key, Write limit |

## Sport data

| Method | Full path | JSON request schema | Success response schema | Write behavior |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v2/sports/{sport}/daily-picks` | — | 200 `MlbDailyPicksResponse` | — |
| `GET` | `/api/v2/sports/{sport}/forecasts` | — | 200 `SportForecastResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games` | — | 200 `SportGameCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}` | — | 200 `SportGameResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/depth-charts` | — | 200 `SportDepthChartResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/live-betting` | — | 200 `CfbLiveBettingResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/live-snapshot` | — | 200 `NflLiveSnapshotResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/market-history` | — | 200 `NflMarketHistoryResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/matchup-signals` | — | 200 `NflMatchupSignalsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/page` | — | 200 `SportGamePageResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/player-props` | — | 200 `SportPlayerPropCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/prediction` | — | 200 `SportPredictionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/research` | — | 200 `NflResearchResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/trends` | — | 200 `SportGameTrendsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/injuries` | — | 200 `SportInjuryCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/leaderboards/players` | — | 200 `SportPlayerLeaderboardResponse` | — |
| `GET` | `/api/v2/sports/{sport}/leaderboards/players/available-seasons` | — | 200 `SportAvailableSeasonsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/markets/futures` | — | 200 `SportFuturesOddCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/markets/player-props` | — | 200 `SportPlayerPropCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/metrics/teams` | — | 200 `SportTeamMetricCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/metrics/teams/available-seasons` | — | 200 `SportAvailableSeasonsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/player-props` | — | 200 `SportPlayerPropCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/player-props/board` | — | 200 `SportPlayerPropBoardResponse` | — |
| `GET` | `/api/v2/sports/{sport}/players` | — | 200 `SportPlayerCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/players/{player}` | — | 200 `SportPlayerResponse` | — |
| `GET` | `/api/v2/sports/{sport}/players/{player}/player-props` | — | 200 `SportPlayerPropCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/predictions` | — | 200 `SportPredictionCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/predictions/available-dates` | — | 200 `SportAvailableDatesResponse` | — |
| `GET` | `/api/v2/sports/{sport}/predictions/available-seasons` | — | 200 `SportAvailableSeasonsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/predictions/{prediction}` | — | 200 `SportPredictionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/signals` | — | 200 `SportSignalResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/player` | — | 200 `SportStatCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/player/available-dates` | — | 200 `SportAvailableDatesResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/player/available-seasons` | — | 200 `SportAvailableSeasonsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/team` | — | 200 `SportStatCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/team/available-dates` | — | 200 `SportAvailableDatesResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/team/available-seasons` | — | 200 `SportAvailableSeasonsResponse` | — |
| `GET` | `/api/v2/sports/{sport}/stats/team/season-averages` | — | 200 `SportTeamStatAverageCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams` | — | 200 `SportTeamCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}` | — | 200 `SportTeamResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/depth-charts` | — | 200 `SportDepthChartResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/futures` | — | 200 `SportFuturesOddCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/games` | — | 200 `SportGameCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/metrics` | — | 200 `SportTeamMetricResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/players` | — | 200 `SportPlayerCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/stats/season-averages` | — | 200 `SportTeamStatAverageResponse` | — |
| `GET` | `/api/v2/sports/{sport}/teams/{team}/trends` | — | 200 `SportTeamTrendResponse` | — |

## Request body field guide

Required means the schema requires the field when submitting that body. Passkey options and update bodies may themselves be optional. Nested fields, allowed values, lengths, conditional validation and nullability are in OpenAPI and the corresponding request/controller; a listed optional field is not valid with every value.

| Schema | Required fields | Optional top-level fields |
| --- | --- | --- |
| `AlertPreferenceStoreRequest` | `enabled`, `notification_types`, `minimum_edge`, `time_window_start`, `time_window_end`, `digest_mode` | `digest_time`, `daily_digest_subscribed`, `phone_number`, `sports` |
| `AlertPreferenceUpdateRequest` | — | `enabled`, `notification_types`, `minimum_edge`, `time_window_start`, `time_window_end`, `digest_mode`, `digest_time`, `daily_digest_subscribed`, `phone_number`, `sports` |
| `CbbBracketStoreRequest` | `season` | `name`, `group_id`, `picks` |
| `CbbBracketUpdateRequest` | — | `season`, `name`, `group_id`, `picks` |
| `CbbBracketUpsertRequest` | `season`, `picks` | `name`, `group_id` |
| `GroupStoreRequest` | `name` | `type`, `sport`, `season` |
| `GroupUpdateRequest` | — | `name` |
| `LoginRequest` | `email`, `password` | `device_name` |
| `NativeDeviceSessionRefreshRequest` | `refresh_token` | — |
| `NativeDeviceSessionStoreRequest` | `device_name`, `platform` | `device_identifier` |
| `NativePushRegistrationStoreRequest` | `provider`, `device_token` | `environment` |
| `PasskeyAuthenticationOptionsRequest` | — | `email` |
| `PasskeyAuthenticationVerifyRequest` | `challenge_id`, `credential_id`, `client_data_json`, `authenticator_data`, `signature` | `device_name` |
| `SecurityReportRequest` | — | — |
| `UserBetStoreRequest` | `bet_amount`, `odds`, `bet_type` | `selection_side`, `selection_label`, `line`, `notes`, `placed_at`, `prediction_id`, `prediction_sport`, `prediction_type` |
| `UserBetUpdateRequest` | — | `bet_amount`, `odds`, `bet_type`, `selection_side`, `selection_label`, `line`, `notes`, `placed_at`, `result`, `profit_loss`, `settled_at`, `prediction_type` |

`SecurityReportRequest` accepts a report object or an array of report objects. The browser generates these reports; the success response is `{"ok":true}`.

Native push registration can return **201** for a new registration or **200** for an existing one. Clients must accept both.
