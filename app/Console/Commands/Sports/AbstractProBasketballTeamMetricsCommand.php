<?php

namespace App\Console\Commands\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class AbstractProBasketballTeamMetricsCommand extends AbstractCalculateTeamMetricsCommand
{
    protected function buildSignature(): string
    {
        return sprintf(
            "%s\n {--season= : Calculate metrics for a specific season (defaults to current year)}\n {--team= : Calculate metrics for a specific team ID}\n {--season-type= : Limit team metrics to a specific season type}",
            $this->commandName()
        );
    }

    protected function modifyTopTeamsQuery(mixed $query, int|string $season): mixed
    {
        if ($this->requestedMetricSeasonType === null) {
            return $query;
        }

        $model = $query->getModel();
        if (! Schema::hasColumn($model->getTable(), 'season_type')) {
            return $query;
        }

        return $query->where("{$model->getTable()}.season_type", (string) $this->requestedMetricSeasonType);
    }

    protected function requestedSeasonType(): int|string|null
    {
        $requested = parent::requestedSeasonType();
        if ($requested !== null) {
            return $requested;
        }

        $sport = str($this->commandName())->before(':')->toString();

        return config("{$sport}.season.default_team_metrics_type");
    }

    protected function topTeamsTitle(): string
    {
        return 'Top 10 Teams by Net Rating:';
    }

    protected function topRatingColumn(): string
    {
        return 'net_rating';
    }

    protected function topTableHeaders(): array
    {
        return ['Rank', 'Team', 'ORtg', 'DRtg', 'Net', 'Tempo', 'SOS Elo'];
    }

    protected function topTableFields(): array
    {
        return [
            'offensive_efficiency' => 1,
            'defensive_efficiency' => 1,
            'net_rating' => 1,
            'tempo' => 1,
            'strength_of_schedule' => 3,
        ];
    }

    protected function displayTeamMetric(Model $metric): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Season Type', $metric->season_type ?? 'N/A'],
                ['Offensive Efficiency', round($metric->offensive_efficiency, 1)],
                ['Defensive Efficiency', round($metric->defensive_efficiency, 1)],
                ['Net Rating', round($metric->net_rating, 1)],
                ['Tempo', round($metric->tempo, 1)],
                ['SOS Elo', $metric->strength_of_schedule ?? 'N/A'],
                ['L5 Form', $metric->recent_form_rating ?? 'N/A'],
                ['Availability Elo', $metric->injury_adjusted_team_rating ?? 'N/A'],
                ['Schedule Fatigue', $metric->rest_travel_fatigue ?? 'N/A'],
                ['Calculation Date', $metric->calculation_date->format('Y-m-d')],
            ]
        );
    }
}
