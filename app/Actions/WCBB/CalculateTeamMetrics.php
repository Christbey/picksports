<?php

namespace App\Actions\WCBB;

use App\Actions\Sports\AbstractCollegeBasketballCalculateTeamMetrics;
use App\Models\WCBB\Game;
use App\Models\WCBB\Play;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Services\Sports\SportsDateWindowService;
use App\Services\WCBB\WcbbMetricSourceEvidence;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CalculateTeamMetrics extends AbstractCollegeBasketballCalculateTeamMetrics
{
    protected function getCompletedGamesForTeam(Model $team, int $season, string $sport, int|string|null $seasonType = null): Collection
    {
        return parent::getCompletedGamesForTeam($team, $season, $sport, $seasonType)
            ->filter(function (Game $game): bool {
                $start = app(SportsDateWindowService::class)->gameDateTimeUtc($game->game_date, $game->game_time);

                return $start !== null && $start->lessThanOrEqualTo(now());
            });
    }

    public function execute(Model $team, int $season): ?Model
    {
        $coverage = app(WcbbMetricSourceEvidence::class)->coverage((int) $team->getKey(), $season);
        if ($coverage['usable_paired_games'] !== $coverage['completed_games']) {
            Log::warning('WCBB team metrics held because final-game scoring or possession evidence is incomplete.', [
                'team_id' => $team->getKey(), 'season' => $season, ...$coverage,
            ]);

            return null;
        }

        return parent::execute($team, $season);
    }

    protected function teamModelClass(): string
    {
        return Team::class;
    }

    protected function teamMetricModelClass(): string
    {
        return TeamMetric::class;
    }

    protected function gameModelClass(): string
    {
        return Game::class;
    }

    protected function playModelClass(): string
    {
        return Play::class;
    }

    protected function sportCode(): string
    {
        return 'WCBB';
    }

    protected function sportKey(): string
    {
        return 'wcbb';
    }

    protected function configPrefix(): string
    {
        return 'wcbb';
    }

    protected function shouldGateByMinimumGames(): bool
    {
        return false;
    }
}
