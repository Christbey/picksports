<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballCalculateTeamMetrics;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Services\CBB\CbbDataQuality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class CalculateTeamMetrics extends AbstractCollegeBasketballCalculateTeamMetrics
{
    protected function getCompletedGamesForTeam(Model $team, int $season, string $sport, int|string|null $seasonType = null): Collection
    {
        return app(CbbDataQuality::class)->reconciledGames(Game::query())
            ->where('season', $season)
            ->whereIn('season_type', $seasonType === null ? [2, 3, 'regular', 'postseason'] : [$seasonType])
            ->where(fn (Builder $query) => $query->where('home_team_id', $team->id)->orWhere('away_team_id', $team->id))
            ->with(['teamStats', 'homeTeam', 'awayTeam'])->orderBy('game_date')->orderBy('id')->get()
            ->filter(fn (Game $game): bool => app(CbbDataQuality::class)->boxScoreIssues($game, $game->teamStats) === []);
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
        return 'CBB';
    }

    protected function sportKey(): string
    {
        return 'cbb';
    }

    protected function configPrefix(): string
    {
        return 'cbb';
    }

    protected function shouldGateByMinimumGames(): bool
    {
        return true;
    }
}
