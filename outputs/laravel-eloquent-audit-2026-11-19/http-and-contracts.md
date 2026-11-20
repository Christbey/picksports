# HTTP and data-flow cleanup inventory

Baseline audit evidence. For implemented changes and current completion status, use the [README checklist](README.md).

Read-only review of current working tree, November 19, 2026. No code changed or runtime query measurements. Priorities follow the README: P1 data integrity; P2 substantive query/contract/boundary work; P3 maintainability or measurement-led work. C identifiers in the README are the completion tracker. A match is not automatically a defect. Paths below are repository relative.

## Verified findings

1. **P2 contract review — divergent game serialization contracts (C02).** `app/Http/Resources/Api/V2/SportGameResource.php:58,144`: model path supplies NFL `starts_at`; GameSummary path does not. `app/Http/Controllers/Api/V2/SportGameController.php:24` uses summary pagination while show uses the model. `app/Application/Sports/ReadModels/GameSummaryMapper.php:14` duplicates much of the resource's model shaping. Use one field-definition owner and add list/show contract parity coverage for deliberately shared fields. Different list/detail contents can be intentional, so confirm expected `starts_at` contract before calling this a client bug.

2. **P3 — canonical mapper fallback drift (C03).** `app/Application/Sports/ReadModels/GameSummaryMapper.php:134-146` and `app/Application/Sports/ReadModels/CanonicalPredictionSummaryMapper.php:10-34` independently construct canonical `PredictionSummary`. The dedicated mapper falls back to spread confidence and 0.5 probability; GameSummaryMapper returns home-moneyline confidence/probability without those fallbacks. Centralize canonical mapping and explicitly decide unknown probability semantics (do not casually infer 50%). Dedicated mapper calls `loadMissing('markets')` at line 12, so ensure collection eager loading before mapping in loops. Final caller review: the dedicated mapper is injected into `app/Services/Predictions/CanonicalPredictionReadRepository.php:12`; the only discovered repository consumer is `tests/Feature/WNBA/CanonicalPredictionLifecycleTest.php:103`. This is a consistency/dead-code review, not proof of divergent live responses. Unify deliberate semantics or retire the unused path after compatibility review.

3. **P2 — relationship changes model type.** `app/Services/Api/V2/SportGameQuery.php:149-162` attaches CanonicalPrediction to `prediction`; e.g. `app/Models/NFL/Game.php:132` declares sport-specific Prediction. Loaded dynamic property and relationship query therefore have different semantics. Add a separately named canonical relationship or pass forecast explicitly. Canonical selection/release gates must remain intact.

4. **P2 — redundant canonical reload on composite game pages.** `app/Services/Api/V2/SportGameQuery.php:135-141,149-162` attaches canonical forecast after finding game; `app/Services/Api/V2/SportPredictionReader.php:88-93` then queries it again in `forLoadedGame()`, loading its event/game graph afresh. Composite page callers: `NflGamePageQuery.php:20-23`, `SportGamePageController.php:40-60`. Introduce an explicit selected-forecast result passed through the page pipeline, preserving freshness/publication filters. Do not simply trust the legacy-named relation as a permanent fix.

5. **P2 — response-only attributes dirty models.** `app/Services/Api/V2/NflGamePageQuery.php:21`, `app/Http/Controllers/Api/V2/SportGameController.php:58`, `app/Http/Controllers/Api/V2/SportGamePageController.php:42` set `matchup_context` as an Eloquent attribute; resource reads it at `SportGameResource.php:128`; mapper reads it at `GameSummaryMapper.php:90`. Pass explicit presentation context/page data. No subsequent accidental save was demonstrated; this is a boundary risk, not a proven failed SQL write.

6. **P2 — per-group invitation query in admin page.** `app/Http/Controllers/Settings/AdminSettingsController.php:70-84` gets all owned pools and queries 20 invitations separately inside map. `app/Models/Group.php` lacks invitations relationship. Add HasMany and constrained eager loading with per-parent latest limit, then serialize via a resource. Actual additional query count grows with number of groups; runtime not profiled.

