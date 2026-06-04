<?php

namespace App\Services\Api\V2;

use App\Models\Sports\FuturesOdd;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SportFuturesOddQuery
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    private const TEAM_FOREIGN_KEY_BY_SPORT = [
        'nba' => 'nba_team_id',
        'mlb' => 'mlb_team_id',
        'nfl' => 'nfl_team_id',
        'cbb' => 'cbb_team_id',
        'wcbb' => 'wcbb_team_id',
    ];

    private const PLAYER_FOREIGN_KEY_BY_SPORT = [
        'nfl' => 'nfl_player_id',
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(string $sport, array $filters = []): LengthAwarePaginator
    {
        return $this->query($sport, $filters)
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FuturesOdd>
     */
    public function query(string $sport, array $filters = []): Builder
    {
        $query = FuturesOdd::query()
            ->with($this->relationsFor($sport))
            ->where('sport', $sport)
            ->when($filters['season'] ?? null, fn (Builder $query, int $season): Builder => $query->where('season', $season))
            ->when($filters['market_key'] ?? null, fn (Builder $query, string $marketKey): Builder => $query->where('market_key', $marketKey))
            ->when($filters['bookmaker'] ?? null, fn (Builder $query, string $bookmaker): Builder => $query->where('bookmaker', $bookmaker))
            ->when($filters['event_id'] ?? null, fn (Builder $query, string $eventId): Builder => $query->where('event_id', $eventId))
            ->when($filters['outcome_name'] ?? null, fn (Builder $query, string $outcome): Builder => $query->where('outcome_name', $outcome));

        if (($filters['team_id'] ?? null) && ($teamKey = self::TEAM_FOREIGN_KEY_BY_SPORT[$sport] ?? null)) {
            $query->where($teamKey, $filters['team_id']);
        }

        if (($filters['player_id'] ?? null) && ($playerKey = self::PLAYER_FOREIGN_KEY_BY_SPORT[$sport] ?? null)) {
            $query->where($playerKey, $filters['player_id']);
        }

        return $query->orderByDesc('fetched_at')->orderByDesc('id');
    }

    /**
     * @return array<int, string>
     */
    private function relationsFor(string $sport): array
    {
        return match ($sport) {
            'nba' => ['nbaTeam'],
            'mlb' => ['mlbTeam'],
            'nfl' => ['nflTeam', 'nflPlayer'],
            'cbb' => ['cbbTeam'],
            'wcbb' => ['wcbbTeam'],
            default => [],
        };
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters): int
    {
        return max(1, min((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE));
    }
}
