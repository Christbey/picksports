<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractGradePredictions;
use App\Models\WNBA\Prediction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class GradePredictions extends AbstractGradePredictions
{
    protected const PREDICTION_MODEL = Prediction::class;

    protected const PREDICTION_TABLE = 'wnba_predictions';

    protected const GAMES_TABLE = 'wnba_games';

    private const MINIMUM_FINAL_GRADING_MINUTES_AFTER_TIP = 90;

    protected function baseUngradedFinalPredictionsQuery()
    {
        return parent::baseUngradedFinalPredictionsQuery()
            ->addSelect(
                $this->gamesTable().'.game_date as game_game_date',
                $this->gamesTable().'.game_time as game_game_time',
            );
    }

    protected function shouldGradePrediction(Model $prediction): bool
    {
        if (! parent::shouldGradePrediction($prediction)) {
            return false;
        }

        $scheduledStart = $this->scheduledStartAt($prediction);
        if ($scheduledStart === null) {
            return false;
        }

        return CarbonImmutable::now('UTC')
            ->gte($scheduledStart->addMinutes(self::MINIMUM_FINAL_GRADING_MINUTES_AFTER_TIP));
    }

    private function scheduledStartAt(Model $prediction): ?CarbonImmutable
    {
        $gameDate = $prediction->game_game_date ?? null;
        $gameTime = $prediction->game_game_time ?? null;

        if ($gameDate === null || $gameTime === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::parse((string) $gameDate, 'UTC')->toDateString();

            return CarbonImmutable::parse($date.' '.(string) $gameTime, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