7. **P2 — query fallback during recommendation serialization.** `app/Http/Resources/BettingRecommendationResource.php:36-38` calls `NflPropSeasonSummary::forProps(new Collection([$prop]))` when prepared summary absent. `app/Services/BettingRecommendations/NflPropSeasonSummary.php:34-48` loads relations and queries teams and player history. Require prepared batch summaries at list boundary rather than allow per-item DB fallback. Existing callers may already provide summaries, so classify as dangerous fallback rather than unconditional current N+1.

8. **P2 — injury impact queries hidden inside resource serialization.** Team resources `app/Http/Resources/{CBB,WCBB,WNBA,MLB,NFL}/TeamResource.php:18` call InjuryImpactScorer per injury; `app/Http/Resources/NBA/PlayerInjuryResource.php:18` does too. `app/Support/InjuryImpactScorer.php:203-221` queries player stats when not preloaded for supported sports. `PublicSportPageController.php:124` already preloads, but other controllers/query services searched do not. Batch preload at owning data-preparation boundary, pass impact data, and guard nested `player` access with eager loading. The reviewed NFL/MLB configs do not enable weighted stats, so their default scorer path does not incur this stat fallback. The setting can override that behavior; do not claim identical query cost across sports. Non-query calculation itself can remain ordinary PHP.

9. **P3 boundary — resource invokes presentation preparation (C24).** `app/Http/Resources/CanonicalDashboardPredictionResource.php:22-26` resolves CanonicalPredictionPresentationService inside toArray; service `app/Services/Api/V2/CanonicalPredictionPresentationService.php:20-41` traverses graph and dispatches recommendation/context services. API reader separately has an explicit preparation pattern (`SportPredictionReader.php:67-69`). Make dashboard use the same explicit preparation approach. Existing dashboard/scoreboard eager loading avoids many lazy queries, so this is a hidden dependency boundary rather than measured N+1.

10. **P3 boundary — serialization can query live store unless caller preloads (C24).** `app/Http/Resources/Api/V2/CanonicalSportPredictionResource.php:43`, `app/Http/Resources/Api/V2/SportPredictionResource.php:831`, `app/Http/Resources/DashboardPredictionResource.php:88`, `app/Http/Resources/Sports/AbstractPredictionResource.php:110` call LivePredictionStore; `app/Services/Predictions/LivePredictionStore.php:69-79` queries absent snapshots. Main reader/dashboard/scoreboard currently preload. Make loaded snapshot/presentation requirements explicit; retain efficient batch preloading. Do not label these already-batched paths N+1.

11. **P2 — raw attribute pass-through bypasses casted stat values and couples API to schema.** `app/Http/Resources/Api/V2/SportStatResource.php:138-157` returns most getAttributes() values as stats. Model casts are bypassed for those values, and new columns enter public response automatically. `SportTeamMetricResource.php:51-74` also derives its public field set from non-excluded columns, but unlike stats it reads each value through `getAttribute` (honoring casts) before `metricAttribute():78-90` coerces numeric values based on string formatting. Do not describe the metrics path as bypassing casts. Define per-sport public metric/stat fields and use casted access. Verify existing payload numeric types before changing; schema-driven metric extensibility may be intentional but should be declared rather than accidental.

12. **P3 — raw attribute guards obscure accessors and duplicate generic helpers.** `app/Http/Resources/Api/V2/SportPlayerResource.php:92`, `SportPlayerPropResource.php:143`, `SportFuturesOddResource.php:94`, `SportStatResource.php:175`, `SportTeamMetricResource.php:185`, `SportPredictionResource.php:885` only call getAttribute if key exists in getAttributes. Computed accessors without backing columns will be treated as missing. These classes also duplicate timestamp and relation guards; nested player/team shaping is repeated across them and GameSummaryMapper. Use explicit public contracts/shared nested resources, allow known accessors deliberately. Do not introduce a catch-all base abstraction merely to reduce lines.

