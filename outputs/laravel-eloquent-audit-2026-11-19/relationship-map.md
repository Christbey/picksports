# Data relationship mapping

This source-backed map is backed by a complete application-model declaration inventory and a newly migrated, disposable SQLite schema audit. The deployed schema and production records were not inspected. Local fixture validation does not certify production data.

## Mapping rules

For every persisted association, record the parent and child, table names, local and foreign keys, cardinality, nullable behavior, inverse relation where useful, uniqueness/indexes, deletion policy, and ownership of historical values. Record its producer, readers, and eager-loading requirements. Distinguish a relationship that exists, one that is missing or incorrect, and one intentionally handled by a query/resolver.

Use belongsTo/hasMany/hasOne for actual foreign-key associations. Use belongsToMany with explicit pivot attributes for membership. A table-name plus ID is not automatically an Eloquent polymorphic relationship; conversion needs stored-type compatibility and a deliberate migration. Do not force home-or-away team membership into a simple hasManyThrough. Do not add unused inverse methods merely for symmetry.

Use internal keys for application identity. A provider ESPN ID is an external identifier with its own uniqueness and source rules, not interchangeable with a player's internal ID. Public ULIDs are route identifiers, not necessarily the database primary key. Current roster membership and historical team ownership are different associations.

## Relationship map

