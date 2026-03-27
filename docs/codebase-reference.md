# Codebase Reference

This document is a working reference for the PickSports codebase so future changes can be grounded in the actual models, controllers, actions, and known data semantics instead of inferred assumptions.

It is intentionally practical rather than exhaustive.

## Purpose

Use this document before changing:
- sport model semantics
- game page data contracts
- prediction inputs/outputs
- team metrics queries
- season / season type logic
- conference / division / league / alignment logic

This should be treated as the first-stop context document for sports-domain work.

## High-Level Architecture

The app is organized by sport with a shared abstraction layer.

Core backend layers:
- `app/Models/{Sport}`: Eloquent models per sport
- `app/Actions/{Sport}`: prediction, Elo, team metrics, trends, grading
- `app/Actions/ESPN/{Sport}`: ESPN ingest/sync actions
- `app/Actions/OddsApi/{Sport}`: odds and player-prop ingest
- `app/Http/Controllers/Api/{Sport}`: sport API controllers
- `app/Http/Controllers/Api/Sports`: shared abstract API controllers
- `app/Http/Resources/{Sport}`: API resource serializers
- `app/Console/Commands/{Sport}`: operational commands
- `resources/js/composables`: shared and sport-specific game-page/prediction-page data hooks
- `resources/js/components/game-page`: shared game-page UI

Shared route registration lives in [routes/api/sports.php](/Users/bey/Herd/github/picksports/routes/api/sports.php).

## Shared API Patterns

The shared API abstractions matter a lot:

- [AbstractGameController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractGameController.php)
  Handles `games`, `games/{id}`, `teams/{team}/games`, season and week lookups.

- [AbstractPredictionController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractPredictionController.php)
  Handles prediction index/show/by-game plus season/date/week filters.
  Important: prediction filtering is usually done through related `game` rows.

- [AbstractTeamController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamController.php)
  Handles team show and `teams/{team}/trends`.
  Important: trends now support `season`, `season_type`, and `before_date`.

- [AbstractTeamMetricController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamMetricController.php)
  Handles team metric index/show/by-team.
  Important: if a metrics table actually has a `season_type` column, filtering is done directly on the stored rows.

## Internal API

The primary internal API is the Laravel JSON API mounted under `/api/v1`.

Route bootstrap:
- [routes/api.php](/Users/bey/Herd/github/picksports/routes/api.php)
- [routes/api/sports.php](/Users/bey/Herd/github/picksports/routes/api/sports.php)

Important pattern:
- `routes/api.php` loads `config('sports.domains')`
- for each sport, it mounts a namespace-specific API group under `/api/v1/{sport}`
- almost all sports routes are generated from the shared route definer in `routes/api/sports.php`

### Auth and Access Model

Auth endpoints:
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/passkeys/options`
- `POST /api/v1/auth/passkeys/verify`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/logout-all`

Sports data access model:
- many public-looking endpoints are actually protected by `auth:sanctum`
- prediction, team-metric, injury, and trends access often also depends on permissions such as `view-{sport}-predictions`
- prediction resources are field-gated by subscription tier / permission, not just endpoint access

That means:
- the same endpoint can return different field sets depending on the authenticated user
- the frontend must tolerate partial prediction payloads

### Generic Sport Route Contract

For each sport namespace, the generated API includes these categories:

Teams:
- `GET /api/v1/{sport}/teams`
- `GET /api/v1/{sport}/teams/{team}`
- `GET /api/v1/{sport}/teams/{team}/trends`

Players:
- `GET /api/v1/{sport}/players`
- `GET /api/v1/{sport}/players/{player}`
- `GET /api/v1/{sport}/teams/{team}/players`