13. **P3 — controller manual team hydration.** `app/Http/Controllers/Api/V2/SportGamePageController.php:96-125` queries recent games in identity profile then queries teams and setRelation. `SportGameQuery.php:267` already has `recent` profile. Prefer collection eager load or profile if query counts remain acceptable. Current batch may avoid duplicate team fetches across home/away lists, so measure before simplifying. Pitcher hydration `SportGamePageController.php:168-211` similarly manually batches six pitcher relationships plus forecast pitchers, but that is potentially valuable optimization; move ownership to query/preparation class rather than mechanically replace with many eager queries.

14. **P3 — reporting logic in controllers.** `app/Http/Controllers/Api/V2/SportTeamStatAverageController.php:99-143` owns MLB aggregate SQL and year choice; `app/Http/Controllers/Api/V2/MlbDailyPickController.php:103-346` owns slate/performance/health/achievement shaping; `app/Http/Controllers/PublicSportPageController.php:92-218,334-381` owns injury/metric/forecast queries and mapping. Extract cohesive query/presentation objects and make controller pass validated input. SQL aggregation and joins themselves are appropriate; converting these into hydrated relationship collections would be worse. Season averages use `(int) date('Y')` with no request season filter, worth separate contract review rather than incidental refactor.

15. **P3 — runtime schema discovery drives public-query behavior.** `app/Services/Api/V2/Concerns/BuildsSportQueries.php:28-42` caches column listings per query-service instance and checks root relationship method existence; `SportTeamMetricQuery.php:146-157,194-203` uses it for query behavior; `SportInjuryQuery.php:22-29` probes tables and player columns each request, duplicating column listing. Replace known per-sport differences with explicit capabilities/configuration or model-owned definitions. Keep diagnostics schema introspection in `Admin/DashboardPayloadInspector` where it is purposeful. Missing known table should not silently look like empty healthy data unless contract explicitly requires that fallback.

16. **P3 — inline validation inconsistent with project convention.** Form Requests are normal in V2 but the locations listed below still embed rules. Migrate cohesive operations to named Form Requests as those controllers are touched; do not manufacture a behavior bug solely from inline validation, which Laravel supports. Repeated rule arrays and controller authorization are the relevant maintenance risks.

### Inline validation locations (verified search inventory)
- `app/Http/Controllers/Api/CBB/BracketController.php:21,38,83,106,134,180`
- `app/Http/Controllers/Api/GroupController.php:16,35,66`
- `app/Http/Controllers/Api/V2/SportPlayerPropController.php:31`
- `app/Http/Controllers/Api/V2/NflMarketHistoryController.php:22`
- `app/Http/Controllers/Api/V2/NativeDeviceSessionController.php:20,41,74`
- `app/Http/Controllers/Api/V2/NflMatchupSignalController.php:32`
- `app/Http/Controllers/BetTrackerController.php:23,62,111`
- `app/Http/Controllers/BettingRecommendationsController.php:43`
- `app/Http/Controllers/AlertPreferenceController.php:30,51`
- `app/Http/Controllers/Auth/OauthOnboardingController.php:26`
- `app/Http/Controllers/Auth/PasskeyController.php:92,213,229`
- `app/Http/Controllers/Subscription/CheckoutController.php:24`
- `app/Http/Controllers/Settings/WebPushSubscriptionController.php:28,50`
- `app/Http/Controllers/Settings/AdminSettingsController.php:147,175,201,231,276,304,340,373,424`
- `app/Http/Controllers/Settings/OddsApiPlayerMappingController.php:53`
- `app/Http/Controllers/Settings/CfbdTeamMappingController.php:54`
- `app/Http/Controllers/Settings/OddsApiTeamMappingController.php:132`
- `app/Http/Controllers/Settings/AlertPreferenceController.php:27`
- `app/Http/Controllers/Admin/NflModelMonitoringController.php:15`
- `app/Http/Controllers/Admin/PlayerPropCardExportController.php:33`
- `app/Http/Controllers/Admin/SubscriptionController.php:91`
- `app/Http/Controllers/Admin/PermissionController.php:70`

