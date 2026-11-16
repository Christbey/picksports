<?php

namespace App\Services\NFL;

class NflPlayoffForecastReport
{
    private array $tallies = [];

    private array $seededFields = [];

    private int $accepted = 0;

    public function __construct(array $teams, private readonly string $modelVersion)
    {
        foreach ($teams as $team) {
            $this->tallies[$team['team_id']] = [
                'team_id' => $team['team_id'],
                'team_name' => $team['team_name'],
                'conference' => $team['conference'],
                'division' => $team['division'],
                'wins' => 0,
                'ties' => 0,
                'seed_sum' => 0,
                'division_titles' => 0,
                'playoff_berths' => 0,
                'conference_titles' => 0,
                'super_bowl_titles' => 0,
            ];
        }
    }

    public static function unavailable(int $season, ?string $asOfDate, array $warnings): array
    {
        return [
            ...self::header($season, $asOfDate),
            'summary' => ['teams' => 0, 'simulations' => 0],
            'teams' => [],
            'projected_bracket' => [],
            'division_leaders' => [],
            'conference_leaders' => [],
            'super_bowl_leaders' => [],
            'warnings' => $warnings,
        ];
    }

    public function record(array $sampled, array $standings, array $bracket): void
    {
        if ($bracket['unmatched_games'] !== []) {
            return;
        }

        $this->accepted++;
        $fields = [];
        foreach ($sampled as $team) {
            $teamId = $team['team_id'];
            $standing = $standings[$teamId] ?? [];
            $this->tallies[$teamId]['wins'] += $team['sampled_wins'];
            $this->tallies[$teamId]['ties'] += $team['sampled_ties'];
            $this->tallies[$teamId]['seed_sum'] += $standing['seed'] ?? 0;
            $this->tallies[$teamId]['division_titles'] += (int) ($standing['division_winner'] ?? false);
            $this->tallies[$teamId]['playoff_berths'] += (int) ($standing['made_playoffs'] ?? false);
            if (isset($standing['seed'])) {
                $fields[$this->tallies[$teamId]['conference']][$standing['seed']] = $teamId;
            }
        }
        $this->recordSeededFields($fields);

        foreach ($bracket['conference_champions'] as $teamId) {
            if (isset($this->tallies[$teamId])) {
                $this->tallies[$teamId]['conference_titles']++;
            }
        }

        $champion = $bracket['super_bowl_champion'];
        if ($champion !== null && isset($this->tallies[$champion])) {
            $this->tallies[$champion]['super_bowl_titles']++;
        }
    }

    public function build(
        int $season,
        ?string $asOfDate,
        bool $requireHistoricalMetrics,
        int $attempted,
        array $postseason,
        array $tieModel,
        array $warnings
    ): array {
        if ($this->accepted === 0) {
            $warnings[] = 'No simulated bracket could reproduce every known postseason matchup and result.';
        } elseif ($this->accepted < $attempted) {
            $warnings[] = 'Only simulated brackets compatible with every known postseason matchup and result were counted.';
        }

        $teams = $this->teamProbabilities();
        $completedGames = count(array_filter($postseason, fn (array $game): bool => $game['winner'] !== null));

        return [
            ...self::header($season, $asOfDate),
            'require_historical_metrics' => $requireHistoricalMetrics,
            'warnings' => $warnings,
            'generated_at' => now()->toIso8601String(),
            'model_version' => $this->modelVersion,
            'tie_model' => $tieModel,
            'summary' => [
                'teams' => count($teams),
                'simulations' => $this->accepted,
                'attempted_simulations' => $attempted,
                'completed_postseason_games' => $completedGames,
                'scheduled_postseason_games' => count($postseason) - $completedGames,
            ],
            'teams' => $teams,
            'projected_bracket' => $this->projectedBracket(),
            'division_leaders' => $this->leaders($teams, byDivision: true),
            'conference_leaders' => $this->leaders($teams, byDivision: false),
            'super_bowl_leaders' => array_slice($teams, 0, 12),
        ];
    }

    private static function header(int $season, ?string $asOfDate): array
    {
        return ['report_type' => 'nfl_team_playoff_forecast', 'season' => $season, 'as_of_date' => $asOfDate];
    }

    private function recordSeededFields(array $fields): void
    {
        foreach ($fields as $conference => $field) {
            ksort($field);
            if (array_keys($field) !== range(1, 7)) {
                continue;
            }

            $divisionWinners = array_slice($field, 0, 4);
            $wildCards = array_slice($field, 4);
            sort($divisionWinners);
            sort($wildCards);
            $composition = implode(',', $divisionWinners).'|'.implode(',', $wildCards);
            $seedOrder = implode(',', $field);
            $this->seededFields[$conference][$composition][$seedOrder] =
                ($this->seededFields[$conference][$composition][$seedOrder] ?? 0) + 1;
        }
    }

    private function projectedBracket(): array
    {
        $bracket = [];
        foreach (['AFC', 'NFC'] as $conference) {
            $compositions = $this->seededFields[$conference] ?? [];
            if ($compositions === []) {
                continue;
            }

            $frequencies = array_map('array_sum', $compositions);
            arsort($frequencies);
            $seedOrders = $compositions[array_key_first($frequencies)];
            arsort($seedOrders);

            $seeds = [];
            foreach (explode(',', (string) array_key_first($seedOrders)) as $index => $teamId) {
                $seeds[] = ['seed' => $index + 1, 'team_id' => (int) $teamId];
            }
            $bracket[] = ['conference' => $conference, 'seeds' => $seeds];
        }

        return $bracket;
    }

    private function teamProbabilities(): array
    {
        if ($this->accepted === 0) {
            return [];
        }

        $teams = array_map(fn (array $tally): array => [
            'team_id' => $tally['team_id'],
            'team_name' => $tally['team_name'],
            'conference' => $tally['conference'],
            'division' => $tally['division'],
            'projected_wins' => round($tally['wins'] / $this->accepted, 3),
            'projected_ties' => round($tally['ties'] / $this->accepted, 3),
            'projected_seed' => $tally['playoff_berths'] > 0 ? round($tally['seed_sum'] / $tally['playoff_berths'], 2) : null,
            'division_winner_probability' => round($tally['division_titles'] / $this->accepted, 4),
            'wild_card_probability' => round(($tally['playoff_berths'] - $tally['division_titles']) / $this->accepted, 4),
            'make_playoffs_probability' => round($tally['playoff_berths'] / $this->accepted, 4),
            'conference_champion_probability' => round($tally['conference_titles'] / $this->accepted, 4),
            'super_bowl_champion_probability' => round($tally['super_bowl_titles'] / $this->accepted, 4),
        ], $this->tallies);

        usort($teams, fn (array $left, array $right): int => $right['super_bowl_champion_probability'] <=> $left['super_bowl_champion_probability']);

        return $teams;
    }

    private function leaders(array $teams, bool $byDivision): array
    {
        $leaders = [];
        $probability = $byDivision ? 'division_winner_probability' : 'conference_champion_probability';
        foreach ($teams as $team) {
            $key = $byDivision ? $team['conference'].' '.$team['division'] : $team['conference'];
            if (! isset($leaders[$key]) || $team[$probability] > $leaders[$key][$probability]) {
                $leaders[$key] = $team;
            }
        }

        ksort($leaders);

        return array_values($leaders);
    }
}
