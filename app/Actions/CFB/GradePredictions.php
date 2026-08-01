<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\PredictionFeatureSnapshot;
use App\Services\CFB\CfbMarketMovementSignalService;
use Illuminate\Database\Eloquent\Model;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'cfb_predictions';

    protected const GAMES_TABLE = 'cfb_games';

    protected function additionalGradingUpdates(Model $prediction, float $actualSpread, float $actualTotal): array
    {
        $game = $prediction->game;

        if (! $game instanceof Game) {
            return [];
        }

        $snapshot = PredictionFeatureSnapshot::query()
            ->where('sport', 'cfb')
            ->where('prediction_table', $prediction->getTable())
            ->where('prediction_id', $prediction->id)
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $snapshot) {
            return [];
        }

        $context = data_get($snapshot->model_metadata, 'cfb_market_movement');
        if (! is_array($context) || $context === []) {
            return [];
        }

        $enriched = app(CfbMarketMovementSignalService::class)->withClosingLineValue($game, $context);
        $marketContext = is_array($snapshot->market_context) ? $snapshot->market_context : [];
        $modelMetadata = is_array($snapshot->model_metadata) ? $snapshot->model_metadata : [];
        $features = is_array($snapshot->features) ? $snapshot->features : [];

        $marketContext['cfb_market_movement'] = $enriched;
        $modelMetadata['cfb_market_movement'] = $enriched;
        $features['market_movement'] = $enriched;

        $snapshot->update([
            'market_context' => $marketContext,
            'model_metadata' => $modelMetadata,
            'features' => $features,
        ]);

        return [];
    }
}