## Justified exceptions / do not rewrite mechanically
- Query builder aggregate SQL in averages/reporting and injury joins avoids unnecessary Eloquent hydration.
- Typed immutable read models can be useful for delivery/calculation boundaries. Problem is duplicate ownership and incompatible fallbacks, not DTO existence.
- PHP loops for model calculations, matchup logic, and simulations are not Laravel anti-patterns.
- Existing constrained eager loading, pagination, Form Requests, Resources, and batch live/impact loaders should be retained.
- Resource service calls for pure dates, routing, box-score arithmetic or label formatting do not by themselves warrant new layers.

## Suggested checks for actual implementation
Shared payload contract parity; list query growth at 1 versus 20 rows/groups; serialization query-free after explicit preparation; accessor/cast field values; canonical publication/release selection remains identical; existing API/Inertia consumers and frontend types preserved.

## Architecture-test debt reconciliation

`tests/Architecture/ApiResourceArchitectureTest.php:25-40` lists 11 resources exempt from its calculator import rule. The test checks source text, so indirect query services bypass it (findings 7, 9, 10).

- `app/Http/Resources/NFL/GameResource.php:47` invokes CalculateLiveWinProbability::execute. This is real live model calculation; action `app/Actions/NFL/CalculateLiveWinProbability.php:25` reads LivePredictionStore::baseline, which can query snapshots/forecasts. Precompute a read-only live estimate with batched baseline selection, pass to resource. Higher priority than arithmetic-only resource calls.
- `app/Http/Resources/NFL/PlayerStatResource.php:18-61` estimates EPA from box score and per-opportunity values; `app/Http/Resources/NBA/PlayerStatResource.php:18-30` and `app/Http/Resources/CBB/PlayerStatResource.php:18-30,55-56` estimate box-score EPA and per-36 values. These are actual domain calculations but ordinary arithmetic, not demonstrated DB queries. P3: prepare consistently with leaderboard services when those paths are touched; preserve estimated labels and coefficients. No benefit from persisting merely for serialization cleanliness.
- `app/Http/Resources/{CBB,WCBB,WNBA,MLB,NFL}/TeamResource.php:18` and `app/Http/Resources/NBA/PlayerInjuryResource.php:18`: scorer description combines label formatting with domain injury penalties. EPA-stat fallback only applies to nba/wnba/cbb/wcbb (`InjuryImpactScorer.php:280-287`), not NFL/MLB. Supported basketball resources have query risk without preloading; NFL/MLB retain domain calculation boundary debt only.
- `app/Http/Resources/Api/V2/MlbPickCandidateResource.php:15-16` receives explanation/model-context services via constructor. This is better than unannounced service-locator access, but preparation still happens during serialization; review actual explanation/model context methods before classifying DB cost. P3 move prepared explanation and context into presentation input, preserve custom labels.
- `app/Http/Resources/Api/V2/CanonicalSportPredictionResource.php:131` calls LiveBoardPresentation::forGame for CFB. `app/Services/CFB/Live/LiveBoardPresentation.php:28` reads latestLiveSnapshot; `CanonicalSportPredictionQuery.php` explicitly eager loads it for CFB and reader/live preload reinforces it. It mainly formats an existing live projection and staleness state, so do not count as a fresh model calculation or existing list N+1.

C37 covers remaining EPA estimate and non-query injury presentation boundaries; C18 covers basketball injury queries, C19 the NFL live estimate, and C24 live/dashboard preparation. Pure timestamp, route, label, nullable-number formatting calls should remain in Resources where readable. Boundary cleanups above should focus on actual input selection, model math, hidden database access, and duplicated contracts.

`MlbPickCandidateResource.php:111-112` explanation is pure formatting of stored snapshot summaries (`MlbPickExplanationService.php:9-41`), so not model calculation. `MlbPeriodModelContextService.php:31-43` queries related shadow outputs via `prime`; `forCandidate():69` uses per-game output lookup. Controller primes the batch (`MlbDailyPickController.php:51`), so retain that batching; explicit precomputed period context would make its contract clearer but there is no demonstrated per-candidate N+1 on this route.
