<?php

namespace App\Actions\Sports\Concerns;

trait CalculatesGridironTeamMetrics
{
    protected function calculateAverage(array $values): float
    {
        if (empty($values)) {
            return 0;
        }

        return array_sum($values) / count($values);
    }

    protected function calculateAverageYards(array $teamStats): float
    {
        return $this->calculateAverageByField($teamStats, 'total_yards');
    }

    protected function calculateAveragePassingYards(array $teamStats): float
    {
        return $this->calculateAverageByField($teamStats, 'passing_yards');
    }

    protected function calculateAverageRushingYards(array $teamStats): float
    {
        return $this->calculateAverageByField($teamStats, 'rushing_yards');
    }

    protected function calculateAverageByField(array $teamStats, string $field): float
    {
        if (empty($teamStats)) {
            return 0;
        }

        $total = 0;
        foreach ($teamStats as $stat) {
            $total += $stat->{$field} ?? 0;
        }

        return $total / count($teamStats);
    }

    protected function calculateTurnoverDifferential(array $teamStats, array $opponentStats): float
    {
        $teamTurnovers = 0;
        $opponentTurnovers = 0;

        foreach ($teamStats as $stat) {
            $teamTurnovers += ($stat->interceptions ?? 0) + ($stat->fumbles_lost ?? 0);
        }

        foreach ($opponentStats as $stat) {
            $opponentTurnovers += ($stat->interceptions ?? 0) + ($stat->fumbles_lost ?? 0);
        }

        $gameCount = max(count($teamStats), 1);

        return ($opponentTurnovers - $teamTurnovers) / $gameCount;
    }
}
