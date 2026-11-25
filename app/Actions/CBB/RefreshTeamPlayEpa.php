<?php

namespace App\Actions\CBB;

use App\Actions\Sports\Concerns\CalculatesTeamTrueEpaFromPlays;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Services\CBB\TeamMetricSample;

class RefreshTeamPlayEpa
{
    use CalculatesTeamTrueEpaFromPlays;

    public function execute(Team $team, int $season): bool
    {
        $metric = TeamMetric::query()->where('team_id', $team->id)->where('season', $season)->first();
        if ($metric === null) {
            return false;
        }
        $sample = app(TeamMetricSample::class);
        $games = $sample->forTeam($team, $season);
        // Match the stored metric's complete-game denominator and require EPA
        // from both sides of every included game before publishing an aggregate.
        if ($games->isEmpty() || $games->count() !== (int) $metric->games_played) {
            return $this->hold($metric);
        }
        if ($sample->missingEpaGameIds($games) !== []) {
            return $this->hold($metric);
        }
        $values = $this->calculateTeamTrueEpaMetrics(Play::class, (int) $team->id, $games);
        $values = array_map(fn (?float $value): ?float => $this->roundOrNull($value, 3), $values);

        return TeamMetric::query()->where('team_id', $team->id)->where('season', $season)
            ->update([...$values, 'updated_at' => now()]) > 0;
    }

    private function hold(TeamMetric $metric): bool
    {
        TeamMetric::query()->whereKey($metric->id)->update([
            'offensive_true_epa_per_play' => null,
            'defensive_true_epa_per_play' => null,
            'net_true_epa_per_play' => null,
        ]);

        return false;
    }
}
