<?php

namespace App\Services\NFL;

use App\Actions\NFL\CalculateTeamMetrics;
use App\Models\NFL\Game;
use App\Models\NFL\TeamMetric;
use Illuminate\Support\Collection;

class NflTrueEpaReadinessService
{
    public function __construct(
        private readonly CalculateTeamMetrics $calculateTeamMetrics,
    ) {}

    /**
     * Refresh incomplete true-EPA team metrics for the exact teams on the slate.
     *
     * @param  Collection<int, Game>  $games
     * @return array{enabled:bool,checked:int,attempted:int,backfilled:int,missing:array<int, array{team_id:int,season:int,season_type:string}>}
     */
    public function prepare(Collection $games): array
    {
        if (config('nfl.predictions.true_epa.custom_epa_quarantined', true)
            || ! config('nfl.predictions.true_epa.enabled', false)
            || ! config('nfl.predictions.true_epa.backfill_before_generation', true)) {
            return ['enabled' => false, 'checked' => 0, 'attempted' => 0, 'backfilled' => 0, 'missing' => []];
        }

        $regularSeasonType = (string) config('nfl.season.types.regular', 2);
        $targets = $games->flatMap(function (Game $game) use ($regularSeasonType): array {
            return collect([$game->homeTeam, $game->awayTeam])
                ->filter()
                ->map(fn ($team): array => [
                    'team' => $team,
                    'team_id' => (int) $team->getKey(),
                    'season' => (int) $game->season,
                    'season_type' => $regularSeasonType,
                ])->all();
        })->unique(fn (array $target): string => $target['season'].'|'.$target['season_type'].'|'.$target['team_id'])->values();

        $attempted = 0;
        $backfilled = 0;
        $missing = [];

        foreach ($targets as $target) {
            $metric = $this->metric($target['team_id'], $target['season'], $target['season_type']);
            if ($this->complete($metric)) {
                continue;
            }

            $attempted++;
            $this->calculateTeamMetrics->execute($target['team'], $target['season'], $target['season_type']);
            $metric = $this->metric($target['team_id'], $target['season'], $target['season_type']);

            if ($this->complete($metric)) {
                $backfilled++;

                continue;
            }

            $missing[] = [
                'team_id' => $target['team_id'],
                'season' => $target['season'],
                'season_type' => $target['season_type'],
            ];
        }

        return [
            'enabled' => true,
            'checked' => $targets->count(),
            'attempted' => $attempted,
            'backfilled' => $backfilled,
            'missing' => $missing,
        ];
    }

    private function metric(int $teamId, int $season, string $seasonType): ?TeamMetric
    {
        return TeamMetric::query()
            ->where('team_id', $teamId)
            ->where('season', $season)
            ->where('season_type', $seasonType)
            ->first();
    }

    private function complete(?TeamMetric $metric): bool
    {
        return $metric !== null
            && $metric->offensive_true_epa_per_play !== null
            && $metric->defensive_true_epa_per_play !== null
            && $metric->net_true_epa_per_play !== null;
    }
}