| Data connection | Keys and cardinality declared in source | Review requirement |
| --- | --- | --- |
| SportEvent to sport-specific Game | Game.sport_event_id belongs to SportEvent; event has one game per sport-specific relation. The link migration makes each sport table's sport_event_id nullable and unique. | Verify event sport matches the linked table and an event is not linked across incompatible sport tables; per-table uniqueness alone does not enforce this. |
| SportEvent to provider identities | SportEventProviderMapping.sport_event_id; event has many mappings. | Preserve provider-scoped identity uniqueness and collision handling. |
| Game to home/away Team | Separate home_team_id and away_team_id belongsTo relations. Team has separate homeGames/awayGames. | Keep side explicit; combine through a query for either-side results. Preserve neutral-site semantics independently of home/away identity. |
| Player to current Team | Player.team_id belongsTo Team. | Treat as current membership; do not derive historical stats or evidence team from it after a transfer. Inspect season/roster history separately by sport. |
| PlayerStat to Player, Game and Team | NFL PlayerStat declares all three belongsTo relations using player_id/game_id/team_id. | Verify equivalent keys across sports, natural uniqueness and stat-category grain; MLB has stat_type and NFL merges categories. Historical team_id remains authoritative for the observation. |
| TeamStat to Game and Team | NFL TeamStat belongsTo Game and Team; Game/Team expose collections. | Verify intended one-row-per-team/game grain or documented exceptions before adding uniqueness. |
| Game to legacy Prediction | Sport Game.prediction targets its sport-specific Prediction. | Keep separate from canonical forecast selection; C04 handles relationship substitution. |
| SportEvent to canonical Prediction | CanonicalPrediction.sport_event_id; event has many revisions. | Preserve phase, revision, publication and release eligibility; event does not have only one historical prediction. |
| Calculation lineage | Run belongsTo event, input snapshot via event_input_snapshot_id and release via calculation_release_id; canonical prediction belongsTo run. Run declares hasOne prediction. | Selected lifecycle migration uniquely constrains predictions.calculation_run_id. Verify deployed uniqueness and event/sport/phase agreement across the lineage. |
| Prediction to markets | PredictionMarket.prediction_id belongsTo CanonicalPrediction; canonical hasMany markets. | Preserve market/selection grain and decimal units. |
| Prediction evaluation and event result | Evaluation.canonical_prediction_id, sport_event_id, sport_event_result_id; canonical hasMany evaluations and latestEvaluation by evaluation_revision. Result hasMany evaluations. | Match event/phase and revision; distinguish canonical lineage from legacy prediction_table/prediction_id references. Corrections are append-only history. |
| Snapshot and revision ancestry | Predictions, event results and evaluations have explicit supersedes keys; input snapshots belongTo event. | Retain original evidence and revision ancestry. Verify chain integrity and permitted deletion behavior. |
| Odds snapshots and quotes | MarketQuote.game_odds_snapshot_id belongsTo GameOddsSnapshot; snapshot hasMany quotes. Both also carry sport/game_table/game_id identity. | Resolve game identity explicitly, preserve bookmaker/market/contract/time grain, and distinguish missing snapshots from invalid links. |
| Bet decision and settlement | BetSettlement.bet_decision_id belongsTo BetDecision; decision hasOne settlement. Decision also belongsTo model run/artifact, feature snapshot, shadow output and odds snapshot. | Verify settlement uniqueness and each lineage key. Decisions use prediction_table/prediction_id metadata; do not assume those are canonical IDs. UserBet is a separate user ledger. |
| User bet and forecast | UserBet belongsTo User and SportEvent; prediction is morphTo via prediction_type/prediction_id. | Audit allowed types, legacy stored class names, key types and null references before any morph-map change. |
| Live snapshot and forecast | Shared LivePredictionSnapshot belongsTo canonical prediction and carries sport/game_table/game_id; CFB uses a separate snapshot model/relation. | Define sport-specific game resolution and latest-at-cutoff semantics. C20/C24 own fake validity state and loading; do not collapse snapshot designs without a compatibility decision. |
| Injury and depth chart | NFL injury belongsTo Player and Team. DepthChartSnapshot belongsTo Team and hasMany entries via snapshot_id; entries belongTo player. GameDepthChartLink belongsTo Game, Team, Snapshot and prediction feature snapshot. | Separate current injuries/entries from frozen game snapshots. Validate side/team/season/cutoff consistency; an injury's recorded team must not be replaced by the player's current team. |
| MLB pitcher forecast | StartingPitcherForecast belongsTo Game and Team; predicted/actual pitcher relations use ESPN owner key espn_id. | Confirm provider identifier uniqueness and string types; preserve predicted versus actual roles and confirmation timing. |
| MLB pick candidate | Explicit canonical_prediction_id relation coexists with legacy prediction_id and game/team/player links. | Keep forecast sources distinct and validate candidate's game/sport agreement. |
| Group and invitations | Group has owner, users pivot, brackets and joinLink; GroupInvitation belongsTo group, inviter and accepting user. Group invitations inverse is implemented under C08. | C08 owns the invitation inverse/loading boundary; the separate join-link uniqueness migration protects its declared one-to-one association. Verify pivot grain, invite acceptance nullability and user deletion policy. |
| User delivery records | User has alert preference and alertsSent; DailyDigestSend belongsTo User. | Verify recipient ownership and delivery deduplication grain. C09/C10 own batching; add inverses only when useful to those queries. |

The [complete model inventory](relationship-inventory.md) covers all 188 concrete application models and their declared/inherited relation builders, including models with no relations. The [JSON schema inventory](relationship-inventory.json) records keys, cardinalities, nullable behavior, indexes, deletion policies, inverse candidates, source locations, migration references and source-reference candidates. The [ownership review](relationship-ownership-review.md) assigns producer/consumer/loading and historical policies to every model/association. Package-managed target schemas are recorded separately. The [integrity counts](relationship-integrity.json) describe only the disposable migrated baseline; invalid seeded fixtures are covered by regression tests.

## Source anchors

