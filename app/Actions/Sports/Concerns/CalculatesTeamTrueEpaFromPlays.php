<?php

namespace App\Actions\Sports\Concerns;

use Illuminate\Support\Collection;

trait CalculatesTeamTrueEpaFromPlays
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $playModelClass
     * @param  Collection<int,\Illuminate\Database\Eloquent\Model>  $games
     * @return array{
     *   offensive_true_epa_per_play:?float,
     *   defensive_true_epa_per_play:?float,
     *   net_true_epa_per_play:?float
     * }
     */
    protected function calculateTeamTrueEpaMetrics(
        string $playModelClass,
        int $teamId,
        Collection $games,
        bool $eligibleOnly = false
    ): array {
        if ($games->isEmpty()) {
            return $this->emptyTrueEpaMetrics();
        }

        $validTeamIds = $games->flatMap(function ($game) {
            return [
                (int) ($game->home_team_id ?? 0),
                (int) ($game->away_team_id ?? 0),
            ];
        })->filter(fn (int $id) => $id > 0)->unique()->values()->all();

        if ($validTeamIds === []) {
            return $this->emptyTrueEpaMetrics();
        }

        $query = $playModelClass::query()
            ->whereIn('game_id', $games->pluck('id')->all())
            ->whereIn('possession_team_id', $validTeamIds)
            ->whereNotNull('true_epa')
            ->whereNotNull('possession_team_id')
            ->select(['possession_team_id', 'true_epa']);

        if ($eligibleOnly) {
            $query->where('is_epa_eligible', true);
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return $this->emptyTrueEpaMetrics();
        }

        $offensive = $rows->where('possession_team_id', $teamId)->pluck('true_epa');
        $defensive = $rows->where('possession_team_id', '!=', $teamId)->pluck('true_epa');

        $offensiveAvg = $offensive->isEmpty() ? null : (float) $offensive->avg();
        $defensiveAvg = $defensive->isEmpty() ? null : (float) $defensive->avg();
        $net = ($offensiveAvg !== null && $defensiveAvg !== null)
            ? $offensiveAvg - $defensiveAvg
            : null;

        return [
            'offensive_true_epa_per_play' => $offensiveAvg,
            'defensive_true_epa_per_play' => $defensiveAvg,
            'net_true_epa_per_play' => $net,
        ];
    }

    /**
     * @return array{
     *   offensive_true_epa_per_play:?float,
     *   defensive_true_epa_per_play:?float,
     *   net_true_epa_per_play:?float
     * }
     */
    protected function emptyTrueEpaMetrics(): array
    {
        return [
            'offensive_true_epa_per_play' => null,
            'defensive_true_epa_per_play' => null,
            'net_true_epa_per_play' => null,
        ];
    }

    protected function roundOrNull(?float $value, int $precision = 3): ?float
    {
        return $value === null ? null : round($value, $precision);
    }
}
