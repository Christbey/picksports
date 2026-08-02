<?php

namespace App\Support\MLB;

use App\Models\MLB\Game;
use Illuminate\Support\Collection;

final class MlbGameScoreResolver
{
    /**
     * @return array{home:?int,away:?int,complete:bool,source:string}
     */
    public function resolve(Game $game): array
    {
        $home = $this->nullableInt($game->home_score);
        $away = $this->nullableInt($game->away_score);
        $gameScoreCount = (int) ($home !== null) + (int) ($away !== null);
        $zeroPlaceholder = $home === 0 && $away === 0;

        if ($home === null || $away === null || $zeroPlaceholder) {
            $stats = $this->teamStats($game);
            $homeStat = $this->statForSide($stats, (int) $game->home_team_id, 'home');
            $awayStat = $this->statForSide($stats, (int) $game->away_team_id, 'away');
            $homeStatRuns = $this->nullableInt($homeStat?->runs);
            $awayStatRuns = $this->nullableInt($awayStat?->runs);

            if ($zeroPlaceholder && $homeStatRuns !== null && $awayStatRuns !== null && ($homeStatRuns > 0 || $awayStatRuns > 0)) {
                $home = $homeStatRuns;
                $away = $awayStatRuns;
                $gameScoreCount = 0;
            }

            if ($home === null && $homeStatRuns !== null) {
                $home = $homeStatRuns;
            }

            if ($away === null && $awayStatRuns !== null) {
                $away = $awayStatRuns;
            }
        }

        $source = match ($gameScoreCount) {
            2 => 'mlb_games',
            1 => 'mixed',
            default => 'mlb_team_stats',
        };

        if ($home === null || $away === null) {
            $source = 'unresolved';
        }

        return [
            'home' => $home,
            'away' => $away,
            'complete' => $home !== null && $away !== null,
            'source' => $source,
        ];
    }

    /**
     * @return array{team:?int,opponent:?int,complete:bool,source:string}
     */
    public function forTeam(Game $game, int $teamId): array
    {
        $scores = $this->resolve($game);
        $isHome = (int) $game->home_team_id === $teamId;

        return [
            'team' => $isHome ? $scores['home'] : $scores['away'],
            'opponent' => $isHome ? $scores['away'] : $scores['home'],
            'complete' => $scores['complete'],
            'source' => $scores['source'],
        ];
    }

    /**
     * @return Collection<int, mixed>
     */
    private function teamStats(Game $game): Collection
    {
        if (! $game->relationLoaded('teamStats')) {
            $game->loadMissing('teamStats');
        }

        return $game->teamStats;
    }

    private function statForSide(Collection $stats, int $teamId, string $side): mixed
    {
        return $stats->firstWhere('team_id', $teamId)
            ?? $stats->first(fn ($stat): bool => ($stat->team_id === null || (int) $stat->team_id === $teamId)
                && strtolower((string) $stat->team_type) === $side
            );
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