- app/Models/SportEvent.php and SportEventProviderMapping.php.
- app/Models/NFL/Game.php, Team.php, Player.php, PlayerStat.php, TeamStat.php, PlayerInjury.php, DepthChartSnapshot.php, DepthChartSnapshotEntry.php and GameDepthChartLink.php.
- app/Models/CanonicalPrediction.php, CalculationRun.php, CalculationRelease.php, EventInputSnapshot.php, PredictionMarket.php, PredictionEvaluation.php and SportEventResult.php.
- app/Models/GameOddsSnapshot.php, MarketQuote.php, BetDecision.php, BetSettlement.php, LivePredictionSnapshot.php, UserBet.php, Group.php, GroupInvitation.php and DailyDigestSend.php.
- app/Models/MLB/StartingPitcherForecast.php and PickCandidate.php.
- database/migrations/2026_08_12_130000_create_sport_events_tables.php and 2026_08_12_131000_add_sport_event_links_to_sport_games.php.
- database/migrations/2026_08_12_180000_create_canonical_predictions_tables.php and 2026_08_13_021000_create_canonical_event_results_and_evaluation_links.php; inspect subsequent migrations before proposing constraints.

## Verification and release boundaries

1. Reconcile declarations with migration history and an explicitly identified, authorized database schema. Report the environment inspected; local schema is not proof of production schema.
2. Check existing duplicates, orphans, null links and sport/event mismatches using read-only queries before tightening constraints. Record results without exporting sensitive row contents. The emitted counts and seeded exceptions were checked only in the named disposable SQLite environment; production checks were not run.
3. Choose restrict, null or cascade deletion intentionally. Published predictions, source snapshots, results, bets and evaluations have retention/immutability requirements. A convenient cascade can erase evidence; a nullable relation is not automatically a defect.
4. Verify associations both from a loaded model and a relationship query. Test eager loading, custom owner keys, ambiguous provider IDs, historical team changes and null/unsupported references.
5. Run schema changes only against a verified local/disposable environment first. Preserve columns, existing key types and model lifecycle behavior. Do not automatically backfill, delete or merge records during an audit.

C41 produces the full map. C42 corrects useful Eloquent declarations. C43 verifies and repairs relational constraints. C44 defines dynamic identity resolution. C45 protects temporal associations. These IDs do not mark C04/C08/C20/C26/C28 complete automatically.

## Implemented relationship corrections

- The six Team forecast builders are explicitly named queryPredictions, and unused NFL Prediction team accessors were removed; callers load game.homeTeam/game.awayTeam explicitly.
- OAuthUser::clients now uses owner_id and owner_type to match the current Passport migration; the deprecated user_id implementation was incompatible with the schema. oauthApps remains preferred.
- GameDataCheck.game, GameContextFact.game/team and ResearchDocument.source expose their existing ownership FKs.
- UserBet prediction and legacy generic PlayerProp gameable relations allowlist supported models before eager or lazy resolution. Stored type/sport/event mismatches return no association rather than querying arbitrary classes or another sport.
- SportEventIdentitySynchronizer validates the game model against the explicit sport registry before writing. SportRecordResolver provides provider, table/ID, public-ID, canonical-detail and source-type identity resolution without applying publication/approval policy.
- The additive group_join_links group_id unique index migration refuses existing duplicates without deleting them. Its predecessor's unique call after constrained did not create the index in the migrated schema.
- Calibration observations now copy immutable snapshot outputs and recorded result identity into readonly DTOs with the former decimal rounding. MLB export horizon travels beside the snapshot, not as a dirty model attribute.

Regression tests cover source/team transfers, custom ESPN pitcher owner keys, explicit forecast-source resolution, unknown/mismatched morph types, provider/sport conflicts, constraint enforcement, duplicate-preflight preservation, dynamic reference audit counts, stage independence, and existing forecast/export contracts.

## Retained roster writer policy

Legacy basketball boxscore ingestion still updates the mutable Player.team_id from the imported athlete/team identity. This is retained source-compatible behavior under C06, including when historical boxscores are imported. C45 does not redesign the current-roster writer or claim that a historical backfill cannot change current roster membership. Its guarantee is that historical readers use recorded stat.team_id, game identity and frozen snapshots rather than deriving historical ownership from the mutable Player.team relation. Any separate point-in-time roster writer policy needs an explicit follow-up and numerical/input review.
