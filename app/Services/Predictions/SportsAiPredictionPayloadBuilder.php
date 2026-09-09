<?php

namespace App\Services\Predictions;

use Illuminate\Database\Eloquent\Model;
use JsonException;

class SportsAiPredictionPayloadBuilder
{
    public function __construct(
        private readonly SportsOperationalContextBuilder $operationalContextBuilder,
        private readonly SportsExternalGameContextBuilder $externalGameContextBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $sport, Model $prediction): array
    {
        $sport = strtolower($sport);
        $game = $prediction->getRelationValue('game') ?? $prediction->game;

        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $homeTeam = $game?->homeTeam;
        $awayTeam = $game?->awayTeam;
        $homeWinProbability = $this->floatAttribute($prediction, 'win_probability');
        $pickSide = $homeWinProbability !== null && $homeWinProbability >= 0.5 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $homeTeam : $awayTeam;
        $calculatedEdge = $this->calculatedEdge($prediction, $sport);

        $externalContext = $this->externalGameContextBuilder->build($sport, $game, $prediction);

        return [
            'schema_version' => $sport === 'nfl' ? 'sports_ai_prediction_payload_v3' : 'sports_ai_prediction_payload_v2',
            'sport' => $sport,
            'generated_at' => now()->toIso8601String(),
            'game' => [
                'id' => $game?->id,
                'espn_id' => $this->attribute($game, 'espn_id'),
                'season' => $this->attribute($game, 'season'),
                'season_type' => $this->attribute($game, 'season_type'),
                'week' => $this->attribute($game, 'week'),
                'date' => $game?->game_date?->toDateString(),
                'time' => $this->attribute($game, 'game_time'),
                'status' => $this->attribute($game, 'status'),
                'matchup' => $this->attribute($game, 'short_name') ?: $this->attribute($game, 'name'),
                'venue' => $this->attribute($game, 'venue_name'),
                'home_team' => $this->teamPayload($homeTeam),
                'away_team' => $this->teamPayload($awayTeam),
            ],
            'calculated_model' => [
                'pick_side' => $pickSide,
                'pick_team' => $this->teamName($pickTeam),
                'predicted_spread' => $this->floatAttribute($prediction, 'predicted_spread'),
                'predicted_total' => $this->floatAttribute($prediction, 'predicted_total'),
                'home_win_probability' => $homeWinProbability,
                'pick_win_probability' => $homeWinProbability !== null ? max($homeWinProbability, 1 - $homeWinProbability) : null,
                'confidence_score' => $this->floatAttribute($prediction, 'confidence_score'),
                'vegas_spread' => $calculatedEdge['vegas_spread'],
                'model_version' => $this->attribute($prediction, 'model_version'),
                'feature_version' => $this->attribute($prediction, 'feature_version'),
                'blend_version' => $this->attribute($prediction, 'blend_version'),
            ],
            ...($sport === 'nfl' ? [
                'calculated_edge' => $calculatedEdge,
                'decision_contract' => $this->decisionContract($sport, $prediction, $game, $externalContext),
            ] : []),
            'market_context' => [
                'odds_updated_at' => $this->attribute($game, 'odds_updated_at'),
                'odds_markets' => $this->summarizeOdds($this->arrayAttribute($game, 'odds_data')),
                'market_context' => $this->arrayDataGet($prediction, 'model_metadata.market_context'),
            ],
            'operational_context' => $this->operationalContextBuilder->build($sport, $game),
            'external_game_context' => $externalContext,
            'model_metadata' => $this->arrayAttribute($prediction, 'model_metadata'),
            'existing_narrative' => $this->arrayAttribute($prediction, 'narrative_json'),
            'raw_prediction_snapshot' => $this->predictionSnapshot($prediction),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        $payload = $this->normalizeForHash($payload);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function calculatedEdge(Model $prediction, ?string $sport = null): array
    {
        $homeWinProbability = $this->floatAttribute($prediction, 'win_probability');
        $predictedSpread = $this->floatAttribute($prediction, 'predicted_spread');
        $predictedTotal = $this->floatAttribute($prediction, 'predicted_total');
        $sport = strtolower((string) $sport);

        if ($sport === 'nfl') {
            $marketSpread = $this->number(data_get($prediction, 'model_metadata.analysis_layer.calculated_edge.market_spread'));
            $marketTotal = $this->number(data_get($prediction, 'model_metadata.analysis_layer.calculated_edge.market_total'));
            $spreadEdge = $this->number(data_get($prediction, 'model_metadata.analysis_layer.calculated_edge.spread_points'))
                ?? ($predictedSpread !== null && $marketSpread !== null ? $predictedSpread - $marketSpread : null);
            $totalEdge = $this->number(data_get($prediction, 'model_metadata.analysis_layer.calculated_edge.total_points'))
                ?? ($predictedTotal !== null && $marketTotal !== null ? $predictedTotal - $marketTotal : null);

            return [
                'sign_convention' => 'positive_home_margin',
                'predicted_spread' => $predictedSpread,
                'predicted_total' => $predictedTotal,
                'home_win_probability' => $homeWinProbability,
                'pick_win_probability' => $homeWinProbability !== null ? max($homeWinProbability, 1 - $homeWinProbability) : null,
                'confidence_score' => $this->floatAttribute($prediction, 'confidence_score'),
                'vegas_spread' => $marketSpread !== null ? round(-$marketSpread, 3) : null,
                'market_spread_home_margin' => $marketSpread !== null ? round($marketSpread, 3) : null,
                'market_total' => $marketTotal !== null ? round($marketTotal, 3) : null,
                'spread_edge' => $spreadEdge !== null ? round($spreadEdge, 3) : null,
                'total_edge' => $totalEdge !== null ? round($totalEdge, 3) : null,
            ];
        }

        $vegasSpread = $this->floatAttribute($prediction, 'vegas_spread');

        return [
            'sign_convention' => 'sportsbook_home_spread',
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $predictedTotal,
            'home_win_probability' => $homeWinProbability,
            'pick_win_probability' => $homeWinProbability !== null ? max($homeWinProbability, 1 - $homeWinProbability) : null,
            'confidence_score' => $this->floatAttribute($prediction, 'confidence_score'),
            'vegas_spread' => $vegasSpread,
            'spread_edge' => $predictedSpread !== null && $vegasSpread !== null
                ? round($predictedSpread + $vegasSpread, 2)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decisionContract(
        string $sport,
        Model $prediction,
        ?Model $game,
        array $externalContext,
    ): ?array {
        if ($sport !== 'nfl') {
            return null;
        }

        $analysis = (array) data_get($prediction, 'model_metadata.analysis_layer', []);
        $proSignal = (array) ($analysis['pro_signal_layer'] ?? []);
        $baseEdges = $this->calculatedEdge($prediction, 'nfl');
        $decisionEdges = $this->nflContextAdjustedEdges($baseEdges, $externalContext);
        $baseClassification = $this->nflClassification((string) ($analysis['bet_classification'] ?? ''));
        $proClassification = $this->nflClassification((string) ($proSignal['tier'] ?? ''));
        $classification = $this->lowerClassification($baseClassification, $proClassification);
        $recommendedMarkets = collect((array) ($proSignal['recommended_markets'] ?? []))
            ->filter(fn ($market): bool => is_array($market))
            ->sortByDesc(fn (array $market): int => (int) ($market['score'] ?? 0))
            ->values();
        $eligibleMarkets = [];

        foreach ($recommendedMarkets as $market) {
            $candidate = $this->nflMarketCandidate(
                (string) ($market['market'] ?? ''),
                (int) ($market['score'] ?? 0),
                (string) ($market['tier'] ?? ''),
                $decisionEdges,
                $baseEdges,
                $prediction,
                $game,
            );

            if ($candidate !== null) {
                $eligibleMarkets[] = $candidate;
            }
        }

        $selected = $this->classificationRank($classification) >= $this->classificationRank('lean')
            ? ($eligibleMarkets[0] ?? null)
            : null;

        if ($selected === null && $this->classificationRank($classification) >= $this->classificationRank('lean')) {
            $classification = 'watch';
        }

        return [
            'schema_version' => 'nfl_deterministic_decision_v1',
            'authority' => 'nfl_analysis_and_pro_signal_consensus',
            'classification' => $classification,
            'recommendation' => $selected['market'] ?? 'pass',
            'selection' => $selected,
            'eligible_markets' => $eligibleMarkets,
            'base_classification' => $baseClassification,
            'pro_classification' => $proClassification,
            'moneyline_play_enabled' => (bool) config('nfl.betting.moneyline.play_enabled', false),
            'context_report_id' => $externalContext['report_id'] ?? null,
            'context_adjustment_applied' => ($decisionEdges['context_adjustment_applied'] ?? false) === true,
            'base_edge' => [
                'spread' => $baseEdges['spread_edge'] ?? null,
                'total' => $baseEdges['total_edge'] ?? null,
            ],
            'context_adjusted_edge' => [
                'spread' => $decisionEdges['spread_edge'] ?? null,
                'total' => $decisionEdges['total_edge'] ?? null,
            ],
            'thresholds' => [
                'spread_edge' => (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0),
                'total_edge' => (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0),
            ],
            'reason_codes' => array_values(array_unique(array_filter([
                ...(array) ($analysis['reason_codes'] ?? []),
                ...(array) ($proSignal['reason_codes'] ?? []),
            ], 'is_string'))),
            'risk_flags' => array_values(array_unique(array_filter([
                ...(array) ($analysis['risk_flags'] ?? []),
                ...(array) ($proSignal['risk_flags'] ?? []),
            ], 'is_string'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $edges
     * @param  array<string, mixed>  $baseEdges
     * @return array<string, mixed>|null
     */
    private function nflMarketCandidate(
        string $market,
        int $score,
        string $tier,
        array $edges,
        array $baseEdges,
        Model $prediction,
        ?Model $game,
    ): ?array {
        $market = strtolower($market);
        $tier = $this->nflClassification($tier);
        $homeTeam = $game?->homeTeam;
        $awayTeam = $game?->awayTeam;

        if ($market === 'winner') {
            if (! config('nfl.betting.moneyline.play_enabled', false)) {
                return null;
            }

            $homeWinProbability = $this->floatAttribute($prediction, 'win_probability');
            if ($homeWinProbability === null) {
                return null;
            }

            $side = $homeWinProbability >= 0.5 ? 'home' : 'away';

            return [
                'market' => 'moneyline',
                'side' => $side,
                'direction' => null,
                'team' => $this->teamName($side === 'home' ? $homeTeam : $awayTeam),
                'line' => null,
                'price' => null,
                'edge' => null,
                'score' => $score,
                'tier' => $tier,
            ];
        }

        if ($market === 'spread') {
            $edge = $this->number($edges['spread_edge'] ?? null);
            $marketSpread = $this->number($edges['market_spread_home_margin'] ?? null);
            if ($edge === null || $marketSpread === null || abs($edge) < (float) config('nfl.predictions.analysis_layer.min_spread_edge', 2.0)) {
                return null;
            }

            $side = $edge >= 0 ? 'home' : 'away';

            return [
                'market' => 'spread',
                'side' => $side,
                'direction' => null,
                'team' => $this->teamName($side === 'home' ? $homeTeam : $awayTeam),
                'line' => round($side === 'home' ? -$marketSpread : $marketSpread, 3),
                'price' => $this->number(data_get($prediction, 'model_metadata.analysis_layer.pro_signal_layer.market_context.spread_price')),
                'edge' => round($edge, 3),
                'model_edge' => $this->number($baseEdges['spread_edge'] ?? null),
                'context_adjusted_edge' => round($edge, 3),
                'score' => $score,
                'tier' => $tier,
            ];
        }

        if ($market === 'total') {
            $edge = $this->number($edges['total_edge'] ?? null);
            $marketTotal = $this->number($edges['market_total'] ?? null);
            if ($edge === null || $marketTotal === null || abs($edge) < (float) config('nfl.predictions.analysis_layer.min_total_edge', 3.0)) {
                return null;
            }

            return [
                'market' => 'total',
                'side' => null,
                'direction' => $edge >= 0 ? 'over' : 'under',
                'team' => null,
                'line' => round($marketTotal, 3),
                'price' => null,
                'edge' => round($edge, 3),
                'model_edge' => $this->number($baseEdges['total_edge'] ?? null),
                'context_adjusted_edge' => round($edge, 3),
                'score' => $score,
                'tier' => $tier,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $baseEdges
     * @param  array<string, mixed>  $externalContext
     * @return array<string, mixed>
     */
    private function nflContextAdjustedEdges(array $baseEdges, array $externalContext): array
    {
        $contextEligible = ($externalContext['available'] ?? false) === true
            && data_get($externalContext, 'deterministic_adjustment.eligible') === true;
        $spreadAdjustment = $contextEligible
            ? $this->number(data_get($externalContext, 'deterministic_adjustment.home_margin_points'))
            : null;
        $totalAdjustment = $contextEligible
            ? $this->number(data_get($externalContext, 'deterministic_adjustment.total_points'))
            : null;
        $spreadAdjusted = $spreadAdjustment !== null && abs($spreadAdjustment) > 0.0001;
        $totalAdjusted = $totalAdjustment !== null && abs($totalAdjustment) > 0.0001;
        $basePredictedSpread = $this->number($baseEdges['predicted_spread'] ?? null);
        $basePredictedTotal = $this->number($baseEdges['predicted_total'] ?? null);
        $baseSpreadEdge = $this->number($baseEdges['spread_edge'] ?? null);
        $baseTotalEdge = $this->number($baseEdges['total_edge'] ?? null);

        return [
            ...$baseEdges,
            'predicted_spread' => $spreadAdjusted && $basePredictedSpread !== null
                ? round($basePredictedSpread + $spreadAdjustment, 3)
                : ($baseEdges['predicted_spread'] ?? null),
            'predicted_total' => $totalAdjusted && $basePredictedTotal !== null
                ? round($basePredictedTotal + $totalAdjustment, 3)
                : ($baseEdges['predicted_total'] ?? null),
            'spread_edge' => $spreadAdjusted && $baseSpreadEdge !== null
                ? round($baseSpreadEdge + $spreadAdjustment, 3)
                : ($baseEdges['spread_edge'] ?? null),
            'total_edge' => $totalAdjusted && $baseTotalEdge !== null
                ? round($baseTotalEdge + $totalAdjustment, 3)
                : ($baseEdges['total_edge'] ?? null),
            'context_adjustment_applied' => $spreadAdjusted || $totalAdjusted,
        ];
    }

    private function nflClassification(string $classification): string
    {
        $classification = strtolower(trim($classification));

        return match (true) {
            in_array($classification, ['bet', 'official_candidate'], true) => 'bet',
            $classification === 'lean' => 'lean',
            $classification === 'watch' || $classification === 'watchlist' || str_contains($classification, 'watchlist') => 'watch',
            default => 'pass',
        };
    }

    private function lowerClassification(string $first, string $second): string
    {
        return $this->classificationRank($first) <= $this->classificationRank($second) ? $first : $second;
    }

    private function classificationRank(string $classification): int
    {
        return match ($classification) {
            'bet' => 3,
            'lean' => 2,
            'watch' => 1,
            default => 0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function teamPayload(?Model $team): array
    {
        return [
            'id' => $team?->id,
            'name' => $this->teamName($team),
            'abbreviation' => $this->attribute($team, 'abbreviation'),
            'record' => [
                'wins' => $this->attribute($team, 'wins'),
                'losses' => $this->attribute($team, 'losses'),
            ],
        ];
    }

    private function teamName(?Model $team): ?string
    {
        if (! $team) {
            return null;
        }

        $display = trim((string) $this->attribute($team, 'display_name'));
        if ($display !== '') {
            return $display;
        }

        return trim(((string) $this->attribute($team, 'location')).' '.((string) $this->attribute($team, 'name'))) ?: null;
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     * @return array<int, array<string, mixed>>
     */
    private function summarizeOdds(?array $oddsData): array
    {
        $bookmakers = is_array($oddsData['bookmakers'] ?? null) ? $oddsData['bookmakers'] : [];
        $markets = [];

        foreach (array_slice($bookmakers, 0, 3) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (! is_array($market)) {
                    continue;
                }

                $markets[] = [
                    'bookmaker' => $bookmaker['key'] ?? $bookmaker['title'] ?? null,
                    'market' => $market['key'] ?? null,
                    'outcomes' => array_slice(array_map(fn ($outcome): array => [
                        'name' => $outcome['name'] ?? null,
                        'price' => $outcome['price'] ?? null,
                        'point' => $outcome['point'] ?? null,
                    ], is_array($market['outcomes'] ?? null) ? $market['outcomes'] : []), 0, 4),
                ];
            }
        }

        return array_slice($markets, 0, 8);
    }

    /**
     * @return array<string, mixed>
     */
    private function predictionSnapshot(Model $prediction): array
    {
        return collect($prediction->getAttributes())
            ->except([
                'created_at',
                'updated_at',
                'narrative_json',
                'narrative_input_hash',
                'narrative_generated_at',
            ])
            ->all();
    }

    private function attribute(?Model $model, string $key): mixed
    {
        if (! $model || ! array_key_exists($key, $model->getAttributes())) {
            return null;
        }

        return $model->getAttribute($key);
    }

    private function floatAttribute(Model $model, string $key): ?float
    {
        $value = $this->attribute($model, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayAttribute(?Model $model, string $key): ?array
    {
        $value = $this->attribute($model, $key);

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayDataGet(Model $model, string $key): ?array
    {
        $value = data_get($model, $key);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        unset(
            $payload['generated_at'],
            $payload['operational_context']['generated_at'],
            $payload['operational_context']['data_freshness']['market_odds_age_minutes'],
        );

        return $payload;
    }
}
