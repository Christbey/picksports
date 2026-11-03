<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\WCBB\Game;
use App\Models\WCBB\Prediction;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TeamStat;
use App\Services\Predictions\Basketball\CollegeBasketballPregameEligibility;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    /** @return array<string, mixed>|null */
    public function preview(Game $game): ?array
    {
        return $this->makePredictionData($game);
    }

    protected function shouldGeneratePredictionForGame(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        return app(CollegeBasketballPregameEligibility::class)->teamsAreKnown($homeTeam, $awayTeam);
    }

    /** @return list<array{side: string, metric_id: int, reason: string}> */
    public function metricInputHolds(Game $game): array
    {
        $holds = [];
        $metrics = $this->teamMetricsForGame($game, $game->home_team_id, $game->away_team_id);
        foreach (['home', 'away'] as $index => $side) {
            $metric = $metrics[$index];
            if ($metric === null) {
                continue;
            }
            foreach (['offensive_efficiency', 'defensive_efficiency', 'tempo'] as $field) {
                $value = $metric->getAttribute($field);
                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0) {
                    $holds[] = ['side' => $side, 'metric_id' => (int) $metric->getKey(), 'reason' => 'unusable_'.$field];
                }
            }
        }

        return $holds;
    }

    protected const SPORT_KEY = 'wcbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;
}
