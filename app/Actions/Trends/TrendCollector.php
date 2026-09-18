<?php

namespace App\Actions\Trends;

use App\Models\MLB\Game as MlbGame;
use App\Support\MLB\MlbGameScoreResolver;
use Illuminate\Support\Collection;

abstract class TrendCollector
{
    protected string $league;

    protected Collection $games;

    protected int $teamId;

    protected string $teamAbbr;

    protected object $team;

    /** @var array<int|string, array{home:?int,away:?int,complete:bool,source:string}> */
    protected array $resolvedScoreCache = [];

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
        $this->resolvedScoreCache = [];
        $this->games = $games
            ->filter(fn (object $game): bool => $this->hasCompleteScore($game))
            ->values();

        if ($league === 'nfl' && in_array($this->key(), ['quarters', 'halves', 'first_score', 'scoring_patterns'], true)) {
            $this->games = $this->games->filter(fn ($game) => count($this->teamLinescores($game)) >= 4
                && count($this->opponentLinescores($game)) >= 4)->values();
        }

        return $this;
    }

    public function hasGames(): bool
    {
        return $this->games->isNotEmpty();
    }

    protected function isHome(object $game): bool
    {
        return $game->home_team_id === $this->teamId;
    }

    protected function teamScore(object $game): ?int
    {
        $scores = $this->resolvedScores($game);

        return $this->isHome($game) ? $scores['home'] : $scores['away'];
    }

    protected function opponentScore(object $game): ?int
    {
        $scores = $this->resolvedScores($game);

        return $this->isHome($game) ? $scores['away'] : $scores['home'];
    }

    protected function opponentId(object $game): int
    {
        return $this->isHome($game) ? $game->away_team_id : $game->home_team_id;
    }

    protected function won(object $game): bool
    {
        return $this->teamScore($game) > $this->opponentScore($game);
    }

    protected function teamRecord(Collection $games): string
    {
        $wins = $games->filter(fn ($game) => $this->won($game))->count();
        $ties = $games->filter(fn ($game) => $this->margin($game) === 0)->count();

        return $this->formatRecord($wins, $games->count(), $ties);
    }

    protected function modelTeamMargin(object $game): ?float
    {
        if (! $game->relationLoaded('prediction') || ! is_numeric($game->prediction?->predicted_spread)) {
            return null;
        }
        $spread = (float) $game->prediction->predicted_spread;
        // NFL stores home-minus-away projected points, not a book handicap.
        $homeMargin = $this->league === 'nfl' ? $spread : -$spread;

        return $this->isHome($game) ? $homeMargin : -$homeMargin;
    }

    protected function margin(object $game): int
    {
        return $this->teamScore($game) - $this->opponentScore($game);
    }

    protected function totalPoints(object $game): int
    {
        $scores = $this->resolvedScores($game);

        return (int) $scores['home'] + (int) $scores['away'];
    }

    protected function hasCompleteScore(object $game): bool
    {
        return $this->resolvedScores($game)['complete'];
    }

    /**
     * @return array{home:?int,away:?int,complete:bool,source:string}
     */
    protected function resolvedScores(object $game): array
    {
        $key = $game->id ?? spl_object_id($game);

        if (isset($this->resolvedScoreCache[$key])) {
            return $this->resolvedScoreCache[$key];
        }

        if ($this->league === 'mlb' && $game instanceof MlbGame) {
            return $this->resolvedScoreCache[$key] = app(MlbGameScoreResolver::class)->resolve($game);
        }

        $home = is_numeric($game->home_score ?? null) ? (int) $game->home_score : null;
        $away = is_numeric($game->away_score ?? null) ? (int) $game->away_score : null;

        return $this->resolvedScoreCache[$key] = [
            'home' => $home,
            'away' => $away,
            'complete' => $home !== null && $away !== null,
            'source' => 'game',
        ];
    }

    protected function formatRecord(int $wins, int $total, int $ties = 0): string
    {
        $losses = $total - $wins - $ties;
        $pct = $total > 0 ? round(($wins / $total) * 100) : 0;

        $record = $ties > 0 ? "{$wins}-{$losses}-{$ties}" : "{$wins}-{$losses}";

        return "{$record} ({$pct}%)";
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

        if ($this->league === 'nfl') {
            if (! is_array($data)) {
                return [];
            }
            $result = [];
            foreach ($data as $index => $period) {
                if (is_array($period)) {
                    $index = isset($period['period']) ? (int) $period['period'] - 1 : $index;
                    $value = $period['value'] ?? $period['displayValue'] ?? null;
                } else {
                    $value = $period;
                    if (is_string($index) && preg_match('/^Q([1-4])$/', $index, $match)) {
                        $index = (int) $match[1] - 1;
                    }
                }
                if (is_numeric($index) && is_numeric($value) && (float) $value >= 0) {
                    $result[(int) $index] = (int) $value;
                }
            }
            // Never infer unreported quarters or renumber a sparse ESPN array.
            foreach ([0, 1, 2, 3] as $quarter) {
                if (! array_key_exists($quarter, $result)) {
                    return [];
                }
            }
            ksort($result);

            return $result;
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

        // Check if it's an array of period objects (ESPN shapes like value/displayValue)
        if (isset($data[0]) && is_array($data[0])) {
            $result = [];
            foreach ($data as $index => $period) {
                if (! array_key_exists('value', $period) && ! array_key_exists('displayValue', $period)) {
                    continue;
                }

                $rawValue = $period['value'] ?? $period['displayValue'] ?? 0;
                $periodIndex = isset($period['period']) && is_numeric($period['period'])
                    ? max(0, ((int) $period['period']) - 1)
                    : $index;

                $result[$periodIndex] = (int) $rawValue;
            }
            if (! empty($result)) {
                ksort($result);

                return array_values($result);
            }
        }

        // Already a simple indexed array
        return array_map(
            static fn ($value) => is_scalar($value) ? (int) $value : 0,
            $data
        );
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

    protected function isBasketball(): bool
    {
        return in_array($this->league, ['nba', 'wnba', 'cbb', 'wcbb'], true);
    }

    protected function isCollegeBasketball(): bool
    {
        return in_array($this->league, ['cbb', 'wcbb'], true);
    }

    protected function isFootball(): bool
    {
        return in_array($this->league, ['nfl', 'cfb'], true);
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