Games:
- `GET /api/v1/{sport}/games`
- `GET /api/v1/{sport}/games/{game}`
- `GET /api/v1/{sport}/teams/{team}/games`
- `GET /api/v1/{sport}/games/season/{season}`
- `GET /api/v1/{sport}/games/season/{season}/week/{week}`
- `GET /api/v1/{sport}/games/{game}/plays`
- `GET /api/v1/{sport}/games/{game}/team-stats`
- `GET /api/v1/{sport}/games/{game}/player-stats`
- `GET /api/v1/{sport}/games/{game}/prediction`

Predictions:
- `GET /api/v1/{sport}/predictions`
- `GET /api/v1/{sport}/predictions/{prediction}`
- `GET /api/v1/{sport}/predictions/available-dates`
- `GET /api/v1/{sport}/predictions/available-seasons`

Team metrics:
- `GET /api/v1/{sport}/team-metrics`
- `GET /api/v1/{sport}/team-metrics/{metric}`
- `GET /api/v1/{sport}/team-metrics/available-seasons`
- `GET /api/v1/{sport}/teams/{team}/metrics`

Stats and ratings:
- `GET /api/v1/{sport}/team-stats`
- `GET /api/v1/{sport}/player-stats`
- `GET /api/v1/{sport}/elo-ratings`

Capability-specific additions:
- CFB `fpi-ratings`
- CBB/WCBB tournament forecasts
- NBA/MLB playoff forecasts
- odds-backed player props for enabled sports

### Internal API Filtering Rules

Prediction index filtering in [AbstractPredictionController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractPredictionController.php):
- `season`
- `season_type`
- `week`
- `from_date`
- `to_date`

Team trends filtering in [AbstractTeamController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamController.php):
- `games`
- `season`
- `season_type`
- `before_date`

Team metrics filtering in [AbstractTeamMetricController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamMetricController.php):
- `season`
- `season_type`
- plus sport-specific query modifications in concrete controllers

### Resource Serialization Pattern

Controllers generally return Eloquent models loaded with relations.
Resources shape the actual API contract.

Important examples:
- [app/Http/Resources/MLB/GameResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/MLB/GameResource.php)
- [app/Http/Resources/NFL/GameResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/NFL/GameResource.php)
- [app/Http/Resources/MLB/PredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/MLB/PredictionResource.php)
- [app/Http/Resources/NFL/PredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/NFL/PredictionResource.php)
- shared prediction helpers in [AbstractPredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/Sports/AbstractPredictionResource.php)

Important contract note:
- `games/{game}` returns the base game payload plus loaded relations and any computed attributes attached by the controller
- `games/{game}/prediction` returns the prediction resource shape, which is sport-specific and permission-gated
- not all sports expose identical prediction fields

Examples:
- MLB prediction resource exposes:
  - `predicted_spread`
  - `predicted_total`
  - `win_probability`
  - `home_team_elo`
  - `away_team_elo`
  - `home_pitcher_elo`
  - `away_pitcher_elo`
- NFL prediction resource exposes:
  - `predicted_spread`
  - `predicted_total`
  - `win_probability`
  - `home_elo`
  - `away_elo`
  - `betting_value`

### byGame Prediction Contract Caveat

The `games/{game}/prediction` endpoint deserves special care.

The shared controller supports both collection-style and single-object responses.
Some sports override this behavior.

Example:
- [app/Http/Controllers/Api/MLB/PredictionController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/MLB/PredictionController.php)
  forces `returnFirstPredictionOnly()` to `true`
- NFL currently uses shared behavior without that override

Before changing any game-page prediction fetch path, confirm whether the endpoint returns:
- a single prediction object
- a list containing one prediction

### Frontend Consumers of the Internal API

The frontend talks to the internal API with `fetchJson` from [useApiClient.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useApiClient.ts).

Main game-page consumers:
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
  Uses:
  - `/api/v1/{sport}/games/{game}`
  - `/api/v1/{sport}/games/{game}/prediction`
  - `/api/v1/{sport}/games/{game}/team-stats`
  - `/api/v1/{sport}/games/{game}/player-stats`
  - `/api/v1/{sport}/teams/{team}/metrics`
  - `/api/v1/{sport}/teams/{team}/games`
  - `/api/v1/{sport}/teams/{team}/trends?...`

