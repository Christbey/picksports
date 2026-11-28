# API V2 Endpoint Contracts

Route table reconciled against local source on 2026-11-25: all 89 registered route entries are listed below; GET also supports HEAD. This count check is separate from complete route authorization/workflow certification.

Read the [API reference](api-v2-reference.md) for authentication, supported sports, filters, examples and errors. Schema names resolve under `components.schemas` in [OpenAPI](openapi-v2.json). These are schema identifiers, not literal JSON wrapper names. Extensible sport fields are defined in the resources and contract tests.

`Key` means the route supports an optional `Idempotency-Key`; it is not required. `Write limit` means the shared `api-v2-writes` limiter (default 60/minute/user). Other rate limits are described in the reference. A dash in the body column means no declared JSON body; 204 means an empty response.

This inventory is based on `php artisan route:list --path=api/v2 --json` and `php artisan api:v2-openapi-generate --stdout`. Update it whenever either route methods or named contracts change.

## Authentication and devices

| Method | Full path | JSON request schema | Success response schema | Write behavior |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v2/auth/context` | — | 200 `FrontendContextResponse` | — |
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
| `POST` | `/api/v2/admin/{sport}/games/{game}/analysis/retry` | — | 202 `GameAnalysisRetryResponse` | Admin only, Write limit |
| `GET` | `/api/v2/admin/nfl/games/{game}/research-retry` | — | 200 `NflResearchRetryResponse` | Admin only |
| `POST` | `/api/v2/admin/nfl/games/{game}/research-retry` | — | 202 `NflResearchRetryResponse` | Admin only, Write limit, per-game deduplication |
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

### Mutation and export details

Group creation commits the group and its owner membership in one database transaction. Keyed operations marked `Key` additionally serialize on a durable receipt and commit their local business writes and successful response together. They are restricted to 14 explicitly classified same-connection database operations; external side effects are not admitted. Incomplete legacy outcomes fail closed and are not expired or pruned. See the [atomic idempotency and cutover contract](production-readiness/api-idempotency-2026-11-25.md).

User-bet CSV exports contain only the authenticated user's bets. Formula-leading user text in selection labels, odds and notes is prefixed with an apostrophe for spreadsheet safety, including whitespace/control-prefixed formula markers. Plain numeric odds such as `-110` retain their existing representation. Stored values are unchanged.

The first-party JSON transport treats explicit HTTP 204 as no content. Other successful responses must use `application/json` or an application media type with a `+json` suffix and contain valid JSON; unexpected HTML, missing content types and malformed JSON raise `ApiProtocolError` with status, request ID and a stable reason code. CSV downloads use the export URL directly rather than the JSON transport.

## Sport data

MLB daily-pick `summary`/`board_health.pregame_safe_rate` is a nullable historical stored assessment, not current safety verification. Unknown included assessments keep it null. Candidate `game.starts_at` is nullable observed current-game kickoff; `game.game_date` is calendar storage, not an inferred instant. OpenAPI names these boundaries `MlbDailyStoredAssessment` and `MlbDailyPickGame`.

| Method | Full path | JSON request schema | Success response schema | Write behavior |
| --- | --- | --- | --- | --- |
| `GET` | `/api/v2/sports/{sport}/daily-picks` | — | 200 `MlbDailyPicksResponse` | — |
| `GET` | `/api/v2/sports/{sport}/forecasts` | — | 200 `SportForecastResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games` | — | 200 `SportGameCollectionResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}` | — | 200 `SportGameResponse` | — |
| `GET` | `/api/v2/sports/{sport}/games/{game}/analysis/research` | — | 200 `GameResearchSummaryResponse` | Admin only |
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
| `GET` | `/api/v2/sports/{sport}/player-props/board` | `date`, `game`, `market`, `limit`, `filters_only` | 200 `SportPlayerPropBoardResponse` | — |
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
| `LoginRequest` | `email`, `password` | `device_name`, `token_profile` |
| `NativeDeviceSessionRefreshRequest` | `refresh_token` | — |
| `NativeDeviceSessionStoreRequest` | `device_name`, `platform` | `device_identifier` |
| `NativePushRegistrationStoreRequest` | `provider`, `device_token` | `environment` |
| `PasskeyAuthenticationOptionsRequest` | — | `email` |
| `PasskeyAuthenticationVerifyRequest` | `challenge_id`, `credential_id`, `client_data_json`, `authenticator_data`, `signature` | `device_name`, `token_profile` |
| `SecurityReportRequest` | — | — |
| `UserBetStoreRequest` | `bet_amount`, `odds`, `bet_type` | `selection_side`, `selection_label`, `line`, `notes`, `placed_at`, `prediction_id`, `prediction_sport`, `prediction_type` |
| `UserBetUpdateRequest` | — | `bet_amount`, `odds`, `bet_type`, `selection_side`, `selection_label`, `line`, `notes`, `placed_at`, `result`, `profit_loss`, `settled_at`, `prediction_type` |

`SecurityReportRequest` accepts a report object or an array of report objects. The browser generates these reports; the success response is `{"ok":true}`.

Native push registration can return **201** for a new registration or **200** for an existing one. Clients must accept both.

CBB team-player collections additionally accept `season` (ending year, default current). The player-directory route requires `team_id` when selecting a roster season. Membership and season profile fields come from the latest known immutable roster observation; `meta.roster` identifies it and warnings distinguish missing/stale coverage. Other sports retain their existing player directory contracts.

## Prepared serialization contracts

NFL game list and detail representations expose the same nullable `starts_at` UTC timestamp when both stored game date and time are available. Display `game_date` and `game_time` retain their existing presentation timezone. Canonical summary mappers preserve missing moneyline probability and confidence as null; they do not substitute an unobserved 50 percent probability or spread confidence.

Game queries keep selected canonical forecasts separate from the sport model's legacy `prediction` relationship. A request-local selected-forecast result includes missing selections, so composite page readers reuse the gated selection without silently falling back or querying it again. Matchup context is an explicit response input and does not become a dirty database-model attribute.

Stats and team metrics use explicit per-sport public field lists in `SportPublicFields`; a new schema/model field is not automatically public. Stat values honor declared model casts. Team metric numeric coercion remains compatible with the existing API. Query column capabilities in `SportQuerySchema` are checked against migrated schema in tests and must be deliberately updated alongside applicable schema changes. Missing required tables fail visibly; unsupported sport capabilities return the declared unsupported response.

Prediction live fields and loaded injury impacts are prepared before resource serialization. Main page/list readers retain batch snapshot/stat/player preparation; callers of legacy injury resources must prepare the explicitly loaded graph through `PreparedInjuryImpacts`. This preparation does not load additional injury coverage. NFL legacy game resources accept a prepared live win estimate; standalone resources omit unavailable estimates rather than selecting a forecast during JSON serialization. Recommendation resources use supplied batch season summaries and make no single-prop summary query fallback.
## Shared game research summary and review

`GET /api/v2/sports/{sport}/games/{game}/analysis/research` is an additive, admin-only read endpoint for all seven supported sports. It accepts a sport-scoped legacy numeric game ID or canonical sport-event ULID. It retains API/sport access checks and the existing research prediction/spread/probability/betting-value disclosure permissions, including the established tier-bypass policy. Subscriber access remains denied even when subscription tiers are disabled. The existing NFL `/research` endpoint and its revision envelope remain unchanged.

The `data` envelope contains research `status`, separate `applicability`, stored assessment/expiry timestamps, `supporting`, `concerns`, neutral `facts`, `unresolved`, source references, and an allowlisted completed-game `season_evidence` sample. Findings retain team side and provenance. Missing evidence is unavailable or partial; a completed assessment never grants betting approval. The nested `review` distinguishes pending/current/outdated/expired/deferred/failed results. Its `summary` describes the effective deterministic decision, while `context_summary` is separately labeled AI interpretation. No AI confidence score is exposed as a probability. Raw documents, prompts, provider failures, fingerprints and internal metadata are excluded. Source links must be credential-free HTTPS URLs.

This GET never fetches providers, ingests sources, dispatches work, or regenerates predictions. Ordinary authentication activity bookkeeping is unchanged. The response's `capabilities.can_retry` is true only for a future scheduled/delayed game with an enabled research or review capability. Automatic dispatch settings are separate.

`POST /api/v2/admin/{sport}/games/{game}/analysis/retry` explicitly queues an authorized refresh and returns 202 with `data: { status: "queued", game_id, sport }`. It uses the same disclosure policy, the admin requirement, and write throttling. Existing NFL retry URLs remain supported. A queued response establishes dispatch, not completed research or a playable selection; subsequent read responses show saved results. Normal navigation does not invoke this mutation. Shared game pages mount the admin research disclosure lazily and use bounded read polling only after an explicit retry.

### Basketball leaderboard observation samples

`GET /api/v2/sports/{sport}/leaderboards/players` retains nullable basketball averages and optional `field_samples`, `shooting_samples`, `minutes_samples`, and `complete_box_score_games`. Missing observations are not measured zeroes. Shooting rates require matched stored numerator/denominator observations; the estimated box-score EPA and per36 proxy require complete, matching observed cohorts. See the API reference for the exact read-time rule. Other sport aggregate contracts and endpoint filters are unchanged.

### Live scoreboard calendar window

The no-query-date scoreboard contract is the configured business-date slate, not a rolling exact-instant lookback. Verified starts use UTC bounds for that business date; source-qualified MLB epochs take precedence over mixed venue civil fields. Date-only inventory may have a null kickoff and must not be rendered as guessed midnight. Canonical results retain existing publication/source checks and use the same game-date selection policy as legacy results. Known real midnight is preserved. The existing global 24-row limit is applied after candidate classification. See the API reference for the bounded extra NFL legacy-carrier metadata policy.

NFL live snapshots preserve a nullable `source_updated_at`, currently null because no qualified provider game-state observation is bound to this read. `source_clock_status=observation_unavailable` makes that limit explicit. `record_updated_at` is null. `record_updated_at_raw` preserves local game-record mutation text with `record_clock_status=unverified_storage_clock`; clients must not infer a timezone, instant or age from it. It does not certify provider freshness or a successful sync. Authentication, private/no-store behavior and provisional uncalibrated projection limitations are unchanged.

### CFB kickoff observations

CFB `starts_at` is available only when a bounded current ESPN observation contains a valid explicit-offset timestamp that matches the stored game date, time, and event identity. `provider_clock_known_v1` includes genuine UTC midnight. Missing observations are `legacy_clock_unverified`; invalid, mismatched, oversized, or explicitly unconfirmed observations are `clock_unconfirmed_or_invalid`. Stored date/time carriers are retained for compatibility and are not proof of a kickoff. No historical rows are backfilled as verified.

A same-event summary that explicitly reports an unconfirmed time records that state and clears the current canonical event start atomically, while retaining the stored date/time carriers and unrelated metadata. Verification still reports failure. Access, transport, or event-identity failures leave prior evidence unchanged. Later qualified observations can restore a known start.

Unknown current clocks withhold precise pregame admission and live quote comparisons. Stored history, score/statistic observations, and clock-independent final prop settlement remain available, including real zero values. Cached live baselines bind the physical game, provider event, nullable canonical event link, and UTC cutoff; a changed identity or a previously unavailable baseline is recomputed without rewriting old snapshots. New grading can wait for a known current clock; recorded evaluations and historical inputs are retained. This does not change model weights, calibration, or approval policy.

CFB prop-calendar inventory uses the known instant's America/Chicago date, or the raw stored date with an unverified label when no instant can be qualified. It does not guess a Central date from a missing clock. Qualification reads at most eight bounded clock headers at once, with a 1,024-byte observation cap. A day permits at most 1,000 candidate headers and matched IDs; available-date discovery permits 10,000 candidate headers and 1,000 distinct dates. Exceeding a limit refuses the operation instead of silently truncating its result. These are application read limits, not a production capacity certificate.

### CFB generic calendar windows and pregame clock recovery

The CFB generic games date range (`from_date` and `to_date` together), shared scoreboard date window, and explicit `before_game_at` filter use the bounded CFB calendar reader. Known, currently matching provider observations use their UTC instant. Unknown or invalid observations retain their validated raw date for date inventory; they do not qualify an exact before-game cutoff. Completed results and real zero scores remain accessible without that cutoff. A genuine observed `00:00:00` remains a known midnight. Existing single-sided raw-date filters keep their date-only contract; this does not change other sports' datetime parsers.

The calendar scan reads capped clock headers in chunks of eight without eager team relations: at most 10,000 candidates and 1,000 selected IDs for generic range/instant filtering. Overflow or a prelimited/grouped candidate query refuses rather than returning partial inventory. These are bounded current reads, not a historical cutoff or concurrent snapshot certificate.

CFB pregame metadata verification selects bounded scheduled/pre-game/delayed candidates by requested season or explicit game before using an unknown carrier to exclude a game from the future horizon. The standalone command defaults to the configured CFB season when neither season nor game is supplied, retains its maximum 200-call limit, and reports deferred work as non-success. The pipeline refuses more than 200 candidates before metadata IO. Dry runs do not perform verification. An invalid explicit game ID refuses before work rather than widening selection.

After verification the pipeline checks current CFB kickoff evidence and its current Event match again. Failed or still-unconfirmed verification records an `unreliable_kickoff_metadata` hold and returns non-success. A successfully verified kickoff outside the requested future horizon is explicitly reported as excluded, not mislabeled a metadata failure. Existing snapshots/history are not backfilled, and no prediction parameters, approval policies, or provider refresh schedules change.

CFB date-window qualification also supports correlated `whereHas` game predicates: the bounded reader scans an independent game-date-window superset, then adds its qualified IDs to the original caller query. Parent prediction filters, child game filters, publication and access predicates remain in the original query; no correlation clauses are stripped or executed outside their parent. Capacity is therefore bounded over the independent date-window superset and can refuse even when a caller's narrower outer scope is small. This does not promise filtered-capacity parity or a transactional snapshot across the qualification and final reads.


## November 27 private candidate: player-prop route selectors

The private candidate validates player-prop `{game}` and `{player}` route IDs through the same integer/minimum-one request policy as query IDs. The route identity is authoritative over a conflicting query ID. Malformed route selectors return the existing JSON validation response (422); a syntactically valid positive ID with no matching rows retains 200 with an empty list. This is an identifier contract, not permission to disclose other accounts or bypass the existing authentication/tier policy.

Omitting the selector retains the existing unscoped endpoint behavior. Direct query-service null/omitted ID filters remain unscoped, while an explicit zero stays restrictive rather than dropping its predicate. This does not add nullable HTTP ID support or change board/analyzer validation. Stored line zero, pagination, other filters and response fields remain unchanged. Private source-bound verification records are retained separately. This contract does not certify native concurrency or production deployment.

The private November27 futures-route repair validates raw team route selectors through the existing Form Request before integer conversion. Malformed zero, junk, partial, exponent and overflow selectors return422. A valid route team overrides a conflicting query team; a valid unknown positive team retains200 with an empty collection. Omitted/null direct-query filters remain absent; explicit zero direct-query entity filters remain restrictive. Scoped portable evidence is38tests/353assertions across the existing five-sport futures controls; this is not verification of every route or a native SQL coercion exploit.

The private post-ninth legacy game-prediction repair validates positive integer game identities before casting and keeps explicit zero query scope restrictive. Zero, junk, partial, exponent and overflow legacy route selectors return404 through the existing model-not-found channel; valid unknown positive IDs also remain404. Valid matching legacy reads preserve their prediction and game identity. The canonical read branch is unchanged. Scoped actual HTTP evidence covers the supported NFL legacy configuration plus the full58-test endpoint cohort; this does not close all route/engine identity validation.

The private post-ninth generated-contract repair keeps queued research-retry fields optional in closed objects and includes its actual422 response; adds optional CFB prop `is_current`; documents the two validated prop-route integer IDs and selected request query bounds; qualifies CFB-only board filters; and includes declared numeric throttle429 responses. The internal research event FK remains integer/null; public ULID identity is a separate domain. The scoped79-test/1,652-assertion cohort uses actual queue-faked HTTP responses and a single preprimed limiter request. Only two schemas and nine operations changed; other122schemas remain byte-equivalent as parsed data. This is not complete validation of every endpoint or schema. Shared generated OpenAPI remains on the shared baseline until integrated source is adopted.
