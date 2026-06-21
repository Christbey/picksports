<?php

namespace App\Actions\MLB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\MLB\Prediction;
use App\Services\MLB\MlbPredictionTotalResultService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'mlb_predictions';

    protected const GAMES_TABLE = 'mlb_games';

    /**
     * @return array<string, mixed>
     */
    protected function additionalGradingUpdates(Model $prediction, float $actualSpread, float $actualTotal): array
    {
        if (! $prediction instanceof Prediction || ! Schema::hasColumn('mlb_predictions', 'total_pick_result')) {
            return [];
        }

        return app(MlbPredictionTotalResultService::class)->result($prediction, $actualTotal);
    }
}