- [useMlbGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useMlbGamePage.ts)
  Does a more bespoke MLB flow:
  - `/api/v1/mlb/games/{game}`
  - `/api/v1/mlb/games/{game}/prediction`
  - `/api/v1/mlb/teams/{id}`
  - `/api/v1/mlb/teams/{id}/games`
  - `/api/v1/mlb/teams/{id}/trends?...`

- [useNflGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useNflGamePage.ts)
  Uses:
  - `/api/v1/nfl/games/{game}`
  - `/api/v1/nfl/games/{game}/prediction`
  - `/api/v1/nfl/games/{game}/team-stats`
  - `/api/v1/nfl/teams/{id}/games`
  - `/api/v1/nfl/teams/{id}/trends?...`

- [useCfbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useCfbDetailedGamePage.ts)
  Uses a bespoke CFB flow similar to NFL.

Predictions list pages:
- [SportPredictions.vue](/Users/bey/Herd/github/picksports/resources/js/components/SportPredictions.vue)
  Uses:
  - `/api/v1/{sport}/predictions?...`
  - `/api/v1/{sport}/predictions/available-dates`
  - `/api/v1/{sport}/predictions/available-seasons`

Team metrics pages:
- built from config in [sport-team-metrics-configs.ts](/Users/bey/Herd/github/picksports/resources/js/config/sport-team-metrics-configs.ts)
  and call `/api/v1/{sport}/team-metrics`

### Internal API Data Flow

The normal flow is:

1. External source ingestion
   - ESPN sync actions populate sport tables:
     - games
     - teams
     - players
     - stats
     - plays
     - injuries
   - Odds API sync actions populate:
     - `odds_api_event_id`
     - `odds_data`
     - player props / mappings where applicable

2. Derived calculations
   - Elo commands/actions populate `elo_ratings`
   - team metrics commands/actions populate `team_metrics`
   - prediction commands/actions populate `predictions`
   - trends are usually computed on demand rather than persisted as first-class rows

3. API assembly
   - controller loads model and relations
   - shared abstract controller applies filtering and caching
   - resource serializes the public/internal JSON contract
   - field-level gating may hide premium prediction fields

4. Frontend composition
   - composables call multiple API endpoints
   - page-specific hooks normalize sport-specific payloads
   - shared game-page components render the assembled state

### Caching in the Internal API

Shared API controllers use [SportsViewCache.php](/Users/bey/Herd/github/picksports/app/Support/SportsViewCache.php).

Important cache segments include:
- `predictions_index`
- `team_metrics_index`
- `team_trends`
- `team_games_by_team`

This matters when debugging “the DB has it but the UI doesn’t.”
Sometimes the issue is:
- stale cache segment
- stale browser state
- page-specific payload normalization
not missing database rows.

### Internal API Debugging Checklist

When something looks wrong on a page:

1. Check the raw game row in the DB.
2. Check the raw prediction / metric row in the DB.
3. Check the API resource response at `/api/v1/{sport}/games/{id}`.
4. Check `/api/v1/{sport}/games/{id}/prediction`.
5. Check whether the frontend uses the shared loader or a sport-specific loader.
6. Check cache segments if the DB and API are correct but the page is stale.

### Internal API Contract Risks To Watch

- Sport resources are similar but not identical.
- Prediction resources are permission-gated.
- Some pages use shared loaders, others use bespoke sport loaders.
- Some endpoints return arrays in one sport and single objects in another.
- `season_type` support is not uniform unless the underlying tables actually store it.

## Shared Frontend Game Page Flow

The main game page UI is shared.

Key files:
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
  Shared loader for game detail, prediction, team stats, player stats, recent games, and team trends.

- [useSportDetailedPageProps.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useSportDetailedPageProps.ts)
  Shared prop assembler for detailed game pages.

