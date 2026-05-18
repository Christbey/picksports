<?php

namespace App\Console\Commands\NFL;

use App\Actions\NFL\CalculateElo;
use App\Console\Commands\Sports\AbstractCalculateEloCommand;
use App\Models\NFL\EloRating;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Carbon;

class CalculateEloCommand extends AbstractCalculateEloCommand
{
    protected const COMMAND_NAME = 'nfl:calculate-elo';

    protected const COMMAND_DESCRIPTION = 'Calculate NFL team Elo ratings based on completed games';

    protected const SPORT_NAME = 'NFL';

    protected const GAME_MODEL = Game::class;

    protected const TEAM_MODEL = Team::class;

    protected const ELO_RATING_MODEL = EloRating::class;

    protected const CALCULATE_ELO_ACTION = CalculateElo::class;

    protected const EXTRA_SIGNATURE_OPTIONS = [
        '{--regress : Apply offseason regression toward mean before calculating}',
    ];

    protected function getAnalyticsSeasonTypes(): ?array
    {
        return [
            (string) config('nfl.season.types.regular'),
            (string) config('nfl.season.types.postseason'),
        ];
    }

    protected function applyRegressionTowardMean(?int $targetSeason = null): void
    {
        parent::applyRegressionTowardMean($targetSeason);

        if ($targetSeason === null) {
            return;
        }

        $baselineDate = Carbon::today();

        Team::query()->each(function (Team $team) use ($targetSeason, $baselineDate) {
            $priorRating = EloRating::query()
                ->where('team_id', $team->id)
                ->where('season', '<', $targetSeason)
                ->orderByDesc('season')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->value('elo_rating');

            $regressed = (int) ($team->elo_rating ?? $this->getDefaultElo());
            $delta = $priorRating !== null ? round((float) $regressed - (float) $priorRating, 1) : 0;

            EloRating::updateOrCreate(
                [
                    'team_id' => $team->id,
                    'season' => $targetSeason,
                    'week' => 0,
                ],
                [
                    'game_id' => null,
                    'date' => $baselineDate,
                    'elo_rating' => $regressed,
                    'elo_change' => $delta,
                ]
            );
        });
    }
}
