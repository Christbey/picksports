<?php

namespace App\Http\Resources\Api\V2;

use App\Actions\WNBA\CalculateBettingValue as WnbaCalculateBettingValue;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\Api\V2\SportContext;
use App\Services\MLB\MlbMarketAwareProjectionService;
use App\Services\MLB\MlbPredictionRecommendationService;
use App\Support\MLB\MlbGamePhase;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SportPredictionResource extends JsonResource
{
    private bool $mlbRecommendationResolved = false;

    /** @var array<string,mixed>|null */
    private ?array $mlbRecommendation = null;

    public function __construct(
        mixed $resource,
        private readonly SportContext $context,
        private readonly ?array $mlbPeriodInsights = null,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->attribute('id'),
            'sport' => $this->context->slug,
            'game_id' => $this->attribute('game_id'),
            'home_team_id' => $this->gameAttribute('home_team_id'),
            'away_team_id' => $this->gameAttribute('away_team_id'),
            'game' => $this->game(),
            'status' => $this->gameAttribute('status'),
            'pick' => $this->pick(),
            'projection' => $this->projection(),
            'home_win_probability' => $this->homeWinProbability(),
            'away_win_probability' => $this->awayWinProbability(),
            'win_probability' => $this->homeWinProbability(),
            'predicted_spread' => $this->floatAttribute('predicted_spread'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'confidence_score' => $this->floatAttribute('confidence_score'),
            'confidence_level' => $this->confidenceLevel(),
            'confidence_context' => $this->confidenceContext(),
            'public_recommendation' => $this->publicRecommendation(),
            'value_signal' => $this->valueSignal(),
            'market_aware_projection' => $this->marketAwareProjection(),
            'recommendation' => $this->recommendation(),
            'pro_signal_layer' => $this->proSignalLayer(),
            'prediction_analysis' => $this->predictionAnalysis(),
            'period_insights' => $this->context->slug === 'mlb' ? ($this->mlbPeriodInsights ?? []) : [],
            'cfb_signal_context' => $this->cfbSignalContext(),
            'home_elo' => $this->floatAttribute('home_elo'),
            'away_elo' => $this->floatAttribute('away_elo'),
            'home_team_elo' => $this->floatAttribute('home_team_elo'),
            'away_team_elo' => $this->floatAttribute('away_team_elo'),
            'home_pitcher_elo' => $this->floatAttribute('home_pitcher_elo'),
            'away_pitcher_elo' => $this->floatAttribute('away_pitcher_elo'),
            'home_combined_elo' => $this->floatAttribute('home_combined_elo'),
            'away_combined_elo' => $this->floatAttribute('away_combined_elo'),
            'actual_spread' => $this->floatAttribute('actual_spread'),
            'actual_total' => $this->floatAttribute('actual_total'),
            'spread_error' => $this->floatAttribute('spread_error'),
            'total_error' => $this->floatAttribute('total_error'),
            'winner_correct' => $this->attribute('winner_correct'),
            'total_pick_side' => $this->attribute('total_pick_side'),
            'total_pick_line' => $this->floatAttribute('total_pick_line'),
            'total_pick_result' => $this->attribute('total_pick_result'),
            'total_pick_edge' => $this->floatAttribute('total_pick_edge'),
            'total_result' => $this->totalResult(),
            'graded_at' => $this->serializeDateValue($this->attribute('graded_at')),
            'live_predicted_spread' => $this->liveFloatAttribute('live_predicted_spread'),
            'live_predicted_total' => $this->liveFloatAttribute('live_predicted_total'),
            'live_win_probability' => $this->liveFloatAttribute('live_win_probability'),
            'live_seconds_remaining' => $this->liveAttribute('live_seconds_remaining'),
            'live_outs_remaining' => $this->liveAttribute('live_outs_remaining'),
            'live_updated_at' => $this->serializeDateValue($this->liveAttribute('live_updated_at')),
            'depth_chart_context' => $this->depthChartContext(),
            'market_summary' => $this->marketSummary(),
            'audit_context' => $this->auditContext(),
            'created_at' => $this->serializeDateValue($this->attribute('created_at')),
            'updated_at' => $this->serializeDateValue($this->attribute('updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function game(): ?array
    {
        $game = $this->relation('game');

        if (! $game) {
            return null;
        }

        $dateTime = GameDateTimePresenter::forSport(
            $this->context->slug,
            $game->getAttribute('game_date'),
            $game->getAttribute('game_time'),
        );

        return [
            'id' => $game->getAttribute('id'),
            'espn_id' => $game->getAttribute('espn_id'),
            'season' => $game->getAttribute('season'),
            'season_type' => $game->getAttribute('season_type'),
            'week' => $game->getAttribute('week'),
            'name' => $game->getAttribute('name'),
            'short_name' => $game->getAttribute('short_name'),
            'game_date' => $dateTime['game_date'],
            'game_time' => $dateTime['game_time'],
            'status' => $game->getAttribute('status'),
            'home_score' => $game->getAttribute('home_score'),
            'away_score' => $game->getAttribute('away_score'),
            'home_linescores' => $this->context->slug === 'mlb' ? $game->getAttribute('home_linescores') : null,
            'away_linescores' => $this->context->slug === 'mlb' ? $game->getAttribute('away_linescores') : null,
            'inning' => $game->getAttribute('inning'),
            'inning_half' => $game->getAttribute('inning_half') ?? $game->getAttribute('inning_state'),
            'balls' => $game->getAttribute('balls'),
            'strikes' => $game->getAttribute('strikes'),
            'outs' => $game->getAttribute('outs'),
            'home_team_id' => $game->getAttribute('home_team_id'),
            'away_team_id' => $game->getAttribute('away_team_id'),
            'home_team' => $this->team($game, 'homeTeam'),
            'away_team' => $this->team($game, 'awayTeam'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pick(): array
    {
        $winProbability = $this->floatAttribute('win_probability');
        $game = $this->relation('game');
        $side = $winProbability === null ? null : ($winProbability >= 0.5 ? 'home' : 'away');
        $team = $game && $side ? $this->teamModel($game, "{$side}Team") : null;

        return [
            'side' => $side,
            'team_id' => $team?->getAttribute('id') ?? ($game?->getAttribute("{$side}_team_id")),
            'team_abbreviation' => $team?->getAttribute('abbreviation'),
            'label' => $team?->getAttribute('abbreviation') ?? $team?->getAttribute('display_name'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projection(): array
    {
        return [
            'home_win_probability' => $this->homeWinProbability(),
            'away_win_probability' => $this->awayWinProbability(),
            'predicted_spread' => $this->floatAttribute('predicted_spread'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'confidence_score' => $this->floatAttribute('confidence_score'),
            'confidence_context' => $this->confidenceContext(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketSummary(): array
    {
        $game = $this->relation('game');
        $markets = $this->marketKeys($game);
        $hasSpread = $this->attribute('vegas_spread') !== null;

        if ($hasSpread && ! in_array('spread', $markets, true)) {
            $markets[] = 'spread';
        }

        return [
            'has_odds' => $markets !== [],
            'markets' => $markets,
            'odds_updated_at' => $this->serializeDateValue($game?->getAttribute('odds_updated_at')),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function team(Model $game, string $relation): ?array
    {
        $team = $this->teamModel($game, $relation);

        if (! $team) {
            return null;
        }

        return [
            'id' => $team->getAttribute('id'),
            'abbreviation' => $team->getAttribute('abbreviation'),
            'display_name' => $team->getAttribute('display_name')
                ?? trim((string) ($team->getAttribute('location') ?? $team->getAttribute('school')).' '.(string) ($team->getAttribute('name') ?? $team->getAttribute('mascot'))),
            'logo_url' => $team->getAttribute('logo_url'),
        ];
    }

    private function teamModel(Model $game, string $relation): ?Model
    {
        if (! $game->relationLoaded($relation)) {
            return null;
        }

        $team = $game->getRelation($relation);

        return $team instanceof Model ? $team : null;
    }

    /**
     * @return array<int, string>
     */
    private function marketKeys(?Model $game): array
    {
        $oddsData = $game?->getAttribute('odds_data');
        if (! is_array($oddsData)) {
            return [];
        }

        $markets = [];

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            if (! is_array($bookmaker)) {
                continue;
            }

            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (! is_array($market)) {
                    continue;
                }

                $key = match ((string) ($market['key'] ?? '')) {
                    'spreads' => 'spread',
                    'totals' => 'total',
                    'h2h' => 'moneyline',
                    'totals_1st_1_innings', 'totals_1st_1' => 'first_inning',
                    'h2h_1st_3_innings', 'h2h_1st_3',
                    'totals_1st_3_innings', 'totals_1st_3' => 'first_3',
                    'h2h_1st_5_innings', 'h2h_1st_5',
                    'spreads_1st_5_innings', 'spreads_1st_5',
                    'totals_1st_5_innings', 'totals_1st_5' => 'first_5',
                    default => null,
                };

                if ($key !== null) {
                    $markets[] = $key;
                }
            }
        }

        return array_values(array_unique($markets));
    }

    private function relation(string $relation): ?Model
    {
        if (! $this->resource instanceof Model || ! $this->resource->relationLoaded($relation)) {
            return null;
        }

        $related = $this->resource->getRelation($relation);

        return $related instanceof Model ? $related : null;
    }

    private function gameAttribute(string $key): mixed
    {
        return $this->relation('game')?->getAttribute($key);
    }

    private function homeWinProbability(): ?float
    {
        $winProbability = $this->floatAttribute('win_probability');

        return $winProbability === null ? null : round($winProbability, 3);
    }

    private function awayWinProbability(): ?float
    {
        $homeWinProbability = $this->homeWinProbability();

        return $homeWinProbability === null ? null : round(1 - $homeWinProbability, 3);
    }

    private function confidenceLevel(): string
    {
        $confidenceScore = $this->floatAttribute('confidence_score');

        return $this->rawConfidenceLevel($confidenceScore);
    }

    private function rawConfidenceLevel(?float $confidenceScore): string
    {
        if ($confidenceScore === null) {
            return 'unavailable';
        }

        return match (true) {
            $confidenceScore >= 75 => 'high',
            $confidenceScore >= 60 => 'medium',
            default => 'low',
        };
    }

    /**
     * @return array{label:string,tier:string,raw_level:string,reason_codes:array<int,string>,sample_games:int|null}
     */
    private function confidenceContext(): array
    {
        $confidenceScore = $this->floatAttribute('confidence_score');
        $rawLevel = $this->rawConfidenceLevel($confidenceScore);
        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];
        $sampleGames = $this->confidenceSampleGames($metadata);
        $reasonCodes = [];

        if ($confidenceScore === null) {
            return [
                'label' => 'Unavailable',
                'tier' => 'unavailable',
                'raw_level' => $rawLevel,
                'reason_codes' => ['missing_confidence_score'],
                'sample_games' => $sampleGames,
            ];
        }

        if ($sampleGames === null) {
            $reasonCodes[] = 'sample_context_missing';
        } elseif ($sampleGames < 8) {
            $reasonCodes[] = 'thin_sample_context';
        } elseif ($sampleGames < 15) {
            $reasonCodes[] = 'limited_sample_context';
        }

        $pitcherConfidence = $this->minimumPitcherConfidence($metadata);
        if ($pitcherConfidence !== null && $pitcherConfidence < 0.75) {
            $reasonCodes[] = 'probable_pitcher_context_low_confidence';
        }

        $marketContext = is_array($metadata['market_context'] ?? null) ? $metadata['market_context'] : [];
        if (
            $rawLevel === 'high'
            && $this->context->slug !== 'mlb'
            && $marketContext !== []
            && ! (bool) ($marketContext['spread_available'] ?? $marketContext['moneyline_available'] ?? false)
        ) {
            $reasonCodes[] = 'market_context_not_confirmed';
        }

        $blockingReasons = array_intersect($reasonCodes, [
            'sample_context_missing',
            'thin_sample_context',
            'probable_pitcher_context_low_confidence',
            'market_context_not_confirmed',
        ]);

        if ($rawLevel === 'high' && $blockingReasons === []) {
            return [
                'label' => 'High',
                'tier' => 'high',
                'raw_level' => $rawLevel,
                'reason_codes' => $reasonCodes,
                'sample_games' => $sampleGames,
            ];
        }

        if ($rawLevel === 'high') {
            return [
                'label' => 'Watch',
                'tier' => 'watch',
                'raw_level' => $rawLevel,
                'reason_codes' => $reasonCodes,
                'sample_games' => $sampleGames,
            ];
        }

        if ($rawLevel === 'medium') {
            return [
                'label' => 'Medium',
                'tier' => 'medium',
                'raw_level' => $rawLevel,
                'reason_codes' => $reasonCodes,
                'sample_games' => $sampleGames,
            ];
        }

        return [
            'label' => 'Low',
            'tier' => 'low',
            'raw_level' => $rawLevel,
            'reason_codes' => $reasonCodes,
            'sample_games' => $sampleGames,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function proSignalLayer(): ?array
    {
        if ($this->context->slug !== 'nfl') {
            return null;
        }

        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];
        $layer = data_get($metadata, 'analysis_layer.pro_signal_layer');

        return is_array($layer) ? $layer : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function predictionAnalysis(): ?array
    {
        if ($this->context->slug !== 'nfl') {
            return null;
        }

        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];
        $analysis = data_get($metadata, 'analysis_layer');

        if (! is_array($analysis)) {
            return null;
        }

        return [
            'enabled' => (bool) ($analysis['enabled'] ?? true),
            'applied' => (bool) ($analysis['applied'] ?? false),
            'trust_score' => isset($analysis['trust_score']) ? (float) $analysis['trust_score'] : null,
            'bet_classification' => $analysis['bet_classification'] ?? null,
            'model_signal_classification' => $analysis['model_signal_classification'] ?? null,
            'risk_flags' => array_values((array) ($analysis['risk_flags'] ?? [])),
            'reason_codes' => array_values((array) ($analysis['reason_codes'] ?? [])),
            'validated_signals' => array_values((array) ($analysis['validated_signals'] ?? [])),
            'best_validated_signal' => $analysis['best_validated_signal'] ?? null,
            'bet_rule_evaluation' => $analysis['bet_rule_evaluation'] ?? null,
            'calculated_edge' => $analysis['calculated_edge'] ?? null,
            'analysis_confidence' => $analysis['analysis_confidence'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cfbSignalContext(): ?array
    {
        if ($this->context->slug !== 'cfb') {
            return null;
        }

        $snapshot = $this->latestFeatureSnapshot();
        $metadata = is_array($snapshot?->model_metadata)
            ? $snapshot->model_metadata
            : (is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : []);

        if ($metadata === []) {
            return null;
        }

        $preseason = $this->sanitizeCfbPreseasonLayer(data_get($metadata, 'cfb_preseason_layer'));
        $gameContext = $this->sanitizeCfbGameContext(data_get($metadata, 'cfb_game_context'));
        $marketMovement = $this->sanitizeCfbMarketMovement(data_get($metadata, 'cfb_market_movement'));
        $advancedMetricLayer = $this->sanitizeCfbAdjustmentList(data_get($metadata, 'cfb_advanced_metric_layer'));
        $playerAvailability = $this->sanitizeCfbPlayerAvailability(data_get($metadata, 'cfb_player_availability'));

        if (! $preseason && ! $gameContext && ! $marketMovement && ! $advancedMetricLayer && ! $playerAvailability) {
            return null;
        }

        return [
            'preseason_layer' => $preseason,
            'game_context' => $gameContext,
            'market_movement' => $marketMovement,
            'advanced_metric_layer' => $advancedMetricLayer,
            'player_availability' => $playerAvailability,
            'audit' => [
                'feature_snapshot_at' => $this->serializeDateValue($snapshot?->generated_at),
                'snapshot_run_id' => $snapshot?->snapshot_run_id,
                'feature_version' => $snapshot?->feature_version ?? $this->attribute('feature_version'),
                'blend_version' => $snapshot?->blend_version ?? $this->attribute('blend_version'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbPreseasonLayer(mixed $layer): ?array
    {
        if (! is_array($layer)) {
            return null;
        }

        return [
            'enabled' => (bool) ($layer['enabled'] ?? false),
            'week' => $this->nullableInt($layer['week'] ?? null),
            'week_bucket' => $layer['week_bucket'] ?? null,
            'spread_adjustment' => $this->nullableFloat($layer['spread_adjustment'] ?? null),
            'total_adjustment' => $this->nullableFloat($layer['total_adjustment'] ?? null),
            'confidence_penalty' => $this->nullableFloat($layer['confidence_penalty'] ?? null),
            'aligned_signals' => $this->nullableInt($layer['aligned_signals'] ?? null),
            'risk_flags' => $this->stringList($layer['risk_flags'] ?? []),
            'components' => $this->sanitizeCfbAdjustmentList($layer['components'] ?? null),
            'market' => $this->sanitizeCfbMarketMovement($layer['market'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbGameContext(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        $weather = is_array($context['weather'] ?? null) ? $context['weather'] : [];
        $venue = is_array($context['venue'] ?? null) ? $context['venue'] : [];
        $schedule = is_array($context['schedule'] ?? null) ? $context['schedule'] : [];
        $persisted = is_array($context['persisted_signals'] ?? null) ? $context['persisted_signals'] : [];

        return [
            'enabled' => (bool) ($context['enabled'] ?? false),
            'spread_adjustment' => $this->nullableFloat($context['spread_adjustment'] ?? null),
            'total_adjustment' => $this->nullableFloat($context['total_adjustment'] ?? null),
            'confidence_penalty' => $this->nullableFloat($context['confidence_penalty'] ?? null),
            'risk_flags' => $this->stringList($context['risk_flags'] ?? []),
            'weather' => $weather === [] ? null : [
                'available' => (bool) ($weather['available'] ?? false),
                'signal' => $weather['signal'] ?? null,
                'total_adjustment' => $this->nullableFloat($weather['total_adjustment'] ?? null),
                'confidence_penalty' => $this->nullableFloat($weather['confidence_penalty'] ?? null),
                'risk_flags' => $this->stringList($weather['risk_flags'] ?? []),
                'inputs' => $this->sanitizeCfbWeatherInputs($weather['inputs'] ?? null),
            ],
            'venue' => $venue === [] ? null : [
                'neutral_site' => (bool) ($venue['neutral_site'] ?? false),
                'conference_game' => (bool) ($venue['conference_game'] ?? false),
                'rivalry_game' => (bool) ($venue['rivalry_game'] ?? false),
                'venue_name' => $venue['venue_name'] ?? null,
                'venue_city' => $venue['venue_city'] ?? null,
                'venue_state' => $venue['venue_state'] ?? null,
                'risk_flags' => $this->stringList($venue['risk_flags'] ?? []),
            ],
            'schedule' => $schedule === [] ? null : [
                'spread_adjustment' => $this->nullableFloat($schedule['spread_adjustment'] ?? null),
                'total_adjustment' => $this->nullableFloat($schedule['total_adjustment'] ?? null),
                'confidence_penalty' => $this->nullableFloat($schedule['confidence_penalty'] ?? null),
                'risk_flags' => $this->stringList($schedule['risk_flags'] ?? []),
                'inputs' => $this->sanitizeCfbScheduleInputs($schedule['inputs'] ?? null),
            ],
            'persisted_signals' => $persisted === [] ? null : [
                'available' => (bool) ($persisted['available'] ?? false),
                'source' => $persisted['source'] ?? null,
                'spread_adjustment' => $this->nullableFloat($persisted['spread_adjustment'] ?? null),
                'total_adjustment' => $this->nullableFloat($persisted['total_adjustment'] ?? null),
                'confidence_penalty' => $this->nullableFloat($persisted['confidence_penalty'] ?? null),
                'applied_families' => $this->stringList($persisted['applied_families'] ?? []),
                'risk_flags' => $this->stringList($persisted['risk_flags'] ?? []),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbMarketMovement(mixed $context): ?array
    {
        if (! is_array($context)) {
            return null;
        }

        return [
            'source' => $context['source'] ?? null,
            'model_pick_side' => $context['model_pick_side'] ?? $context['pick_side'] ?? null,
            'open_bookmaker_home_line' => $this->nullableFloat($context['open_bookmaker_home_line'] ?? null),
            'current_bookmaker_home_line' => $this->nullableFloat($context['current_bookmaker_home_line'] ?? $context['bookmaker_home_line'] ?? null),
            'closing_bookmaker_home_line' => $this->nullableFloat($context['closing_bookmaker_home_line'] ?? null),
            'open_home_margin' => $this->nullableFloat($context['open_home_margin'] ?? null),
            'current_home_margin' => $this->nullableFloat($context['current_home_margin'] ?? $context['market_home_margin'] ?? null),
            'closing_home_margin' => $this->nullableFloat($context['closing_home_margin'] ?? null),
            'line_movement_home_margin' => $this->nullableFloat($context['line_movement_home_margin'] ?? null),
            'line_value_from_open' => $this->nullableFloat($context['line_value_from_open'] ?? null),
            'closing_line_value_points' => $this->nullableFloat($context['closing_line_value_points'] ?? null),
            'closing_line_value_bucket' => $context['closing_line_value_bucket'] ?? null,
            'line_moved_toward_model' => $this->nullableBool($context['line_moved_toward_model'] ?? null),
            'line_moved_against_model' => $this->nullableBool($context['line_moved_against_model'] ?? null),
            'model_edge_vs_current' => $this->nullableFloat($context['model_edge_vs_current'] ?? null),
            'current_bookmaker_home_line_range' => $this->nullableFloat($context['current_bookmaker_home_line_range'] ?? null),
            'confidence_adjustment' => $this->nullableFloat($context['confidence_adjustment'] ?? null),
            'confidence_penalty' => $this->nullableFloat($context['confidence_penalty'] ?? null),
            'risk_flags' => $this->stringList($context['risk_flags'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbPlayerAvailability(mixed $availability): ?array
    {
        if (! is_array($availability)) {
            return null;
        }

        return [
            'enabled' => (bool) ($availability['enabled'] ?? false),
            'applied' => (bool) ($availability['applied'] ?? false),
            'spread_adjustment' => $this->nullableFloat($availability['spread_adjustment'] ?? null),
            'total_adjustment' => $this->nullableFloat($availability['total_adjustment'] ?? null),
            'risk_flags' => $this->stringList($availability['risk_flags'] ?? []),
            'home' => $this->sanitizeCfbAvailabilitySide($availability['home'] ?? null),
            'away' => $this->sanitizeCfbAvailabilitySide($availability['away'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbAvailabilitySide(mixed $side): ?array
    {
        if (! is_array($side)) {
            return null;
        }

        return [
            'available' => (bool) ($side['available'] ?? false),
            'out' => $this->nullableInt($side['out'] ?? null),
            'questionable' => $this->nullableInt($side['questionable'] ?? null),
            'weighted_impact' => $this->nullableFloat($side['weighted_impact'] ?? $side['impact_score'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbWeatherInputs(mixed $inputs): ?array
    {
        if (! is_array($inputs)) {
            return null;
        }

        return [
            'temperature_f' => $this->nullableFloat($inputs['temperature_f'] ?? null),
            'wind_speed_mph' => $this->nullableFloat($inputs['wind_speed_mph'] ?? null),
            'wind_gust_mph' => $this->nullableFloat($inputs['wind_gust_mph'] ?? null),
            'precipitation_inches' => $this->nullableFloat($inputs['precipitation_inches'] ?? null),
            'precipitation_probability' => $this->nullableFloat($inputs['precipitation_probability'] ?? null),
            'humidity_percent' => $this->nullableFloat($inputs['humidity_percent'] ?? null),
            'condition_code' => $inputs['condition_code'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbScheduleInputs(mixed $inputs): ?array
    {
        if (! is_array($inputs)) {
            return null;
        }

        return [
            'home_rest_days' => $this->nullableInt($inputs['home_rest_days'] ?? null),
            'away_rest_days' => $this->nullableInt($inputs['away_rest_days'] ?? null),
            'away_road_trip_game_number' => $this->nullableInt($inputs['away_road_trip_game_number'] ?? null),
            'home_lookahead_gap' => $this->nullableFloat($inputs['home_lookahead_gap'] ?? null),
            'away_lookahead_gap' => $this->nullableFloat($inputs['away_lookahead_gap'] ?? null),
            'home_letdown_gap' => $this->nullableFloat($inputs['home_letdown_gap'] ?? null),
            'away_letdown_gap' => $this->nullableFloat($inputs['away_letdown_gap'] ?? null),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function sanitizeCfbAdjustmentList(mixed $groups): ?array
    {
        if (! is_array($groups)) {
            return null;
        }

        $sanitized = [];
        foreach ($groups as $name => $group) {
            if (! is_string($name) || ! is_array($group)) {
                continue;
            }

            $sanitized[$name] = [
                'available' => array_key_exists('available', $group) ? (bool) $group['available'] : null,
                'enabled' => array_key_exists('enabled', $group) ? (bool) $group['enabled'] : null,
                'source' => $group['source'] ?? null,
                'spread_adjustment' => $this->nullableFloat($group['spread_adjustment'] ?? $group['adjustment'] ?? null),
                'total_adjustment' => $this->nullableFloat($group['total_adjustment'] ?? null),
                'confidence_penalty' => $this->nullableFloat($group['confidence_penalty'] ?? null),
                'risk_flags' => $this->stringList($group['risk_flags'] ?? []),
                'adaptive_multiplier' => $this->nullableFloat($group['adaptive_multiplier'] ?? null),
                'label' => $group['label'] ?? null,
            ];
        }

        return $sanitized === [] ? null : $sanitized;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function recommendation(): ?array
    {
        if ($this->context->slug !== 'mlb' || ! $this->resource instanceof MlbPrediction) {
            return null;
        }

        if (! $this->mlbRecommendationResolved) {
            $this->mlbRecommendation = app(MlbPredictionRecommendationService::class)->forPrediction($this->resource);
            $this->mlbRecommendationResolved = true;
        }

        return $this->mlbRecommendation;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function publicRecommendation(): ?array
    {
        $recommendation = $this->recommendation();

        if ($recommendation === null) {
            return null;
        }

        $public = (array) ($recommendation['public'] ?? $recommendation);
        $promotion = (array) ($recommendation['promotion'] ?? []);
        $type = (string) ($public['recommendation_type'] ?? 'no_play');

        return [
            'type' => $type,
            'label' => $type === 'no_play' ? 'No betting recommendation' : $this->recommendationLabel($type),
            'is_bet' => ($public['is_bet'] ?? false) === true,
            'is_lean' => $type === 'lean' && ($public['prediction_phase'] ?? null) === 'pregame',
            'promotion_blocked' => ($promotion['status'] ?? null) === 'blocked',
            'block_reasons' => array_values((array) ($promotion['block_reasons'] ?? [])),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function valueSignal(): ?array
    {
        if ($this->context->slug !== 'wnba') {
            return null;
        }

        $game = $this->relation('game');
        if (! $game) {
            return null;
        }

        $recommendations = app(WnbaCalculateBettingValue::class)->execute($game);
        if (! is_array($recommendations) || $recommendations === []) {
            return [
                'has_playable_value' => false,
                'play_count' => 0,
                'best' => null,
            ];
        }

        $playable = collect($recommendations)
            ->filter(fn (array $recommendation): bool => ($recommendation['is_playable'] ?? false) === true)
            ->sortByDesc(fn (array $recommendation): float => (float) ($recommendation['bet_units'] ?? 0))
            ->values();

        $best = $playable->first() ?? collect($recommendations)
            ->sortByDesc(fn (array $recommendation): float => (float) ($recommendation['confidence'] ?? 0))
            ->first();

        return [
            'has_playable_value' => $playable->isNotEmpty(),
            'play_count' => $playable->count(),
            'best' => is_array($best) ? [
                'type' => $best['type'] ?? null,
                'label' => $best['recommendation'] ?? null,
                'edge' => isset($best['edge']) ? (float) $best['edge'] : null,
                'odds' => $best['odds'] ?? null,
                'market_line' => isset($best['market_line']) ? (float) $best['market_line'] : null,
                'model_line' => isset($best['model_line']) ? (float) $best['model_line'] : null,
                'model_probability' => isset($best['model_probability']) ? (float) $best['model_probability'] : null,
                'implied_probability' => isset($best['implied_probability']) ? (float) $best['implied_probability'] : null,
                'grade' => $best['grade'] ?? null,
                'risk_level' => $best['risk_level'] ?? null,
                'units' => isset($best['bet_units']) ? (float) $best['bet_units'] : null,
                'reason' => $best['reasoning'] ?? null,
            ] : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function marketAwareProjection(): ?array
    {
        if ($this->context->slug !== 'mlb' || ! $this->resource instanceof MlbPrediction) {
            return null;
        }

        return app(MlbMarketAwareProjectionService::class)->forPrediction($this->resource);
    }

    private function recommendationLabel(string $type): string
    {
        return match ($type) {
            'bet' => 'Model bet',
            'lean' => 'Model lean',
            'monitor' => 'Live monitor',
            default => str($type)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function confidenceSampleGames(array $metadata): ?int
    {
        foreach ([
            'season_context.sample_games',
            'sample_games',
            'raw_inputs.sample_games',
            'analysis_layer.sample_games',
        ] as $key) {
            $value = data_get($metadata, $key);

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function minimumPitcherConfidence(array $metadata): ?float
    {
        $home = data_get($metadata, 'pitcher_inputs.home_confidence');
        $away = data_get($metadata, 'pitcher_inputs.away_confidence');

        if (! is_numeric($home) || ! is_numeric($away)) {
            return null;
        }

        return min((float) $home, (float) $away);
    }

    private function isLivePrediction(): bool
    {
        $status = $this->gameAttribute('status');

        if ($this->context->slug === 'mlb') {
            return MlbGamePhase::isLive(is_string($status) ? $status : null);
        }

        return $status === null || in_array($status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function auditContext(): array
    {
        $snapshot = $this->latestFeatureSnapshot();
        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];
        $marketSafety = (array) data_get($metadata, 'market_context.safety', []);
        $game = $this->relation('game');

        return [
            'prediction_generated_at' => $this->serializeDateValue($snapshot?->generated_at ?? $this->attribute('created_at')),
            'prediction_locked_at' => $this->serializeDateValue($snapshot?->generated_at ?? $this->attribute('created_at')),
            'feature_snapshot_at' => $this->serializeDateValue($snapshot?->generated_at),
            'snapshot_run_id' => $snapshot?->snapshot_run_id,
            'odds_collected_at' => $marketSafety['odds_captured_at'] ?? $this->serializeDateValue($game?->getAttribute('odds_updated_at')),
            'graded_at' => $this->serializeDateValue($this->attribute('graded_at')),
            'result_finalized_at' => MlbGamePhase::isFinal(is_string($game?->getAttribute('status')) ? $game?->getAttribute('status') : null)
                ? $this->serializeDateValue($game?->getAttribute('updated_at'))
                : null,
            'model_version' => $this->attribute('model_version'),
            'feature_version' => $this->attribute('feature_version'),
            'blend_version' => $this->attribute('blend_version'),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function totalResult(): ?array
    {
        $side = $this->attribute('total_pick_side');
        $line = $this->floatAttribute('total_pick_line');
        $result = $this->attribute('total_pick_result');
        $edge = $this->floatAttribute('total_pick_edge');

        if ($side === null && $line === null && $result === null && $edge === null) {
            return null;
        }

        return [
            'side' => $side,
            'line' => $line,
            'result' => $result,
            'edge' => $edge,
            'actual_total' => $this->floatAttribute('actual_total'),
            'predicted_total' => $this->floatAttribute('predicted_total'),
            'total_error' => $this->floatAttribute('total_error'),
        ];
    }

    private function latestFeatureSnapshot(): ?PredictionFeatureSnapshot
    {
        if (! $this->resource instanceof Model || $this->attribute('id') === null) {
            return null;
        }

        return PredictionFeatureSnapshot::query()
            ->where('prediction_table', $this->resource->getTable())
            ->where('prediction_id', (int) $this->attribute('id'))
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    private function liveAttribute(string $key): mixed
    {
        return $this->isLivePrediction() ? $this->attribute($key) : null;
    }

    private function liveFloatAttribute(string $key): ?float
    {
        $value = $this->liveAttribute($key);

        return $value === null ? null : (float) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function depthChartContext(): ?array
    {
        $metadata = is_array($this->attribute('model_metadata')) ? $this->attribute('model_metadata') : [];

        if (is_array($metadata['depth_chart_injuries'] ?? null)) {
            $injuries = $metadata['depth_chart_injuries'];

            return [
                'type' => 'injury_weighting',
                'applied' => (bool) ($injuries['applied'] ?? false),
                'home_out_weighted' => (float) ($injuries['home_out_weighted'] ?? 0.0),
                'away_out_weighted' => (float) ($injuries['away_out_weighted'] ?? 0.0),
                'home_questionable_weighted' => (float) ($injuries['home_questionable_weighted'] ?? 0.0),
                'away_questionable_weighted' => (float) ($injuries['away_questionable_weighted'] ?? 0.0),
                'spread_adjustment' => (float) ($injuries['spread_adjustment'] ?? 0.0),
                'total_adjustment' => (float) ($injuries['total_adjustment'] ?? 0.0),
                'win_probability_adjustment' => isset($injuries['win_probability_adjustment'])
                    ? (float) $injuries['win_probability_adjustment']
                    : null,
                'injury_model_source' => $metadata['injury_model_source'] ?? null,
                'injury_spread_model_source' => $metadata['injury_spread_model_source'] ?? null,
                'injury_total_model_source' => $metadata['injury_total_model_source'] ?? null,
            ];
        }

        if (is_array($metadata['depth_chart_context'] ?? null)) {
            $source = $metadata['depth_chart_context'];

            return [
                'type' => 'starter_fallback',
                'home_pitcher_source' => $source['home_pitcher_source'] ?? null,
                'away_pitcher_source' => $source['away_pitcher_source'] ?? null,
                'home_depth_chart_fallback_used' => (bool) ($source['home_depth_chart_fallback_used'] ?? false),
                'away_depth_chart_fallback_used' => (bool) ($source['away_depth_chart_fallback_used'] ?? false),
                'probable_pitcher_injury_applied' => (bool) ($source['probable_pitcher_injury_applied'] ?? false),
            ];
        }

        return null;
    }

    private function attribute(string $key): mixed
    {
        if (! $this->resource instanceof Model) {
            return null;
        }

        return array_key_exists($key, $this->resource->getAttributes())
            ? $this->resource->getAttribute($key)
            : null;
    }

    private function floatAttribute(string $key): ?float
    {
        $value = $this->attribute($key);

        return $value === null ? null : (float) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $value)
            ->values()
            ->all();
    }

    private function serializeDateValue(mixed $value): ?string
    {
        return GameDateTimePresenter::serializeDateValue($value);
    }

    private function serializeTimeValue(mixed $value): ?string
    {
        return GameDateTimePresenter::timeString($value);
    }
}
