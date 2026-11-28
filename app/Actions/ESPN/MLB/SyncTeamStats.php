<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\Concerns\ValidatesBoxscoreReplacement;
use App\Actions\ESPN\MLB\Concerns\ParsesMlbStatValues;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbBoxscoreTeams;
use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Models\MLB\TeamStat;
use App\Support\ESPN\ObservedStatValue;
use DomainException;

class SyncTeamStats
{
    use ParsesMlbStatValues;
    use ResolvesMlbBoxscoreTeams;
    use ValidatesBoxscoreReplacement;

    public function execute(array $gameData, Game $game): int
    {
        return $game->getConnection()->transaction(function () use ($gameData, $game): int {
            $game = $game->newQuery()->whereKey($game->getKey())->lockForUpdate()->firstOrFail();
            try {
                return $this->replaceTeamStats($gameData, $game);
            } catch (DomainException) {
                return 0;
            }
        });
    }

    protected function replaceTeamStats(array $gameData, Game $game): int
    {
        $teamSections = $this->boxscoreSection($gameData, 'teams');
        if ($teamSections === []) {
            return 0;
        }

        $teams = $this->replacementTeams($teamSections, $game, Team::class, TeamStat::class);
        if ($teams === null) {
            return 0;
        }
        $rows = [];
        $synced = 0;

        foreach ($teamSections as $teamData) {
            $team = $teams->get($teamData['team']['id']);
            if (! is_array($teamData['statistics'] ?? null) || $teamData['statistics'] === []) {
                return 0;
            }

            // Determine home/away from game record
            $teamType = ($team->id === $game->home_team_id) ? 'home' : 'away';

            $stats = $this->parseTeamStats($teamData['statistics'] ?? []);

            $attributes = [
                'team_id' => $team->id,
                'game_id' => $game->id,
                'team_type' => $teamType,
                // Batting stats
                'runs' => $stats['batting']['runs'] ?? null,
                'hits' => $stats['batting']['hits'] ?? null,
                'errors' => $stats['fielding']['errors'] ?? null,
                'at_bats' => $stats['batting']['atBats'] ?? null,
                'doubles' => $stats['batting']['doubles'] ?? null,
                'triples' => $stats['batting']['triples'] ?? null,
                'home_runs' => $stats['batting']['homeRuns'] ?? null,
                'rbis' => $stats['batting']['RBIs'] ?? null,
                'walks' => $stats['batting']['walks'] ?? null,
                'hit_by_pitch' => $this->firstStatValue($stats['batting'] ?? [], [
                    'hitByPitch',
                    'hitByPitches',
                    'hit_by_pitch',
                    'HBP',
                ]),
                'sacrifice_flies' => $this->firstStatValue($stats['batting'] ?? [], [
                    'sacrificeFlies',
                    'sacFlies',
                    'sacrifice_flies',
                    'SF',
                ]),
                'strikeouts' => $stats['batting']['strikeouts'] ?? null,
                'stolen_bases' => $stats['batting']['stolenBases'] ?? null,
                'left_on_base' => $stats['batting']['runnersLeftOnBase'] ?? null,
                'batting_average' => $stats['batting']['avg'] ?? null,
                // Pitching stats
                'pitchers_used' => $this->resolvePitchersUsed($stats['pitching'] ?? []),
                'innings_pitched' => $stats['pitching']['innings'] ?? null,
                'hits_allowed' => $stats['pitching']['hits'] ?? null,
                'runs_allowed' => $stats['pitching']['runs'] ?? null,
                'earned_runs' => $stats['pitching']['earnedRuns'] ?? null,
                'walks_allowed' => $stats['pitching']['walks'] ?? null,
                'strikeouts_pitched' => $stats['pitching']['strikeouts'] ?? null,
                'home_runs_allowed' => $stats['pitching']['homeRuns'] ?? null,
                'total_pitches' => $stats['pitching']['pitches'] ?? null,
                'era' => $stats['pitching']['ERA'] ?? null,
                // Fielding stats
                'putouts' => $stats['fielding']['putouts'] ?? null,
                'assists' => $stats['fielding']['assists'] ?? null,
                'double_plays' => $stats['fielding']['doublePlays'] ?? null,
                'passed_balls' => $stats['fielding']['passedBalls'] ?? null,
            ];

            if (collect(array_diff_key($attributes, array_flip(['team_id', 'game_id', 'team_type'])))->every(fn ($value): bool => $value === null)) {
                return 0;
            }
            $rows[] = $attributes;

            $synced++;
        }

        if (! $this->preservesObservedFields(TeamStat::class, $game, $rows, ['team_id'])) {
            return 0;
        }
        TeamStat::query()->where('game_id', $game->id)->delete();
        foreach ($rows as $row) {
            TeamStat::query()->create($row);
        }

        if ($synced > 0) {
            app(ReconcileGameScoreFromTeamStats::class)->execute($game->fresh());
        }

        return $synced;
    }

