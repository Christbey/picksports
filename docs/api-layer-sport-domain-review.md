# API Layer Sport-Domain Review

Last reviewed: 2026-08-03.

## Scope And Invariants

This review covers Laravel API routes, request validation, sport context
resolution, query services, Eloquent models, API resources, Vue consumers,
AI narrative boundaries, and the NFL/MLB Python ML packages.

The governing constraint is unchanged: improve maintainability, performance,
and safety without changing prediction flow, formulas, grading, or model
selection. Calculation changes require a separate backtest and validation
cycle.

## Current Request Flow

```text
Vue useApiV2Client
  -> /api/v2/sports/{sport}/...
  -> auth:sanctum + v2.sport-api-access
  -> V2 Form Request
  -> SportContextResolver / SportContext
  -> sport-family query or presentation service
  -> V2 JsonResource
  -> stable data + meta contract
```

`SportContext` dynamically maps each sport to its Eloquent models and legacy
resources. V2 query services use the model map; V2 resources own the public
wire format. This is the correct long-term boundary.

## Surface Inventory

| Surface | Registered routes | Status |
| --- | ---: | --- |
| `/api/v1` | 334 | Compatibility surface; deprecated and usage-logged. |
| `/api/v2` | 69 | Canonical application API. |

The frontend contains no direct `/api/v1` calls and uses
`resources/js/composables/useApiV2Client.ts`. V1 still has compatibility tests
and must not be removed until production usage logs show no callers for a full
retirement window.

The legacy sport layer currently contains 78 sport controllers plus shared
abstract controllers and roughly 70 sport resources. This is the largest
verified bloat candidate, but it is not yet verified dead code.

## Findings And Actions

| Priority | Finding | Action |
| --- | --- | --- |
| High, fixed | `SportPredictionResource` queried `prediction_feature_snapshots` while serializing every row. | Snapshot retrieval now runs in one batched query per prediction collection. A contract test enforces the query count. |
| High, fixed | The prediction resource resolved MLB and WNBA calculation services from the container during serialization. | `SportPredictionPresentationService` now prepares sport-specific context before resource serialization. Existing calculators are unchanged. |
| High, fixed | NFL Python inference loaded `joblib` files without first verifying the manifest inventory; MLB already verified it. | NFL now validates required files, sizes, SHA-256 hashes, schema hash, and run-directory IDs before deserialization. |
| Medium, open | `SportForecastQuery` is a 600-line switchboard containing NBA, NFL, MLB, CBB, and WCBB branches. | Split it into a shared dispatcher and one forecast query per supported sport. Preserve each existing payload contract. |
| Medium, open | `SportTeamStatAverageController`, `SportPlayerPropController`, and `MlbDailyPickController` still contain query or domain orchestration logic. | Move database access and board/date selection into query/action services; add Form Requests where validation is still inline. |
| Medium, open | Capability metadata is incomplete. Route availability is partly determined by model existence, partly by config, and partly by hard-coded sport lists. | Add explicit V2 capabilities for signals, forecasts, futures, props, daily picks, injuries, and trends, then make discovery and 404 behavior use the same source. |
| Medium, open | V2 request classes repeat trimming, boolean conversion, integer conversion, and pagination normalization. | Introduce a small V2 request-normalization base or concern after endpoint behavior is snapshot-tested. |
| Low, open | `SportPredictionResource` remains large because CFB signal sanitization and MLB recommendation serialization live in the shared class. | Extract pure CFB and MLB serializers after the presentation-service boundary has settled. |

## Sport Domains

