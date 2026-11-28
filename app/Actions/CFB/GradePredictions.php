<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Services\CFB\CfbClosingMarketEvidenceRecorder;
use Illuminate\Database\Eloquent\Model;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cfb_predictions';

    protected const GAMES_TABLE = 'cfb_games';

    public function executeForGame(int $gameId): array
    {
        $result = parent::executeForGame($gameId);
        if ($result['graded'] === 0) {
            app(CfbClosingMarketEvidenceRecorder::class)->recordForGame($gameId);
        }

        return $result;
    }

    protected function additionalGradingUpdates(Model $prediction, float $actualSpread, float $actualTotal): array
    {
        $game = $prediction->game;
        if ($game instanceof Game && $prediction instanceof Prediction) {
            app(CfbClosingMarketEvidenceRecorder::class)->recordForPrediction($prediction, $game);
        }

        return [];
    }
}