- [useSportGameLayout.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useSportGameLayout.ts)
  Shared layout/breadcrumb config.

- [SportDetailedGamePage.vue](/Users/bey/Herd/github/picksports/resources/js/components/game-page/SportDetailedGamePage.vue)
  Shared shell for matchup hero, linescore, prediction summary, trends, and now matchup context.

Sport-specific wrappers:
- [useMlbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useMlbDetailedGamePage.ts)
- [useNflDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useNflDetailedGamePage.ts)
- [useBasketballDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useBasketballDetailedGamePage.ts)
- [useCfbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useCfbDetailedGamePage.ts)

## Per-Sport Model Surface

### MLB

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Team.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/MLB/TeamMetric.php)
- [EloRating.php](/Users/bey/Herd/github/picksports/app/Models/MLB/EloRating.php)
- [PitcherEloRating.php](/Users/bey/Herd/github/picksports/app/Models/MLB/PitcherEloRating.php)
- [Player.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Player.php)
- [PlayerStat.php](/Users/bey/Herd/github/picksports/app/Models/MLB/PlayerStat.php)

Important field semantics:
- `teams.league` and `teams.division` are the alignment fields, not `conference`
- `games.probable_home_pitcher_espn_id` / `probable_away_pitcher_espn_id` are meaningful pregame starter fields
- `player_stats.stat_type` distinguishes batting vs pitching rows
- `team_metrics` now support `season_type` rows

Important operational note:
- MLB opening-day handling is sensitive to local-date conversion and season-type classification

### NFL

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Team.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/NFL/TeamMetric.php)
- [Player.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Player.php)
- [PlayerStat.php](/Users/bey/Herd/github/picksports/app/Models/NFL/PlayerStat.php)

Important field semantics:
- `teams.conference` and `teams.division` are meaningful NFL alignment fields
- there is no dedicated pregame `starting_qb` field on `nfl_games`
- QB inference currently has to come from player stats / recent games if needed
- `games.neutral_site` exists and matters for context splits

### CFB

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Team.php)
- [TeamSeasonAffiliation.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamSeasonAffiliation.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamMetric.php)
- [FpiRating.php](/Users/bey/Herd/github/picksports/app/Models/CFB/FpiRating.php)

Important field semantics:
- `teams.conference` / `teams.division` are not enough by themselves across seasons
- season-aware alignment should prefer `team->seasonAffiliation($season)`
- `games.conference_game` exists and is more trustworthy than inferred “same conference” in some cases
- `games.postseason_round` matters for postseason labeling

### NBA / WNBA

Primary models:
- [app/Models/NBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Team.php)
- [app/Models/NBA/Game.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Game.php)
- [app/Models/NBA/Prediction.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Prediction.php)
- [app/Models/NBA/TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/NBA/TeamMetric.php)
- [app/Models/WNBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Team.php)
- [app/Models/WNBA/Game.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Game.php)

Important field semantics:
- `conference` and `division` are real alignment fields
- basketball game pages use the shared `useDetailedGameData` path, not bespoke per-sport game detail loaders

### CBB / WCBB

Primary models:
- [app/Models/CBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Team.php)
- [app/Models/CBB/Game.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Game.php)
- [app/Models/CBB/Prediction.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Prediction.php)
- [app/Models/CBB/TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/CBB/TeamMetric.php)
- [app/Models/WCBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Team.php)
- [app/Models/WCBB/Game.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Game.php)

Important field semantics:
- `conference` is usually meaningful
- `division` is often an NCAA classification / level field, not a pro-style intraconference division
- do not assume “division record” is product-valid for these sports without confirming the underlying data meaning

## Prediction / Team Metrics / Trends Pipelines

### Predictions

Sport actions:
- `app/Actions/{Sport}/GeneratePrediction.php`
- `app/Console/Commands/{Sport}/GeneratePredictionsCommand.php`

