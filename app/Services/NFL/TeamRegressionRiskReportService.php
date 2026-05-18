<?php

namespace App\Services\NFL;

use App\Models\NFL\Team;
use App\Models\NFL\TeamMetric;
use Carbon\Carbon;

class TeamRegressionRiskReportService
{
    public function __construct(
        protected TeamFuturesProjectionService $projectionService,
        protected TeamPlayoffForecastService $playoffForecastService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(
        int $season,
        ?string $asOfDate = null,
        bool $requireHistoricalMetrics = false,
        int $limit = 12,
        string $mode = 'regression'
    ): array {
        $targetDate = $asOfDate !== null ? Carbon::parse($asOfDate) : null;
        $previousSeason = max(0, $season - 1);
        $mode = $mode === 'breakout' ? 'breakout' : 'regression';

        $projections = collect($this->projectionService->projections(
            season: $season,
            market: 'season_wins',
            asOfDate: $targetDate,
            requireHistoricalMetrics: $requireHistoricalMetrics,
            onlyWithOdds: false,
            sortBy: 'projected_total',
            direction: 'desc',
            limit: 64,
        ));

        if ($projections->isEmpty()) {
            return [
                'report_type' => "nfl_team_{$mode}_risk",
                'season' => $season,
                'previous_season' => $previousSeason,
                'as_of_date' => $targetDate?->toIso8601String(),
                'mode' => $mode,
                'summary' => ['count' => 0],
                'teams' => [],
            ];
        }

        $forecast = $this->playoffForecastService->forecast(
            season: $season,
            asOfDate: $targetDate?->toIso8601String(),
            requireHistoricalMetrics: $requireHistoricalMetrics,
            simulations: 5000,
            seed: 20260402,
        );

        $playoffByTeam = collect($forecast['teams'] ?? [])->keyBy('team_id');
        $actuals = TeamMetric::query()
            ->where('season', $previousSeason)
            ->where('season_type', (string) config('nfl.season.types.regular', 2))
            ->get(['team_id', 'wins', 'losses', 'predictive_rating', 'recent_form_rating'])
            ->keyBy('team_id');
        $teams = Team::query()
            ->whereIn('id', $projections->pluck('team_id')->all())
            ->get(['id', 'location', 'name', 'abbreviation'])
            ->keyBy('id');

        $rows = $projections->map(function (array $projection) use ($actuals, $teams, $playoffByTeam, $mode): array {
            $teamId = (int) ($projection['team_id'] ?? 0);
            $actual = $actuals->get($teamId);
            $team = $teams->get($teamId);
            $playoff = $playoffByTeam->get($teamId);

            $projectedWins = round((float) ($projection['projected_total'] ?? 0.0), 3);
            $actualWins = (int) ($actual->wins ?? 0);
            $delta = round($projectedWins - $actualWins, 3);

            return [
                'team_id' => $teamId,
                'team_name' => trim(implode(' ', array_filter([$team?->location, $team?->name]))),
                'abbreviation' => $team?->abbreviation,
                'actual_wins' => $actualWins,
                'projected_wins' => $projectedWins,
                'win_delta' => $delta,
                'regression_amount' => round(max(0.0, $actualWins - $projectedWins), 3),
                'breakout_amount' => round(max(0.0, $projectedWins - $actualWins), 3),
                'risk_tier' => $this->riskTier($actualWins - $projectedWins, $mode),
                'playoff_probability' => round((float) ($playoff['make_playoffs_probability'] ?? 0.0), 4),
                'division_winner_probability' => round((float) ($playoff['division_winner_probability'] ?? 0.0), 4),
                'conference_champion_probability' => round((float) ($playoff['conference_champion_probability'] ?? 0.0), 4),
                'super_bowl_champion_probability' => round((float) ($playoff['super_bowl_champion_probability'] ?? 0.0), 4),
                'predictive_rating' => round((float) ($actual->predictive_rating ?? 0.0), 3),
                'recent_form_rating' => round((float) ($actual->recent_form_rating ?? 0.0), 3),
            ];
        });

        $rows = $mode === 'breakout'
            ? $rows->sortByDesc('breakout_amount')->values()
            : $rows->sortByDesc('regression_amount')->values();

        return [
            'report_type' => "nfl_team_{$mode}_risk",
            'season' => $season,
            'previous_season' => $previousSeason,
            'as_of_date' => $targetDate?->toIso8601String(),
            'mode' => $mode,
            'require_historical_metrics' => $requireHistoricalMetrics,
            'summary' => [
                'count' => $rows->count(),
                'average_movement_amount' => round((float) $rows->avg($mode === 'breakout' ? 'breakout_amount' : 'regression_amount'), 3),
                'high_risk_count' => $rows->whereIn('risk_tier', ['very_high', 'high'])->count(),
            ],
            'teams' => $rows->take(max(1, $limit))->all(),
        ];
    }

    protected function riskTier(float $movementAmount, string $mode): string
    {
        $amount = $mode === 'breakout'
            ? max(0.0, -$movementAmount)
            : max(0.0, $movementAmount);

        if ($amount >= 4.0) {
            return 'very_high';
        }

        if ($amount >= 2.5) {
            return 'high';
        }

        if ($amount >= 1.5) {
            return 'medium';
        }

        if ($amount > 0.0) {
            return 'low';
        }

        return 'none';
    }
}
