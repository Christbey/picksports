<?php

namespace App\Actions\Trends;

use Illuminate\Support\Collection;

abstract class TrendCollector
{
    protected string $league;

    protected Collection $games;

    protected int $teamId;

    protected string $teamAbbr;

    protected object $team;

    abstract public function key(): string;

    /**
     * @return array<int, string>
     */
    abstract public function collect(): array;

    public function setContext(string $league, object $team, Collection $games): self
    {
        $this->league = $league;
        $this->team = $team;
        $this->teamId = $team->id;
        $this->teamAbbr = $team->abbreviation;
        $this->games = $games;

        return $this;
    }

    protected function isHome(object $game): bool
    {
        return $game->home_team_id === $this->teamId;
    }

    protected function teamScore(object $game): ?int
    {
        return $this->isHome($game) ? $game->home_score : $game->away_score;
    }

    protected function opponentScore(object $game): ?int
    {
        return $this->isHome($game) ? $game->away_score : $game->home_score;
    }

    protected function opponentId(object $game): int
    {
        return $this->isHome($game) ? $game->away_team_id : $game->home_team_id;
    }

    protected function won(object $game): bool
    {
        return $this->teamScore($game) > $this->opponentScore($game);
    }

    protected function margin(object $game): int
    {
        return $this->teamScore($game) - $this->opponentScore($game);
    }

    protected function totalPoints(object $game): int
    {
        return ($game->home_score ?? 0) + ($game->away_score ?? 0);
    }

    protected function formatRecord(int $wins, int $total): string
    {
        $losses = $total - $wins;
        $pct = $total > 0 ? round(($wins / $total) * 100) : 0;

        return "{$wins}-{$losses} ({$pct}%)";
    }

    protected function percentage(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("trends.thresholds.{$this->league}.{$key}", $default);
    }

    /**
     * Get team linescores from game.
     *
     * @return array<int, int>
     */
    protected function teamLinescores(object $game): array
    {
        $linescores = $this->isHome($game)
            ? ($game->home_linescores ?? [])
            : ($game->away_linescores ?? []);

        return $this->parseLinescores($linescores);
    }

    /**
     * Get opponent linescores from game.
     *
     * @return array<int, int>
     */
    protected function opponentLinescores(object $game): array
    {
        $linescores = $this->isHome($game)
            ? ($game->away_linescores ?? [])
            : ($game->home_linescores ?? []);

        return $this->parseLinescores($linescores);
    }

    /**
     * Parse linescores from various formats into a simple indexed array.
     *
     * @return array<int, int>
     */
    protected function parseLinescores(mixed $linescores): array
    {
        if (empty($linescores)) {
            return [];
        }

        $data = is_string($linescores) ? json_decode($linescores, true) ?? [] : $linescores;

        if (empty($data)) {
            return [];
        }

        // Check if it's keyed by quarter name (Q1, Q2, Q3, Q4)
        if (isset($data['Q1']) || isset($data['Q2'])) {
            return [
                (int) ($data['Q1'] ?? 0),
                (int) ($data['Q2'] ?? 0),
                (int) ($data['Q3'] ?? 0),
                (int) ($data['Q4'] ?? 0),
            ];
        }

        // Check if it's an array of objects with 'period' and 'value' keys
        if (isset($data[0]) && is_array($data[0]) && array_key_exists('value', $data[0])) {
            $result = [];
            foreach ($data as $period) {
                $periodIndex = ($period['period'] ?? 1) - 1;
                $result[$periodIndex] = (int) ($period['value'] ?? 0);
            }
            ksort($result);

            return array_values($result);
        }

        // Already a simple indexed array
        return array_map('intval', $data);
    }

    /**
     * Get team stats for this game.
     */
    protected function teamStats(object $game): ?object
    {
        if (! $game->relationLoaded('teamStats')) {
            return null;
        }

        return $game->teamStats->first(fn ($stat) => $stat->team_id === $this->teamId);
    }

    /**
     * Get opponent stats for this game.
     */
    protected function opponentStats(object $game): ?object
    {
        if (! $game->relationLoaded('teamStats')) {
            return null;
        }

        return $game->teamStats->first(fn ($stat) => $stat->team_id === $this->opponentId($game));
    }

    /**
     * Calculate current streak for a given condition.
     *
     * @param  callable(object): bool  $condition
     */
    protected function calculateStreak(callable $condition): int
    {
        $streak = 0;

        foreach ($this->games->sortByDesc('game_date') as $game) {
            if ($condition($game)) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Count games matching a condition.
     *
     * @param  callable(object): bool  $condition
     */
    protected function countWhere(callable $condition): int
    {
        return $this->games->filter($condition)->count();
    }

    /**
     * Get threshold config for this league's collector.
     */
    protected function threshold(string $key): mixed
    {
        return $this->config($key);
    }

    /**
     * Get the win percentage threshold for showing trends.
     */
    protected function winPercentageThreshold(): float
    {
        return $this->config('win_percentage', 0.6);
    }

    /**
     * Check if trend is significant enough to display (meets win percentage threshold).
     */
    protected function isSignificant(int $count, ?int $total = null): bool
    {
        $total = $total ?? $this->games->count();

        return $total > 0 && ($count / $total) >= $this->winPercentageThreshold();
    }

    /**
     * Get the scoring unit term for this sport (runs for MLB, points for others).
     */
    protected function scoringUnit(): string
    {
        return $this->league === 'mlb' ? 'runs' : 'points';
    }

    /**
     * Get the period name for this sport (inning for MLB, quarter for basketball/football, half for college).
     */
    protected function periodName(): string
    {
        return match ($this->league) {
            'mlb' => 'inning',
            'cbb', 'wcbb' => 'half',
            default => 'quarter',
        };
    }

    /**
     * Get the first period label for this sport (1st inning for MLB, Q1 for basketball/football, 1H for college).
     */
    protected function firstPeriodLabel(): string
    {
        return match ($this->league) {
            'mlb' => 'the 1st inning',
            'cbb', 'wcbb' => '1H',
            default => 'Q1',
        };
    }

    /**
     * Check if this is a baseball league.
     */
    protected function isBaseball(): bool
    {
        return $this->league === 'mlb';
    }

    /**
     * Get close game margin threshold for this sport.
     */
    protected function closeGameMargin(): int
    {
        return match ($this->league) {
            'mlb' => 2,      // Baseball: 2 runs or less is close
            'nfl', 'cfb' => 7,   // Football: one score
            default => 7,     // Basketball: default
        };
    }
}