Game page prediction endpoint:
- `/api/v1/{sport}/games/{game}/prediction`

Important note:
- the frontend usually expects a single prediction object for a game page
- prediction index endpoints may return collections

### Team Metrics

Sport actions:
- `app/Actions/{Sport}/CalculateTeamMetrics.php`
- `app/Console/Commands/{Sport}/CalculateTeamMetricsCommand.php`

Important note:
- the shared team-metric controller now supports true `season_type` filtering when the table actually stores that column
- MLB team metrics now support season-type-specific rows

### Trends

Sport actions:
- `app/Actions/{Sport}/CalculateTeamTrends.php`
- shared base: [AbstractCalculateTeamTrends.php](/Users/bey/Herd/github/picksports/app/Actions/Trends/AbstractCalculateTeamTrends.php)

Important note:
- trends are calculated from prior games and should use `season`, `season_type`, and `before_date` where relevant

## ESPN / Odds Ingest Surface

ESPN ingestion:
- `app/Actions/ESPN/{Sport}/SyncTeams.php`
- `SyncGames.php`
- `SyncGamesFromSchedule.php` where applicable
- `SyncGamesFromScoreboard.php`
- `SyncGameDetails.php`
- `SyncPlayerStats.php`
- `SyncTeamStats.php`
- `SyncPlays.php`
- `SyncPlayerInjuries.php`

Odds ingestion:
- `app/Actions/OddsApi/{Sport}/SyncOddsForGames.php`
- `SyncPlayerPropsForGames.php` where applicable

### ESPN Endpoint Availability Matrix

This section is based on direct endpoint checks, not assumptions from field names.

Verified usable depth-chart endpoints:
- `NFL`
  - team core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{season}/teams/{teamId}/depthcharts`
- `NBA`
  - team core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{season}/teams/{teamId}/depthcharts`
