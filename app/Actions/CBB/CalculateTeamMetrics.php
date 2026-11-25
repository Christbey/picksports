<?php

namespace App\Actions\CBB;

use App\Actions\Sports\AbstractCollegeBasketballCalculateTeamMetrics;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Services\CBB\TeamMetricSample;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

class CalculateTeamMetrics extends AbstractCollegeBasketballCalculateTeamMetrics
{
    protected function getCompletedGamesForTeam(Model $team, int $season, string $sport, int|string|null $seasonType = null): Collection
    {
        return app(TeamMetricSample::class)->forTeam($team, $season, $seasonType);
    }

    protected function calculateTeamTrueEpaMetrics(string $playModelClass, int $teamId, SupportCollection $games, bool $eligibleOnly = false): array
    {
        if (app(TeamMetricSample::class)->missingEpaGameIds($games, $eligibleOnly) !== []) {
            return $this->emptyTrueEpaMetrics();
        }

        return parent::calculateTeamTrueEpaMetrics($playModelClass, $teamId, $games, $eligibleOnly);
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
