<?php

namespace App\Console\Commands\CFB;

use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;
use Illuminate\Database\Eloquent\Builder;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'cfb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate CFB team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = \App\Actions\CFB\CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = \App\Models\CFB\Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['display_name'];

    protected function beforeBulkCalculation(object $calculateMetrics, int|string $season): void
    {
        if (method_exists($calculateMetrics, 'purgeNonFbsMetrics')) {
            $calculateMetrics->purgeNonFbsMetrics((int) $season);
        }
    }

    protected function modifyTeamsQuery(Builder $query, int|string $season): Builder
    {
        $fbs = config('cfb.teams.divisions.fbs', 'FBS');

        return $query->whereHas('seasonAffiliations', function (Builder $affiliationQuery) use ($season, $fbs): void {
            $affiliationQuery
                ->where('season', (int) $season)
                ->where('subdivision', $fbs);
        });
    }

    protected function modifyTopTeamsQuery(mixed $query, int|string $season): mixed
    {
        $affiliationsTable = (new TeamSeasonAffiliation)->getTable();
        $fbs = config('cfb.teams.divisions.fbs', 'FBS');

        return $query
            ->join($affiliationsTable, function ($join) use ($affiliationsTable, $fbs, $season) {
                $join->on("{$affiliationsTable}.team_id", '=', 'cfb_team_metrics.team_id')
                    ->on("{$affiliationsTable}.season", '=', 'cfb_team_metrics.season')
                    ->where("{$affiliationsTable}.season", '=', (int) $season)
                    ->where("{$affiliationsTable}.subdivision", '=', $fbs);
            })
            ->select('cfb_team_metrics.*');
    }

    protected function topTeamsTitle(): string
    {
        return 'Top 10 FBS Teams by CFP Rating:';
    }

    protected function topRatingColumn(): string
    {
        return 'cfp_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'CFP', 'Resume', 'Power', 'Net Rtg', 'SOS'];
    }

    protected function topTableFields(): array
    {
        return [
            'cfp_rating' => 3,
            'resume_rating' => 3,
            'power_rating' => 3,
            'net_rating' => 1,
            'strength_of_schedule' => 3,
        ];
    }
}
