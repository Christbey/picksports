<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CBB\Prediction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cbb_predictions';

    protected const GAMES_TABLE = 'cbb_games';

    protected function baseUngradedFinalPredictionsQuery(): Builder
    {
        return Prediction::query()->join('cbb_games', 'cbb_predictions.game_id', '=', 'cbb_games.id')
            ->where('cbb_games.status', 'STATUS_FINAL')->whereNotNull('cbb_games.home_score')->whereNotNull('cbb_games.away_score')
            ->where(function (Builder $query): void {
                $query->whereNull('cbb_predictions.graded_at')
                    ->orWhereRaw('cbb_predictions.actual_spread <> (cbb_games.home_score - cbb_games.away_score)')
                    ->orWhereRaw('cbb_predictions.actual_total <> (cbb_games.home_score + cbb_games.away_score)');
            })
            ->select('cbb_predictions.*', 'cbb_games.home_score', 'cbb_games.away_score');
    }

    protected function additionalGradingUpdates(Model $prediction, float $actualSpread, float $actualTotal): array
    {
        $marketSpread = $prediction->vegas_spread;
        if (! is_numeric($marketSpread) || ! is_numeric($prediction->predicted_spread)) {
            return [];
        }

        $marketModelSpread = -(float) $marketSpread;
        $predictedSpread = (float) $prediction->predicted_spread;
        $pickSide = $predictedSpread > $marketModelSpread ? 'home' : 'away';
        $coverMargin = $pickSide === 'home'
            ? ($actualSpread - $marketModelSpread)
            : ((-$actualSpread) + $marketModelSpread);
        $result = abs($coverMargin) < 0.0001
            ? 'push'
            : ($coverMargin > 0 ? 'win' : 'loss');

        return [
            'ats_pick_side' => $pickSide,
            'ats_pick_result' => $result,
            'ats_pick_edge' => round(abs($predictedSpread - $marketModelSpread), 1),
        ];
    }
}
