<?php

namespace App\Console\Commands\CFB;

use App\Actions\CFB\CalculateTeamMetrics;
use App\Console\Commands\Sports\AbstractFootballTeamMetricsCommand;
use App\Models\CFB\Team;
use App\Models\CFB\TeamMetric;
use App\Models\CFB\TeamSeasonAffiliation;
use Illuminate\Database\Eloquent\Builder;

class CalculateTeamMetricsCommand extends AbstractFootballTeamMetricsCommand
{
    protected const COMMAND_NAME = 'cfb:calculate-team-metrics';

    protected const COMMAND_DESCRIPTION = 'Calculate CFB team metrics (offensive/defensive ratings, yards, turnovers, SOS)';

    protected const CALCULATE_METRICS_ACTION_CLASS = CalculateTeamMetrics::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const TEAM_METRIC_MODEL_CLASS = TeamMetric::class;

    protected const TEAM_DISPLAY_FIELDS = ['display_name'];

    protected function buildSignature(): string
    {
        return parent::buildSignature().' {--team-ids= : Refresh a bounded batch of up to ten team IDs}';
    }

    public function handle(): int
    {
        if (blank($this->option('team-ids'))) {
            return parent::handle();
        }
        $raw = (string) $this->option('team-ids');
        if ($this->option('team') || ! preg_match('/^\d+(,\d+)*$/', $raw)) {
            $this->error('Provide only a comma-separated batch of team IDs.');

            return self::FAILURE;
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        $season = (int) ($this->option('season') ?? date('Y'));
        $teams = $this->modifyTeamsQuery(Team::whereIn('id', $ids), $season)->get();
        if (count($ids) > 10 || $teams->count() !== count($ids)) {
            $this->error('Metric batches require at most ten verified FBS teams.');

            return self::FAILURE;
        }
        $action = app(CalculateTeamMetrics::class);
        foreach ($teams as $team) {
            if (! $action->execute($team, $season)) {
                $this->error("Team {$team->id}: required current metrics could not be refreshed.");

                return self::FAILURE;
            }
        }
        $this->info('Refreshed metrics for '.$teams->count().' verified FBS teams.');

        return self::SUCCESS;
    }

    protected function beforeBulkCalculation(object $calculateMetrics, int|string $season): void
    {
        $missing = Team::query()->whereDoesntHave('seasonAffiliations', function (Builder $query) use ($season): void {
            $query->where('season', (int) $season)->where('source', 'cfbd_fbs_membership');
        })->count();
        if ($missing > 0) {
            throw new \RuntimeException("{$missing} teams lack authoritative {$season} affiliations. Run cfb:sync-season-affiliations --season={$season} first.");
        }

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