    protected function parseTeamStats(array $statistics): array
    {
        $parsed = ['batting' => [], 'pitching' => [], 'fielding' => []];
        $seen = [];
        foreach ($statistics as $category) {
            if (! is_array($category) || ! is_string($category['name'] ?? null) || ! is_array($category['stats'] ?? null)) {
                throw new DomainException('Missing MLB team category.');
            }
            $name = $category['name'];
            if (! array_key_exists($name, $parsed)) {
                continue;
            }
            if (isset($seen[$name])) {
                throw new DomainException('Duplicate MLB team category.');
            }
            $seen[$name] = true;
            foreach ($category['stats'] as $stat) {
                if (! is_array($stat) || ! is_string($stat['name'] ?? null) || ! array_key_exists('displayValue', $stat)) {
                    throw new DomainException('Missing MLB team observation.');
                }
                $key = $stat['name'];
                if (array_key_exists($key, $parsed[$name])) {
                    throw new DomainException('Duplicate MLB team observation.');
                }
                $value = $stat['displayValue'];
                $counts = match ($name) {
                    'batting' => ['runs', 'hits', 'atBats', 'doubles', 'triples', 'homeRuns', 'RBIs', 'walks',
                        'hitByPitch', 'hitByPitches', 'hit_by_pitch', 'HBP', 'sacrificeFlies', 'sacFlies', 'sacrifice_flies', 'SF',
                        'strikeouts', 'stolenBases', 'runnersLeftOnBase'],
                    'pitching' => ['pitchersUsed', 'pitchers', 'totalPitchersUsed', 'staffPitchersUsed', 'hits', 'runs', 'earnedRuns', 'walks', 'strikeouts', 'homeRuns', 'pitches'],
                    'fielding' => ['errors', 'putouts', 'assists', 'doublePlays', 'passedBalls'],
                };
                if (! in_array($key, [...$counts, ...($name === 'pitching' ? ['innings', 'ERA'] : ($name === 'batting' ? ['avg'] : []))], true)) {
                    continue;
                }
                $parsed[$name][$key] = match (true) {
                    $key === 'innings' => $this->normalizeInningsPitched($value),
                    $key === 'avg' => ObservedStatValue::decimal($value, maximum: 1.0),
                    $key === 'ERA' => ObservedStatValue::decimal($value, maximum: 999.99),
                    default => ObservedStatValue::integer($value),
                };
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $pitchingStats
     */
    protected function resolvePitchersUsed(array $pitchingStats): ?int
    {
        $value = $this->firstStatValue($pitchingStats, ['pitchersUsed', 'pitchers', 'totalPitchersUsed', 'staffPitchersUsed']);

        return $value === null ? null : ObservedStatValue::integer($value);
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<int, string>  $keys
     */
    protected function firstStatValue(array $stats, array $keys): mixed
    {
        $found = null;
        foreach ($keys as $key) {
            if (! array_key_exists($key, $stats)) {
                continue;
            }
            if ($found !== null && $found !== $stats[$key]) {
                throw new DomainException('Conflicting MLB team aliases.');
            }
            $found = $stats[$key];
        }

        return $found;
    }
}