- `MLB`
  - team core payload exposes a `depthCharts` `$ref`
  - direct core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/baseball/leagues/mlb/seasons/{season}/teams/{teamId}/depthcharts`

Not currently reliable from the tested public/core endpoints:
- `WNBA`
  - tested direct depth chart endpoint returned `500`
  - team core payload did not expose a matching `depthCharts` ref in the same way as NBA
- `CFB`
  - tested direct depth chart endpoint returned `500`
  - team core payload did not expose a matching `depthCharts` ref
- `CBB`
  - tested direct depth chart endpoint returned `400`
  - team core payload did not expose a matching `depthCharts` ref
- `WCBB`
  - tested direct depth chart endpoint returned `400`
  - team core payload did not expose a matching `depthCharts` ref

Important rule:
- do not assume every ESPN sport has a stable `depthcharts` contract
- prefer discovering `depthCharts.$ref` from the team core object when available
- when the ref is missing or the endpoint errors, treat depth-chart ingest for that sport as unsupported until re-verified

### ESPN MLB Probable Starter Availability

MLB probable starters are available from ESPN scheduled-game payloads.

Verified locations:
- site scoreboard:
  - `https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard?dates={yyyymmdd}`
- site summary:
  - `https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/summary?event={eventId}`

Verified payload shape:
- `competitors[].probables[]`
- probable starter entries include:
  - `abbreviation: "SP"`
  - `name: "probableStartingPitcher"`
  - `playerId`
  - nested `athlete.id`

Codebase note:
- the current MLB ESPN ingest already uses this data in:
  - [SyncGames.php](/Users/bey/Herd/github/picksports/app/Actions/ESPN/MLB/SyncGames.php)
  - [SyncGameDetails.php](/Users/bey/Herd/github/picksports/app/Actions/ESPN/MLB/SyncGameDetails.php)
- `games.probable_home_pitcher_espn_id` / `probable_away_pitcher_espn_id` are therefore grounded in real ESPN payloads, not a heuristic

### Recommended ESPN Ingestion Plan By Sport

This is the recommended storage/ingest plan based on the endpoints currently verified.

#### MLB

Use:
- game ingest from existing scoreboard / summary flows
- probable starters from `competitors[].probables[]`
- team depth charts from the core `depthCharts` ref

Recommended stored data:
- probable starter ESPN player id
- probable starter handedness / display name if present
- team depth-chart slots by position
- depth-chart ordering / rank
- source timestamps for freshness checks

Recommendation:
- keep probable-starter ingest as the primary pregame pitching source
- treat depth charts as supplemental roster/context data, not a replacement for `probables`

#### NFL

Use:
- existing game/team/player ingest
- core team `depthcharts` endpoint

Recommended stored data:
- player slot by offensive / defensive / special teams position
- depth rank within position
- starter flag if it can be derived from the top slot
- source timestamps

Recommendation:
- this is a good candidate for first-class depth-chart ingest
- useful for QB1/RB1/WR depth, injury replacement context, and lineup-aware prediction features

#### NBA

Use:
- existing game/team/player ingest
- core team `depthcharts` endpoint

Recommended stored data:
- position slot
- depth rank
- likely starter group
- source timestamps

Recommendation:
- implement as lineup context, not as a hard projection input by default
- useful for injury-adjusted roster context and game-page presentation

#### WNBA

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not build production ingest around depth charts yet
- re-verify ESPN availability later or use a separate lineup source if WNBA starter/depth data becomes important

#### CFB

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not assume ESPN depth-chart support
- if starter context is needed, build a separate CFB-specific approach
- especially avoid presenting “starting QB” as explicit unless the source is explicit

#### CBB / WCBB

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not build against the tested `depthcharts` pattern
- if lineup context becomes important, use a different source or a sport-specific ingest strategy

### Suggested Implementation Order

1. `MLB`
   - formalize current probable-starter ingest contract
   - optionally add team depth-chart ingest behind a separate sync action
2. `NFL`
   - add persistent team depth-chart storage and sync
3. `NBA`
   - add persistent team depth-chart storage and sync
4. `WNBA`, `CFB`, `CBB`, `WCBB`
   - defer until ESPN exposes a stable contract or a better source is chosen

### Data-Model Guidance For Depth Charts

If implemented, depth-chart storage should be modeled as source data, not inferred analytics.

Suggested fields:
- `sport`
- `team_id`
- `season`
- `season_type` if the source supports meaningful seasonal separation
- `espn_athlete_id`
- `position_code`
- `position_name`
- `slot_order`
- `depth_rank`
- `is_starter`
- `source`
- `source_updated_at`
- raw source payload for debugging

Operational rule:
- depth-chart data should be replaceable on every sync
- probable-starter / QB logic should prefer explicit game-level source data over team-level depth charts when both exist

## Sport Config Files

Primary sport config lives in:
- [config/mlb.php](/Users/bey/Herd/github/picksports/config/mlb.php)
- [config/nfl.php](/Users/bey/Herd/github/picksports/config/nfl.php)
- [config/cfb.php](/Users/bey/Herd/github/picksports/config/cfb.php)
- [config/nba.php](/Users/bey/Herd/github/picksports/config/nba.php)
- [config/wnba.php](/Users/bey/Herd/github/picksports/config/wnba.php)
- [config/cbb.php](/Users/bey/Herd/github/picksports/config/cbb.php)
- [config/wcbb.php](/Users/bey/Herd/github/picksports/config/wcbb.php)

Use config as source of truth for:
- statuses
- season types
- Elo defaults
- team-metric formulas
- trend thresholds
- prediction blend weights

## Field Semantics That Should Not Be Assumed

This is the section to check before building cross-sport “generic” features.

### 1. `division` does not mean the same thing across sports

- `NFL`, `NBA`, `WNBA`, `MLB`: usually real alignment grouping
- `MLB`: use `league` + `division`, not `conference`
- `CBB`, `WCBB`: `division` may effectively mean NCAA level/classification, not “same division as tonight’s opponent”
- `CFB`: season-aware affiliation may live in `TeamSeasonAffiliation`, not the base team row

### 2. `conference` does not automatically mean “conference record is valid”

You may need to consider:
- season-aware affiliations in CFB
- explicit `conference_game` flag in CFB games
- whether the sport actually organizes regular standings that way

### 3. Starter identity is asymmetric by sport

- MLB: probable starters are explicit on the game row
- NFL/CFB: there is currently no dedicated pregame starting-QB field on the game model
- Any “record vs QB” logic in football is inferred unless a dedicated starter field is added later

### 4. `season_type` is operationally important

It affects:
- trend samples
- opening-day logic
- MLB schedule classification
- team metrics storage and retrieval
- prediction carryover behavior

## Recent MLB Lessons Already Learned

These are recent, concrete codebase lessons that should be preserved.

### MLB date handling
- MLB `game_date` must be stored/grouped by local game date, not raw UTC rollover

### MLB season type
- spring training and regular season cannot be mixed in trends or opening-day model inputs
- season-type normalization belongs in ingest, not only in cleanup scripts

### MLB opening-day carryover
- opening-day Elo/predictions should use prior-season carryover, not spring-training samples

### MLB team metrics
- `mlb_team_metrics` now need season-type-aware semantics

## Current Matchup Context Caveat

The new matchup-context work should be treated with these rules:

- head-to-head, overall record, current role record, and time-of-day record are generally safe cross-sport
- conference/division splits must be sport-aware
- MLB alignment should be league/division, not conference/division
- CBB/WCBB division record should not be shown unless the underlying field is confirmed product-valid
- CFB alignment should prefer season affiliation and/or explicit `conference_game` data
- football `record vs QB` is currently inferred, not explicitly modeled

## Recommended Workflow Before Cross-Sport Feature Work

1. Check this document.
2. Read the actual model(s) for the target sport.
3. Read the shared abstract controller/resource involved.
4. Confirm field semantics in config and tests.
5. Only then generalize across sports.

## Files To Re-Read First For Sports-Domain Changes

Shared:
- [routes/api/sports.php](/Users/bey/Herd/github/picksports/routes/api/sports.php)
- [AbstractGameController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractGameController.php)
- [AbstractPredictionController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractPredictionController.php)
- [AbstractTeamController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamController.php)
- [AbstractTeamMetricController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/Sports/AbstractTeamMetricController.php)
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
- [SportDetailedGamePage.vue](/Users/bey/Herd/github/picksports/resources/js/components/game-page/SportDetailedGamePage.vue)

Sport-specific first reads:
- MLB:
  [app/Models/MLB/Game.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Game.php)
  [app/Models/MLB/Team.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Team.php)
  [app/Actions/MLB/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/GeneratePrediction.php)
  [app/Actions/MLB/CalculateTeamMetrics.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/CalculateTeamMetrics.php)

- NFL:
  [app/Models/NFL/Game.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Game.php)
  [app/Models/NFL/Team.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Team.php)
  [app/Actions/NFL/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/NFL/GeneratePrediction.php)
  [app/Actions/NFL/CalculateTeamMetrics.php](/Users/bey/Herd/github/picksports/app/Actions/NFL/CalculateTeamMetrics.php)

- CFB:
  [app/Models/CFB/Game.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Game.php)
  [app/Models/CFB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Team.php)
  [app/Models/CFB/TeamSeasonAffiliation.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamSeasonAffiliation.php)

- NBA / WNBA:
  [app/Models/NBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Team.php)
  [app/Models/WNBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Team.php)

- CBB / WCBB:
  [app/Models/CBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Team.php)
  [app/Models/WCBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Team.php)

## Maintenance Note

If a future change reveals a field-semantics mismatch, add it here immediately.

This document is meant to reduce repeated mistakes, especially around:
- alignment semantics
- starter identity assumptions
- season-type behavior
- shared-vs-sport-specific abstractions
