<?php

namespace App\Services\NFL;

use App\Models\NFL\Team;
use App\Support\NflTeamAlignment;
use Carbon\CarbonInterface;

class NflPlayoffTeamInputs
{
    public function __construct(private readonly TeamFuturesProjectionService $projections) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function load(int $season, ?CarbonInterface $asOfDate, bool $requireHistoricalMetrics): array
    {
        $rows = $this->projections->projections(
            season: $season,
            market: 'season_wins',
            asOfDate: $asOfDate,
            requireHistoricalMetrics: $requireHistoricalMetrics || $asOfDate !== null,
            onlyWithOdds: false,
            sortBy: 'projected_total',
            direction: 'desc',
            limit: 64,
        );

        return $this->buildTeamInputs($rows);
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<int, array<string,mixed>>
     */
    private function buildTeamInputs(array $rows): array
    {
        $teamModels = Team::query()->whereIn('id', array_map(fn (array $row) => (int) $row['team_id'], $rows))
            ->get(['id', 'abbreviation', 'location', 'name', 'conference', 'division'])
            ->keyBy('id');

        $teams = [];
        foreach ($rows as $row) {
            $teamId = (int) ($row['team_id'] ?? 0);
            $teamModel = $teamModels->get($teamId);
            if ($teamId <= 0 || ! $teamModel) {
                continue;
            }

            $alignment = $this->resolveAlignment($teamModel);
            if ($alignment === null) {
                continue;
            }

            $teamName = trim(implode(' ', array_filter([$teamModel->location, $teamModel->name])));
            $rating = (float) data_get($row, 'projection_factors.predictive_rating', 0.0);
            if (abs($rating) > 200) {
                $rating = ($rating - 1500.0) / 25.0;
            }
            $played = (int) ($row['games_played'] ?? 0);
            $reliability = $played > 0 ? $played / ($played + max(1, (int) config('nfl.team_futures.prior_games', 4))) : 1.0;

            $teams[] = [
                'team_id' => $teamId,
                'team_name' => $teamName !== '' ? $teamName : (string) ($teamModel->abbreviation ?? $teamId),
                'abbreviation' => (string) ($teamModel->abbreviation ?? ''),
                'conference' => $alignment['conference'],
                'division' => $alignment['division'],
                'projected_total' => (float) ($row['projected_total'] ?? 0.0),
                'strength_rating' => ($rating / 8.0) * $reliability,
            ];
        }

        return $teams;
    }

    private function resolveAlignment(Team $team): ?array
    {
        $conference = $team->conference ? strtoupper((string) $team->conference) : null;
        $division = $team->division ? ucfirst(strtolower((string) $team->division)) : null;

        if ($conference !== null && $division !== null) {
            return ['conference' => $conference, 'division' => $division];
        }

        $alignment = NflTeamAlignment::TEAMS[(string) $team->abbreviation] ?? null;

        return $alignment === null ? null : [
            'conference' => strtoupper($alignment['conference']),
            'division' => ucfirst($alignment['division']),
        ];
    }
}
