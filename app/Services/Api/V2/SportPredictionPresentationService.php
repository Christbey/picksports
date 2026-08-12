<?php

namespace App\Services\Api\V2;

use App\Actions\WNBA\CalculateBettingValue as WnbaCalculateBettingValue;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\MLB\MlbMarketAwareProjectionService;
use App\Services\MLB\MlbPeriodInsightService;
use App\Services\MLB\MlbPredictionRecommendationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class SportPredictionPresentationService
{
    public function __construct(
        private readonly SportPredictionFeatureSnapshotQuery $snapshots,
        private readonly MlbPeriodInsightService $mlbPeriodInsights,
        private readonly MlbPredictionRecommendationService $mlbRecommendations,
        private readonly MlbMarketAwareProjectionService $mlbMarketAwareProjections,
        private readonly WnbaCalculateBettingValue $wnbaBettingValue,
    ) {}

    /**
     * @param  Collection<int, Model>  $predictions
     * @return Collection<int, SportPredictionPresentationData>
     */
    public function forPredictions(SportContext $context, Collection $predictions): Collection
    {
        $snapshots = $this->snapshots->latestForPredictions($predictions);
        $periodInsightsByGame = $context->slug === 'mlb'
            ? $this->mlbPeriodInsights->forGames($predictions->pluck('game')->filter())
            : [];

        return $predictions->mapWithKeys(function (Model $prediction) use ($context, $snapshots, $periodInsightsByGame): array {
            $predictionId = (int) $prediction->getKey();

            return [$predictionId => $this->build(
                $context,
                $prediction,
                $snapshots->get($predictionId),
                $periodInsightsByGame[(int) $prediction->getAttribute('game_id')] ?? [],
            )];
        });
    }

    public function forPrediction(SportContext $context, Model $prediction): SportPredictionPresentationData
    {
        $snapshot = $this->snapshots
            ->latestForPredictions(collect([$prediction]))
            ->get((int) $prediction->getKey());
        $periodInsights = $context->slug === 'mlb'
            ? $this->mlbPeriodInsights->forGame($prediction->getRelationValue('game'))
            : [];

        return $this->build($context, $prediction, $snapshot, $periodInsights);
    }

    /**
     * @param  array<int, array<string, mixed>>  $periodInsights
     */
    private function build(
        SportContext $context,
        Model $prediction,
        ?PredictionFeatureSnapshot $snapshot,
        array $periodInsights,
    ): SportPredictionPresentationData {
        $isMlbPrediction = $context->slug === 'mlb' && $prediction instanceof MlbPrediction;

        return new SportPredictionPresentationData(
            periodInsights: $periodInsights,
            featureSnapshot: $snapshot,
            recommendation: $isMlbPrediction
                ? $this->mlbRecommendations->forPrediction($prediction)
                : null,
            marketAwareProjection: $isMlbPrediction
                ? $this->mlbMarketAwareProjections->forPrediction($prediction)
                : null,
            valueSignal: $context->slug === 'wnba'
                ? $this->wnbaValueSignal($prediction->getRelationValue('game'))
                : null,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function wnbaValueSignal(mixed $game): ?array
    {
        if (! $game instanceof Model) {
            return null;
        }

        $recommendations = $this->wnbaBettingValue->execute($game);
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
}
