<?php

namespace App\Console\Commands\WCBB;

use App\Actions\WCBB\GenerateCanonicalPrediction;
use App\Console\Commands\Sports\Canonical\AbstractGenerateCollegeBasketballCanonicalPredictionsCommand;
use App\Models\WCBB\Game;
use App\Services\Predictions\CalculationReleaseSelector;
use App\Services\WCBB\Predictions\WcbbPregameInputReadiness;
use Illuminate\Database\Eloquent\Model;

class GenerateCanonicalPredictionsCommand extends AbstractGenerateCollegeBasketballCanonicalPredictionsCommand
{
    protected $signature = 'wcbb:generate-canonical-predictions
        {--game= : Generate for one WCBB game ID}
        {--season= : Limit to a season}
        {--date= : Limit to a game date (YYYY-MM-DD)}
        {--days-forward=8 : Bound default generation to the upcoming rollout horizon}
        {--draft : Create immutable revisions without publishing them}';

    protected $description = 'Generate canonical WCBB predictions through the snapshot and calculation-release lifecycle';

    protected function generationHoldReason(Model $game): ?string
    {
        $hold = parent::generationHoldReason($game);
        if ($hold !== null) {
            return $hold;
        }
        $release = app(CalculationReleaseSelector::class)->select($game->sportEvent, 'pregame');

        return app(WcbbPregameInputReadiness::class)->holds($game, $release) !== []
            ? 'unusable WCBB efficiency or tempo inputs; forecast remains held.' : null;
    }

    protected function gameClass(): string
    {
        return Game::class;
    }

    protected function generatorClass(): string
    {
        return GenerateCanonicalPrediction::class;
    }

    protected function sportLabel(): string
    {
        return 'WCBB';
    }
}
