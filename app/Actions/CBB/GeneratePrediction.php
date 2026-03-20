<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballPredictionGenerator;
use App\Models\CBB\Game;
use App\Models\CBB\Prediction;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
use Illuminate\Database\Eloquent\Model;

class GeneratePrediction extends AbstractCollegeBasketballPredictionGenerator
{
    protected const SPORT_KEY = 'cbb';

    protected const TEAM_METRIC_MODEL = TeamMetric::class;

    protected const PREDICTION_MODEL = Prediction::class;

    protected const GAME_MODEL = Game::class;

    protected const TEAM_STAT_MODEL = TeamStat::class;

    /**
     * @return array<string, mixed>|null
     */
    public function preview(Game $game): ?array
    {
        return $this->makePredictionData($game);
    }

    protected function shouldGeneratePredictionForGame(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        return ! $this->isPlaceholderTeam($homeTeam) && ! $this->isPlaceholderTeam($awayTeam);
    }

    private function isPlaceholderTeam(Model $team): bool
    {
        $school = strtoupper(trim((string) ($team->school ?? '')));
        $abbreviation = strtoupper(trim((string) ($team->abbreviation ?? '')));
        $espnId = (int) ($team->espn_id ?? 0);

        return in_array($school, ['TBD', 'TBD2'], true)
            || in_array($abbreviation, ['TBD', 'TBD2', 'WFF', 'FF'], true)
            || $espnId < 0;
    }
}
