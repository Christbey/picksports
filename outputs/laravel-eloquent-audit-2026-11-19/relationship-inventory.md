# Model declaration inventory

Environment: newly migrated, disposable SQLite in memory. These are source/schema facts, not confirmation of production data. Every concrete application model is listed, including models with no declared associations. Detailed keys, nullable behavior, indexes, foreign-key deletion policies, inverse candidates, and source reference candidates are in [the JSON inventory](relationship-inventory.json). Counts from [the read-only integrity audit](relationship-integrity.json) describe this disposable database only.

| Model | Table | Declared associations |
| --- | --- | --- |
| AiGeneration | ai_generations | None; explicit query/storage role must be retained or reviewed. |
| ApiIdempotencyKey | api_idempotency_keys | None; explicit query/storage role must be retained or reviewed. |
| ApplicationSetting | application_settings | None; explicit query/storage role must be retained or reviewed. |
| BetDecision | bet_decisions | featureSnapshot: BelongsTo; modelArtifact: BelongsTo; modelRun: BelongsTo; oddsSnapshot: BelongsTo; settlement: HasOne; shadowOutput: BelongsTo |
| BetSettlement | bet_settlements | decision: BelongsTo |
| CBB\EloRating | cbb_elo_ratings | game: BelongsTo; team: BelongsTo |
| CBB\Game | cbb_games | awayTeam: BelongsTo; homeTeam: BelongsTo; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany |
| CBB\Play | cbb_plays | game: BelongsTo; possessionTeam: BelongsTo |
| CBB\Player | cbb_players | activeInjuries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| CBB\PlayerInjury | cbb_player_injuries | player: BelongsTo; team: BelongsTo |
| CBB\PlayerProp | cbb_player_props | game: BelongsTo; player: BelongsTo |
| CBB\PlayerStat | cbb_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| CBB\Prediction | cbb_predictions | game: BelongsTo |
| CBB\Team | cbb_teams | activePlayerInjuries: HasMany; awayGames: HasMany; eloRatings: HasMany; homeGames: HasMany; metrics: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; plays: HasMany; teamMetrics: HasMany; teamStats: HasMany; tournamentForecasts: HasMany |
| CBB\TeamMetric | cbb_team_metrics | team: BelongsTo |
| CBB\TeamPossessionMetric | cbb_team_possession_metrics | team: BelongsTo |
| CBB\TeamStat | cbb_team_stats | game: BelongsTo; team: BelongsTo |
| CBB\TournamentForecast | cbb_tournament_forecasts | snapshot: BelongsTo; team: BelongsTo |
| CBB\TournamentStateSnapshot | cbb_tournament_state_snapshots | forecasts: HasMany; triggerGame: BelongsTo |
| CFB\EloRating | cfb_elo_ratings | game: BelongsTo; team: BelongsTo |
| CFB\FpiRating | cfb_fpi_ratings | team: BelongsTo |
| CFB\Game | cfb_games | awayTeam: BelongsTo; contextSignal: HasOne; homeTeam: BelongsTo; latestLiveSnapshot: HasOne; liveSnapshots: HasMany; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany; weather: HasOne |
| CFB\GameContextSignal | cfb_game_context_signals | awayTeam: BelongsTo; game: BelongsTo; homeTeam: BelongsTo |
| CFB\GameDataCheck | cfb_game_data_checks | game: BelongsTo |
| CFB\GameWeather | cfb_game_weather | game: BelongsTo |
| CFB\LivePredictionSnapshot | cfb_live_prediction_snapshots | game: BelongsTo |
| CFB\Play | cfb_plays | game: BelongsTo; possessionTeam: BelongsTo |
| CFB\Player | cfb_players | activeInjuries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| CFB\PlayerInjury | cfb_player_injuries | player: BelongsTo; team: BelongsTo |
| CFB\PlayerProp | cfb_player_props | game: BelongsTo; player: BelongsTo |
| CFB\PlayerStat | cfb_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| CFB\Prediction | cfb_predictions | game: BelongsTo |
| CFB\PredictionCalibration | cfb_prediction_calibrations | None; explicit query/storage role must be retained or reviewed. |
| CFB\PreseasonTeamSignal | cfb_preseason_team_signals | team: BelongsTo |
| CFB\ResultRatingRun | cfb_result_rating_runs | None; explicit query/storage role must be retained or reviewed. |
| CFB\Team | cfb_teams | activePlayerInjuries: HasMany; awayGames: HasMany; eloRatings: HasMany; fpiRatings: HasMany; homeGames: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; plays: HasMany; preseasonTeamSignals: HasMany; seasonAffiliations: HasMany; teamStats: HasMany |
| CFB\TeamMetric | cfb_team_metrics | team: BelongsTo |
| CFB\TeamSeasonAffiliation | cfb_team_season_affiliations | team: BelongsTo |
| CFB\TeamStat | cfb_team_stats | game: BelongsTo; team: BelongsTo |
| CalculationRelease | calculation_releases | components: HasMany; runs: HasMany |
| CalculationReleaseComponent | calculation_release_components | modelArtifact: BelongsTo; release: BelongsTo |
| CalculationRun | calculation_runs | inputSnapshot: BelongsTo; prediction: HasOne; release: BelongsTo; sportEvent: BelongsTo |
| CanonicalPrediction | predictions | calculationRun: BelongsTo; datasetExportManifest: BelongsTo; evaluations: HasMany; featureSchema: BelongsTo; latestEvaluation: HasOne; markets: HasMany; modelArtifact: BelongsTo; modelRun: BelongsTo; revisions: HasMany; sportEvent: BelongsTo; supersedes: BelongsTo; trackedWcbbSpread: HasOne |
| CbbBracket | cbb_brackets | group: BelongsTo; user: BelongsTo |
| CfbdTeamMapping | cfbd_team_mappings | None; explicit query/storage role must be retained or reviewed. |
| CommandHeartbeat | command_heartbeats | None; explicit query/storage role must be retained or reviewed. |
| DailyDigestSend | daily_digest_sends | user: BelongsTo |
| DatasetExportManifest | dataset_export_manifests | predictions: HasMany |
| DeveloperApiCredential | developer_api_credentials | creator: BelongsTo; organization: BelongsTo; usageRecords: HasMany |
| DeveloperApiUsageRecord | developer_api_usage_records | credential: BelongsTo; entitlementPolicy: BelongsTo; organization: BelongsTo; product: BelongsTo |
| DeveloperEntitlementPolicy | developer_entitlement_policies | organization: BelongsTo; product: BelongsTo; usageRecords: HasMany |
| DeveloperMeterBatch | developer_meter_batches | items: HasMany |
| DeveloperMeterBatchItem | developer_meter_batch_items | batch: BelongsTo; organization: BelongsTo; product: BelongsTo |
| DeveloperOrganization | developer_organizations | creator: BelongsTo; credentials: HasMany; entitlementPolicies: HasMany; memberships: HasMany; meterBatchItems: HasMany; usageRecords: HasMany; webhookEndpoints: HasMany; webhookOutboxEvents: HasMany |
| DeveloperOrganizationMembership | developer_organization_memberships | organization: BelongsTo; user: BelongsTo |
| DeveloperProduct | developer_products | entitlementPolicies: HasMany; meterBatchItems: HasMany; usageRecords: HasMany |
| DeveloperWebhookDelivery | developer_webhook_deliveries | endpoint: BelongsTo; outboxEvent: BelongsTo |
| DeveloperWebhookEndpoint | developer_webhook_endpoints | deliveries: HasMany; organization: BelongsTo |
| DeveloperWebhookOutboxEvent | developer_webhook_outbox_events | deliveries: HasMany; organization: BelongsTo |
| DevicePushRegistration | device_push_registrations | deviceSession: BelongsTo |
| DeviceSession | device_sessions | accessToken: BelongsTo; pushRegistrations: HasMany; refreshTokens: HasMany; user: BelongsTo |
| DeviceSessionRefreshToken | device_session_refresh_tokens | deviceSession: BelongsTo; replacement: BelongsTo |
| EpaStateBaseline | epa_state_baselines | None; explicit query/storage role must be retained or reviewed. |
| EventInputSnapshot | event_input_snapshots | runs: HasMany; sportEvent: BelongsTo |
| FeatureSchema | feature_schemas | predictions: HasMany |
| GameOddsSnapshot | game_odds_snapshots | marketQuotes: HasMany |
| Group | groups | brackets: HasMany; invitations: HasMany; joinLink: HasOne; owner: BelongsTo; users: BelongsToMany |
| GroupInvitation | group_invitations | acceptedBy: BelongsTo; group: BelongsTo; inviter: BelongsTo |
| GroupJoinLink | group_join_links | creator: BelongsTo; group: BelongsTo |
| Healthcheck | healthchecks | None; explicit query/storage role must be retained or reviewed. |
| LivePredictionSnapshot | live_prediction_snapshots | canonicalPrediction: BelongsTo |
| MLB\BetFilterResult | mlb_bet_filter_results | game: BelongsTo; prediction: BelongsTo; team: BelongsTo |
| MLB\BullpenRating | mlb_bullpen_ratings | team: BelongsTo |
| MLB\DepthChartEntry | mlb_depth_chart_entries | player: BelongsTo; team: BelongsTo |
| MLB\EloRating | mlb_elo_ratings | game: BelongsTo; team: BelongsTo |
| MLB\Game | mlb_games | actualAwayPitcher: BelongsTo; actualHomePitcher: BelongsTo; awayStartingPitcherForecast: HasOne; awayTeam: BelongsTo; homeStartingPitcherForecast: HasOne; homeTeam: BelongsTo; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; probableAwayPitcher: BelongsTo; probableHomePitcher: BelongsTo; projectedAwayPitcher: BelongsTo; projectedHomePitcher: BelongsTo; sportEvent: BelongsTo; startingPitcherForecasts: HasMany; teamStats: HasMany; weather: HasOne |
| MLB\GameWeather | mlb_game_weather | game: BelongsTo |
| MLB\PeriodFeatureSnapshot | mlb_period_feature_snapshots | game: BelongsTo |
| MLB\PickCandidate | mlb_pick_candidates | canonicalPrediction: BelongsTo; game: BelongsTo; player: BelongsTo; prediction: BelongsTo; team: BelongsTo |
| MLB\PitcherEloRating | mlb_pitcher_elo_ratings | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| MLB\Play | mlb_plays | battingTeam: BelongsTo; game: BelongsTo; pitchingTeam: BelongsTo |
| MLB\Player | mlb_players | activeInjuries: HasMany; depthChartEntries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| MLB\PlayerInjury | mlb_player_injuries | player: BelongsTo; team: BelongsTo |
| MLB\PlayerProp | mlb_player_props | game: BelongsTo; player: BelongsTo |
| MLB\PlayerStat | mlb_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| MLB\PlayoffForecast | mlb_playoff_forecasts | team: BelongsTo |
| MLB\Prediction | mlb_predictions | game: BelongsTo |
| MLB\StartingPitcherForecast | mlb_starting_pitcher_forecasts | actualPitcher: BelongsTo; game: BelongsTo; predictedPitcher: BelongsTo; team: BelongsTo |
| MLB\Team | mlb_teams | activePlayerInjuries: HasMany; awayGames: HasMany; battingPlays: HasMany; bullpenRatings: HasMany; depthChartEntries: HasMany; eloRatings: HasMany; homeGames: HasMany; metrics: HasMany; pitchingPlays: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; playoffForecasts: HasMany; teamStats: HasMany |
| MLB\TeamMetric | mlb_team_metrics | team: BelongsTo |
| MLB\TeamStat | mlb_team_stats | game: BelongsTo; team: BelongsTo |
| MarketQuote | market_quotes | gameOddsSnapshot: BelongsTo |
| ModelArtifact | model_artifacts | canonicalPredictions: HasMany; shadowOutputs: HasMany; trainingRun: BelongsTo |
| ModelRun | model_runs | artifacts: HasMany; canonicalPredictions: HasMany; shadowOutputs: HasMany |
| NBA\DepthChartEntry | nba_depth_chart_entries | player: BelongsTo; team: BelongsTo |
| NBA\EloRating | nba_elo_ratings | game: BelongsTo; team: BelongsTo |
| NBA\Game | nba_games | awayTeam: BelongsTo; homeTeam: BelongsTo; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany |
| NBA\Play | nba_plays | game: BelongsTo; possessionTeam: BelongsTo |
| NBA\Player | nba_players | activeInjuries: HasMany; depthChartEntries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| NBA\PlayerInjury | nba_player_injuries | player: BelongsTo; team: BelongsTo |
| NBA\PlayerProp | nba_player_props | game: BelongsTo; player: BelongsTo |
| NBA\PlayerStat | nba_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| NBA\PlayoffForecast | nba_playoff_forecasts | team: BelongsTo |
| NBA\Prediction | nba_predictions | game: BelongsTo |
| NBA\Team | nba_teams | activePlayerInjuries: HasMany; awayGames: HasMany; depthChartEntries: HasMany; eloRatings: HasMany; homeGames: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; playoffForecasts: HasMany; plays: HasMany; teamMetrics: HasMany; teamStats: HasMany |
| NBA\TeamMetric | nba_team_metrics | team: BelongsTo |
| NBA\TeamStat | nba_team_stats | game: BelongsTo; team: BelongsTo |
| NFL\Coach | nfl_coaches | teamSeasons: HasMany |
| NFL\DepthChartEntry | nfl_depth_chart_entries | player: BelongsTo; team: BelongsTo |
| NFL\DepthChartSnapshot | nfl_depth_chart_snapshots | entries: HasMany; team: BelongsTo |
| NFL\DepthChartSnapshotEntry | nfl_depth_chart_snapshot_entries | player: BelongsTo; snapshot: BelongsTo |
| NFL\EloRating | nfl_elo_ratings | game: BelongsTo; team: BelongsTo |
| NFL\Game | nfl_games | awayDepthChartLinks: HasMany; awayTeam: BelongsTo; contextFacts: HasMany; depthChartLinks: HasMany; homeDepthChartLinks: HasMany; homeTeam: BelongsTo; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany; weather: HasOne |
| NFL\GameContextFact | nfl_game_context_facts | game: BelongsTo; team: BelongsTo |
| NFL\GameDepthChartLink | nfl_game_depth_chart_links | game: BelongsTo; predictionFeatureSnapshot: BelongsTo; snapshot: BelongsTo; team: BelongsTo |
| NFL\GameWeather | nfl_game_weather | game: BelongsTo |
| NFL\Play | nfl_plays | game: BelongsTo; possessionTeam: BelongsTo |
| NFL\Player | nfl_players | activeInjuries: HasMany; depthChartEntries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| NFL\PlayerInjury | nfl_player_injuries | player: BelongsTo; team: BelongsTo |
| NFL\PlayerInjurySnapshot | nfl_player_injury_snapshots | entries: HasMany; team: BelongsTo |
| NFL\PlayerInjurySnapshotEntry | nfl_player_injury_snapshot_entries | player: BelongsTo; snapshot: BelongsTo |
| NFL\PlayerProp | nfl_player_props | game: BelongsTo; player: BelongsTo |
| NFL\PlayerStat | nfl_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| NFL\Prediction | nfl_predictions | game: BelongsTo |
| NFL\ResearchDocument | nfl_research_documents | source: BelongsTo |
| NFL\ResearchRevision | nfl_research_revisions | game: BelongsTo |
| NFL\ResearchSource | nfl_research_sources | None; explicit query/storage role must be retained or reviewed. |
| NFL\Team | nfl_teams | activePlayerInjuries: HasMany; awayGames: HasMany; coachSeasons: HasMany; depthChartEntries: HasMany; eloRatings: HasMany; homeGames: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; plays: HasMany; teamMetrics: HasMany; teamStats: HasMany |
| NFL\TeamCoachSeason | nfl_team_coach_seasons | coach: BelongsTo; team: BelongsTo |
| NFL\TeamMetric | nfl_team_metrics | team: BelongsTo |
| NFL\TeamMetricSnapshot | nfl_team_metric_snapshots | team: BelongsTo |
| NFL\TeamStat | nfl_team_stats | game: BelongsTo; team: BelongsTo |
| NflSignalGrade | nfl_signal_grades | betDecision: BelongsTo; betSettlement: BelongsTo; observation: BelongsTo |
| NflSignalObservation | nfl_signal_observations | featureSnapshot: BelongsTo; game: BelongsTo; grades: HasMany; modelRun: BelongsTo |
| NotificationChannel | notification_channels | None; explicit query/storage role must be retained or reviewed. |
| NotificationDelivery | notification_deliveries | None; explicit query/storage role must be retained or reviewed. |
| NotificationTemplate | notification_templates | None; explicit query/storage role must be retained or reviewed. |
| NotificationTemplateDefault | notification_template_defaults | template: BelongsTo |
| OAuthUser | users | clients: HasMany; oauthApps: MorphMany; tokens: HasMany |
| OauthAccount | oauth_accounts | user: BelongsTo |
| OddsApiPlayerMapping | odds_api_player_mappings | None; explicit query/storage role must be retained or reviewed. |
| OddsApiTeamMapping | odds_api_team_mappings | None; explicit query/storage role must be retained or reviewed. |
| Passkey | passkeys | user: BelongsTo |
| PlayerProp | player_props | gameable: AllowedLegacyPropGameMorphTo; player: BelongsTo (context required) |
| PredictionEvaluation | prediction_evaluations | canonicalPrediction: BelongsTo; corrections: HasMany; eventResult: BelongsTo; sportEvent: BelongsTo; supersedes: BelongsTo |
| PredictionFeatureSnapshot | prediction_feature_snapshots | modelRun: BelongsTo; shadowOutputs: HasMany |
| PredictionMarket | prediction_markets | prediction: BelongsTo |
| ProviderImportManifest | provider_import_manifests | sourceFile: BelongsTo |
| ProviderSourceFile | provider_source_files | imports: HasMany |
| ShadowModelOutput | shadow_model_outputs | artifact: BelongsTo; betDecisions: HasMany; canonicalPrediction: BelongsTo; featureSnapshot: BelongsTo; inferenceRun: BelongsTo |
| SportEvent | sport_events | calculationRuns: HasMany; cbbGame: HasOne; cfbGame: HasOne; inputSnapshots: HasMany; mlbGame: HasOne; nbaGame: HasOne; nflGame: HasOne; predictions: HasMany; providerMappings: HasMany; results: HasMany; wcbbGame: HasOne; wnbaGame: HasOne |
| SportEventProviderMapping | sport_event_provider_mappings | sportEvent: BelongsTo |
| SportEventResult | sport_event_results | corrections: HasMany; evaluations: HasMany; sportEvent: BelongsTo; supersedes: BelongsTo |
| SportsAiPredictionAnalysis | sports_ai_prediction_analyses | None; explicit query/storage role must be retained or reviewed. |
| SportsGameContextReport | sports_game_context_reports | None; explicit query/storage role must be retained or reviewed. |
| Sports\FuturesOdd | sports_futures_odds | cbbTeam: BelongsTo; mlbTeam: BelongsTo; nbaTeam: BelongsTo; nflPlayer: BelongsTo; nflTeam: BelongsTo; wcbbTeam: BelongsTo |
| Sports\FuturesOddsSnapshot | sports_futures_odds_snapshots | cbbTeam: BelongsTo; mlbTeam: BelongsTo; nbaTeam: BelongsTo; nflPlayer: BelongsTo; nflTeam: BelongsTo; wcbbTeam: BelongsTo |
| Submission | submissions | user: BelongsTo |
| SubscriptionTier | subscription_tiers | None; explicit query/storage role must be retained or reviewed. |
| User | users | alertPreference: HasOne; alertsSent: HasMany; bets: HasMany; notifications: MorphMany; oauthAccounts: HasMany; passkeys: HasMany; permissions: MorphToMany; roles: MorphToMany; subscriptions: HasMany; tokens: MorphMany; webPushSubscriptions: HasMany |
| UserAlertPreference | user_alert_preferences | user: BelongsTo |
| UserAlertSent | user_alerts_sent | user: BelongsTo |
| UserBet | user_bets | prediction: AllowedPredictionMorphTo; sportEvent: BelongsTo; user: BelongsTo |
| ValidationFinding | validation_findings | run: BelongsTo |
| ValidationRun | validation_runs | findings: HasMany |
| WCBB\EloRating | wcbb_elo_ratings | game: BelongsTo; team: BelongsTo |
| WCBB\Game | wcbb_games | awayTeam: BelongsTo; homeTeam: BelongsTo; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany |
| WCBB\Play | wcbb_plays | game: BelongsTo; possessionTeam: BelongsTo |
| WCBB\Player | wcbb_players | activeInjuries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| WCBB\PlayerInjury | wcbb_player_injuries | player: BelongsTo; team: BelongsTo |
| WCBB\PlayerStat | wcbb_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| WCBB\Prediction | wcbb_predictions | game: BelongsTo |
| WCBB\Team | wcbb_teams | activePlayerInjuries: HasMany; awayGames: HasMany; eloRatings: HasMany; homeGames: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; plays: HasMany; teamMetrics: HasMany; teamStats: HasMany; tournamentForecasts: HasMany |
| WCBB\TeamMetric | wcbb_team_metrics | team: BelongsTo |
| WCBB\TeamStat | wcbb_team_stats | game: BelongsTo; team: BelongsTo |
| WCBB\TournamentForecast | wcbb_tournament_forecasts | team: BelongsTo |
| WNBA\EloRating | wnba_elo_ratings | game: BelongsTo; team: BelongsTo |
| WNBA\Game | wnba_games | awayTeam: BelongsTo; homeTeam: BelongsTo; playerProps: HasMany; playerStats: HasMany; plays: HasMany; prediction: HasOne; sportEvent: BelongsTo; teamStats: HasMany |
| WNBA\Play | wnba_plays | game: BelongsTo; possessionTeam: BelongsTo |
| WNBA\Player | wnba_players | activeInjuries: HasMany; injuries: HasMany; playerStats: HasMany; team: BelongsTo |
| WNBA\PlayerInjury | wnba_player_injuries | player: BelongsTo; team: BelongsTo |
| WNBA\PlayerProp | wnba_player_props | game: BelongsTo; player: BelongsTo |
| WNBA\PlayerStat | wnba_player_stats | game: BelongsTo; player: BelongsTo; team: BelongsTo |
| WNBA\Prediction | wnba_predictions | game: BelongsTo |
| WNBA\Team | wnba_teams | activePlayerInjuries: HasMany; awayGames: HasMany; eloRatings: HasMany; homeGames: HasMany; playerInjuries: HasMany; playerStats: HasMany; players: HasMany; plays: HasMany; teamMetrics: HasMany; teamStats: HasMany |
| WNBA\TeamMetric | wnba_team_metrics | team: BelongsTo |
| WNBA\TeamStat | wnba_team_stats | game: BelongsTo; team: BelongsTo |
| WebPushSubscription | web_push_subscriptions | user: BelongsTo |

All application model tables exist in the migrated fixture; declared associations reference existing FK/owner columns where statically resolvable. Legacy `App\Models\PlayerProp::player` needs a provider-sport context and remains explicitly unresolved in the blank-model inventory. Direct untyped relation builders in inherited traits are included; external package target schema metadata is cataloged separately.

Producer/consumer references are automatically identified import candidates. They do not prove historical correctness or constitute completed semantic review of every path. See the reviewed domain ownership/retention policies and the clearly scoped disposable-schema data checks in relationship-ownership-review.md.