| Sport | API domain and model boundary | Calculation / ML boundary | Review decision |
| --- | --- | --- | --- |
| NFL | Complete V2 teams, players, games, predictions, stats, metrics, injuries, props, futures, signals, depth charts, and forecasts. | PHP prediction engine plus a Python tabular shadow/challenger package with chronological evaluation and artifact lineage. | Keep calculation services intact. The Python loader now matches MLB artifact-integrity behavior. Split NFL forecast and signal presentation from shared V2 switchboards next. |
| CFB | Complete core V2 domain with leaderboard support and week-zero filtering. | PHP model with weather, market movement, EPA, availability, preseason, and game-context layers. | Keep contextual adjustments in CFB services. Move only the public signal serializer out of the shared prediction resource. |
| CBB | Complete core V2 domain with props, season averages, brackets, and tournament forecasts. | Shared college-basketball generator plus tournament simulation and PHP calibration configuration. | Preserve the shared generator. Separate bracket APIs from sport data contracts and split CBB forecast querying from the shared forecast class. |
| WCBB | V2 teams, games, predictions, stats, metrics, injuries, and tournament forecasts; player detail is intentionally unavailable in web metadata. | Shared college-basketball generator with a WCBB tournament forecast implementation. | Make the capability map authoritative so unavailable player/prop URLs are discoverable as unsupported. Reuse CBB request/query primitives where contracts match. |
| NBA | Full V2 core domain with props, futures, signals, depth charts, and playoff forecasts. | PHP prediction engine plus PHP win-probability calibration trainer/inference and spread residual tooling. | Keep calibration artifacts versioned and out of public resources. Move averages and forecasts out of controllers/shared switchboards. |
| WNBA | V2 core domain with props and public value summaries. | PHP prediction engine, WNBA signal service, and line-based value calculator. | The value calculator now runs before serialization. Keep internal recommendation arrays gated; expose only the compact value signal. |
| MLB | Richest V2 domain: core data, props, futures, signals, depth charts, playoff forecasts, period insights, and daily picks. | PHP rules and pick gates plus Python tabular and period-model shadow/challenger packages. | Preserve promotion gates and point-in-time rules. Continue extracting daily-pick, period, and forecast orchestration from controllers while retaining artifact and dataset lineage. |

## Data Contract Rules

- Route parameters identify sport and entity IDs; Form Requests own all query
  filter validation and normalization.
- Query services return Eloquent builders, models, collections, or paginators.
- Resources serialize prepared data and must not execute SQL, resolve services,
  authorize fields, or invoke model calculations.
- Public V2 predictions expose stable consumer terms such as `model_level`,
  `market_edge`, and `market_implied_probability`. Raw inputs, model metadata,
  AI working data, narratives, and calibration internals remain gated.
- Availability endpoints and sport metadata must derive support from one
  capability map rather than duplicated slug arrays.
- Collection endpoints must be paginated or explicitly bounded. Query-count
  tests are required when a resource needs related or historical data.

## AI And ML Review

AI narrative agents use Laravel AI structured outputs, bounded arrays, explicit
timeouts, feature flags, input hashes, and instructions that prohibit invented
data. AI output remains explanatory and must not override deterministic model
outputs or publishing gates. Provider and model names are environment-driven;
default-model upgrades should be evaluated with saved prompts and schema
contract tests rather than folded into API refactors.

NFL and MLB Python packages use Python 3.11-3.12, pinned dependencies,
chronological splits, seeded estimators, feature schemas, dataset/config hashes,
calibration evaluation, and immutable run artifacts. Their `joblib` files must
only be loaded after manifest verification and only from trusted registered
artifacts. A dependency upgrade can alter fitted models and serialized object
compatibility, so it belongs in a retraining and backtest release, not this
behavior-preserving cleanup.

The other five sports currently use PHP-native prediction/calibration paths.
They should not gain a Python or generative-AI layer merely for architectural
symmetry. Add one only when there is a validated modeling need, a versioned
feature contract, chronological evaluation, artifact lineage, and a shadow
deployment path.

## Removal Gate

Code is removable only when all of the following are true:

1. No Vue, mobile, integration, webhook, scheduled command, or documented
   external consumer references it.
2. V1 usage logging reports zero calls for the agreed production window.
3. A V2 replacement contract exists and has feature coverage.
4. Contract, authorization, and route tests remain green after removal.
5. OpenAPI and API reference artifacts are regenerated.

Until this gate is met, legacy code is a retirement candidate rather than dead
code.

## Next Execution Order

1. Split `SportForecastQuery` by NBA, NFL, MLB, CBB, and WCBB domain.
2. Extract team-season averages and player-prop board orchestration from V2
   controllers.
3. Complete V2 capability metadata and make the admin inspector consume it.
4. Extract CFB and MLB prediction serializers from the common resource.
5. Review production V1 usage logs and remove routes/controllers/resources in
   sport-sized batches with replacement and rollback notes.

Standards references:

- Laravel 12 API Resources: <https://laravel.com/docs/12.x/eloquent-resources>
- PHP 8.4: <https://www.php.net/releases/8.4/en.php>
- Python 3.12: <https://docs.python.org/3.12/whatsnew/3.12.html>
- scikit-learn model persistence: <https://scikit-learn.org/stable/model_persistence.html>
