<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CBB\Prediction;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cbb_predictions';

    protected const GAMES_TABLE = 'cbb_games';

    protected function additionalGradingUpdates(\Illuminate\Database\Eloquent\Model $prediction, float $actualSpread, float $actualTotal): array
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
