<?php

namespace App\Services\WNBA;

use App\Models\WNBA\Game;
use App\Models\WNBA\TeamStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class WnbaPredictionSignalService
{
    /**
     * @return array<string, mixed>
     */
    public function forGame(Game $game): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);

        $homeTeamId = (int) $game->home_team_id;
        $awayTeamId = (int) $game->away_team_id;
        $homeLine = $this->homeSpreadLine((array) $game->odds_data, $game->homeTeam);

        $home = $this->teamContext($game, $homeTeamId, 'home');
        $away = $this->teamContext($game, $awayTeamId, 'away');

        return [
            'home' => $home,
            'away' => $away,
            'differentials' => [
                'rest_days' => $this->nullableDiff($home['rest_days'], $away['rest_days']),
                'last5_net_rating' => $this->nullableDiff(
                    data_get($home, 'rolling_four_factors.last5.net_rating'),
                    data_get($away, 'rolling_four_factors.last5.net_rating')
                ),
                'last5_efg_pct' => $this->nullableDiff(
                    data_get($home, 'rolling_four_factors.last5.efg_pct'),
                    data_get($away, 'rolling_four_factors.last5.efg_pct')
                ),
                'last5_turnover_rate' => $this->nullableDiff(
                    data_get($home, 'rolling_four_factors.last5.turnover_rate'),
                    data_get($away, 'rolling_four_factors.last5.turnover_rate')
                ),
                'last10_ats_pct' => $this->nullableDiff(
                    data_get($home, 'ats.last10.pct'),
                    data_get($away, 'ats.last10.pct')
                ),
            ],
            'market' => [
                'home_spread_line' => $homeLine,
                'home_is_favorite' => $homeLine !== null ? $homeLine < 0 : null,
                'away_is_favorite' => $homeLine !== null ? $homeLine > 0 : null,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function teamAtsReport(int $season): array
    {
        $stats = [];

        $games = Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->orderBy('game_date')
            ->get();

        foreach ($games as $game) {
            $homeLine = $this->homeSpreadLine((array) $game->odds_data, $game->homeTeam);
            if ($homeLine === null) {
                foreach ([$game->homeTeam, $game->awayTeam] as $team) {
                    if (! $team) {
                        continue;
                    }

                    $name = $this->teamName($team);
                    $stats[$name] ??= $this->emptyAtsRecord($name);
                    $stats[$name]['missing_line_games']++;
                }

                continue;
            }

            $homeCoverMargin = ((float) $game->home_score - (float) $game->away_score) + $homeLine;

            foreach ([[$game->homeTeam, 'home', $homeCoverMargin], [$game->awayTeam, 'away', -$homeCoverMargin]] as [$team, $venue, $coverMargin]) {
                if (! $team) {
                    continue;
                }

                $name = $this->teamName($team);
                $stats[$name] ??= $this->emptyAtsRecord($name);
                $this->addAtsResult($stats[$name], (string) $venue, (float) $coverMargin);
            }
        }

        $rows = array_map(fn (array $record): array => $this->formatTeamAtsRecord($record), array_values($stats));

        usort($rows, fn (array $left, array $right): int => [
            $right['ats_pct_ex_pushes'] ?? -1,
            $right['games_with_line'],
            $left['team'],
        ] <=> [
            $left['ats_pct_ex_pushes'] ?? -1,
            $left['games_with_line'],
            $right['team'],
        ]);

        return $rows;
    }

    public function missingSpreadLineCount(int $season): int
    {
        return Game::query()
            ->with('homeTeam')
            ->where('season', $season)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get()
            ->filter(fn (Game $game): bool => $this->homeSpreadLine((array) $game->odds_data, $game->homeTeam) === null)
            ->count();
    }

    public function homeSpreadLine(array $oddsData, mixed $homeTeam): ?float
    {
        $markets = [];
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) === 'spreads') {
                    $markets[] = [
                        'bookmaker' => $bookmaker['key'] ?? $bookmaker['title'] ?? null,
                        'market' => $market,
                    ];
                }
            }
        }

        $preferred = ['pinnacle', 'draftkings', 'fanduel', 'betmgm', 'caesars', 'espnbet', 'betrivers', 'betonlineag'];
        usort($markets, function (array $left, array $right) use ($preferred): int {
            $leftIndex = array_search($left['bookmaker'], $preferred, true);
            $rightIndex = array_search($right['bookmaker'], $preferred, true);

            return ($leftIndex === false ? 999 : $leftIndex) <=> ($rightIndex === false ? 999 : $rightIndex);
        });

        foreach ($markets as $wrapped) {
            foreach (($wrapped['market']['outcomes'] ?? []) as $outcome) {
                if (! is_array($outcome) || ! isset($outcome['point'])) {
                    continue;
                }

                if ($this->teamMatchesOutcome($homeTeam, (string) ($outcome['name'] ?? ''))) {
                    return (float) $outcome['point'];
                }
            }
        }

        return null;
    }

    private function teamContext(Game $game, int $teamId, string $venue): array
    {
        $rest = $this->restFatigue($game, $teamId);
        $last5 = $this->rollingFourFactors($game, $teamId, 5);
        $last10 = $this->rollingFourFactors($game, $teamId, 10);

        return [
            'rest_days' => $rest['days'],
            'back_to_back' => $rest['back_to_back'],
            'games_last_5_days' => $rest['prior_games_last_5_days'],
            'games_last_5_days_including_today' => $rest['games_last_5_days_including_today'],
            'three_in_five' => $rest['three_in_five'],
            'rest' => $rest,
            'road_trip_game_number' => $venue === 'away' ? $this->roadTripGameNumber($game, $teamId) : null,
            'ats' => [
                'season' => $this->teamAtsBeforeGame($game, $teamId),
                'last5' => $this->teamAtsBeforeGame($game, $teamId, 5),
                'last10' => $this->teamAtsBeforeGame($game, $teamId, 10),
            ],
            'rolling_four_factors' => [
                'last5' => $last5,
                'last10' => $last10,
            ],
            'rolling' => [
                'last5' => $last5,
                'last10' => $last10,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function restFatigue(Game $game, int $teamId): array
    {
        $previous = $this->previousFinalGames($game, $teamId)
            ->first();
        $currentStart = $this->gameStart($game);
        $previousStart = $previous instanceof Game ? $this->gameStart($previous) : null;
        $restDays = $previousStart instanceof CarbonImmutable
            ? (int) max(0, $previousStart->startOfDay()->diffInDays($currentStart->startOfDay()) - 1)
            : null;
        $priorGamesLastFiveDays = $this->previousFinalGames($game, $teamId)
            ->filter(fn (Game $candidate): bool => $this->gameStart($candidate)->greaterThanOrEqualTo($currentStart->subDays(4)))
            ->count();
        $gamesLastFiveDaysIncludingToday = $priorGamesLastFiveDays + 1;

        return [
            'days' => $restDays,
            'last_game_id' => $previous?->id,
            'last_game_at' => $previousStart?->toDateTimeString(),
            'back_to_back' => $restDays === 0,
            'prior_games_last_5_days' => $priorGamesLastFiveDays,
            'games_last_5_days_including_today' => $gamesLastFiveDaysIncludingToday,
            'three_in_five' => $gamesLastFiveDaysIncludingToday >= 3,
        ];
    }

    private function roadTripGameNumber(Game $game, int $teamId): int
    {
        $count = 1;

        foreach ($this->previousFinalGames($game, $teamId) as $previous) {
            if ((int) $previous->away_team_id !== $teamId) {
                break;
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return Collection<int, Game>
     */
    private function previousFinalGames(Game $game, int $teamId): Collection
    {
        $currentStart = $this->gameStart($game);

        return Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('season', $game->season)
            ->where('status', 'STATUS_FINAL')
            ->when($game->season_type !== null, fn ($query) => $query->where('season_type', $game->season_type))
            ->where('game_date', '<=', $currentStart->toDateString())
            ->where(fn ($query) => $query
                ->where('home_team_id', $teamId)
                ->orWhere('away_team_id', $teamId))
            ->orderByDesc('game_date')
            ->orderByDesc('game_time')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Game $candidate): bool => $candidate->id !== $game->id && $this->gameStart($candidate)->lessThan($currentStart))
            ->values();
    }

    private function teamAtsBeforeGame(Game $game, int $teamId, ?int $limit = null): array
    {
        $record = $this->emptyAtsRecord();

        $games = $this->previousFinalGames($game, $teamId);
        if ($limit !== null) {
            $games = $games->take($limit);
        }

        foreach ($games as $previous) {
            $homeLine = $this->homeSpreadLine((array) $previous->odds_data, $previous->homeTeam);
            if ($homeLine === null) {
                continue;
            }

            $homeCoverMargin = ((float) $previous->home_score - (float) $previous->away_score) + $homeLine;
            $coverMargin = (int) $previous->home_team_id === $teamId ? $homeCoverMargin : -$homeCoverMargin;
            $venue = (int) $previous->home_team_id === $teamId ? 'home' : 'away';

            $this->addAtsResult($record, $venue, $coverMargin);
        }

        return $this->formatTeamAtsRecord($record, includeTeam: false);
    }

    public function rollingFourFactors(Game $game, int $teamId, int $limit): array
    {
        $gameIds = $this->previousFinalGames($game, $teamId)
            ->take($limit)
            ->pluck('id');

        if ($gameIds->isEmpty()) {
            return [
                'games' => 0,
                'sample_size' => 0,
                'efg_pct' => null,
                'turnover_rate' => null,
                'turnover_pct' => null,
                'off_rebound_pct' => null,
                'offensive_rebound_pct' => null,
                'free_throw_rate' => null,
                'foul_rate' => null,
                'pace' => null,
                'net_rating' => null,
            ];
        }

        $stats = TeamStat::query()
            ->select('wnba_team_stats.*')
            ->with(['game.teamStats'])
            ->join('wnba_games', 'wnba_team_stats.game_id', '=', 'wnba_games.id')
            ->where('wnba_team_stats.team_id', $teamId)
            ->whereIn('wnba_team_stats.game_id', $gameIds)
            ->orderByDesc('wnba_games.game_date')
            ->orderByDesc('wnba_games.game_time')
            ->orderByDesc('wnba_games.id')
            ->get();

        if ($stats->isEmpty()) {
            return [
                'games' => 0,
                'sample_size' => 0,
                'efg_pct' => null,
                'turnover_rate' => null,
                'turnover_pct' => null,
                'off_rebound_pct' => null,
                'offensive_rebound_pct' => null,
                'free_throw_rate' => null,
                'foul_rate' => null,
                'pace' => null,
                'net_rating' => null,
            ];
        }

        $fga = (float) $stats->sum('field_goals_attempted');
        $fgm = (float) $stats->sum('field_goals_made');
        $threeMade = (float) $stats->sum('three_point_made');
        $fta = (float) $stats->sum('free_throws_attempted');
        $turnovers = (float) $stats->sum('turnovers');
        $oreb = (float) $stats->sum('offensive_rebounds');
        $fouls = (float) $stats->sum('fouls');
        $points = (float) $stats->sum('points');
        $possessions = (float) $stats->sum('possessions');
        $oppDefRebounds = 0.0;
        $oppPoints = 0.0;
        $oppPossessions = 0.0;

        foreach ($stats as $stat) {
            $opponent = $stat->game?->teamStats
                ->first(fn (TeamStat $candidate): bool => (int) $candidate->team_id !== (int) $stat->team_id);

            $oppDefRebounds += (float) ($opponent?->defensive_rebounds ?? 0);
            $oppPoints += (float) ($opponent?->points ?? 0);
            $oppPossessions += (float) ($opponent?->possessions ?? 0);
        }

        $efgPct = $fga > 0 ? round((($fgm + (0.5 * $threeMade)) / $fga) * 100, 3) : null;
        $turnoverPct = $possessions > 0 ? round(($turnovers / $possessions) * 100, 3) : null;
        $offensiveReboundPct = ($oreb + $oppDefRebounds) > 0 ? round(($oreb / ($oreb + $oppDefRebounds)) * 100, 3) : null;

        return [
            'games' => $stats->count(),
            'sample_size' => $stats->count(),
            'efg_pct' => $efgPct,
            'turnover_rate' => $turnoverPct,
            'turnover_pct' => $turnoverPct,
            'off_rebound_pct' => $offensiveReboundPct,
            'offensive_rebound_pct' => $offensiveReboundPct,
            'free_throw_rate' => $fga > 0 ? round($fta / $fga, 3) : null,
            'foul_rate' => $possessions > 0 ? round(($fouls / $possessions) * 100, 3) : null,
            'pace' => $stats->count() > 0 ? round($possessions / $stats->count(), 3) : null,
            'net_rating' => $possessions > 0 && $oppPossessions > 0
                ? round((($points / $possessions) * 100) - (($oppPoints / $oppPossessions) * 100), 3)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAtsRecord(?string $team = null): array
    {
        return [
            'team' => $team,
            'wins' => 0,
            'losses' => 0,
            'pushes' => 0,
            'home_wins' => 0,
            'home_losses' => 0,
            'home_pushes' => 0,
            'away_wins' => 0,
            'away_losses' => 0,
            'away_pushes' => 0,
            'cover_margin_sum' => 0.0,
            'missing_line_games' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function addAtsResult(array &$record, string $venue, float $coverMargin): void
    {
        if ($coverMargin > 0) {
            $record['wins']++;
            $record["{$venue}_wins"]++;
        } elseif ($coverMargin < 0) {
            $record['losses']++;
            $record["{$venue}_losses"]++;
        } else {
            $record['pushes']++;
            $record["{$venue}_pushes"]++;
        }

        $record['cover_margin_sum'] += $coverMargin;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function formatTeamAtsRecord(array $record, bool $includeTeam = true): array
    {
        $decisions = (int) $record['wins'] + (int) $record['losses'];
        $games = $decisions + (int) $record['pushes'];
        $payload = [
            'ats' => "{$record['wins']}-{$record['losses']}-{$record['pushes']}",
            'wins' => (int) $record['wins'],
            'losses' => (int) $record['losses'],
            'pushes' => (int) $record['pushes'],
            'pct' => $decisions > 0 ? round(((int) $record['wins'] / $decisions) * 100, 1) : null,
            'ats_pct' => $decisions > 0 ? round(((int) $record['wins'] / $decisions) * 100, 1) : 0.0,
            'ats_pct_ex_pushes' => $decisions > 0 ? round(((int) $record['wins'] / $decisions) * 100, 1) : null,
            'home_ats' => "{$record['home_wins']}-{$record['home_losses']}-{$record['home_pushes']}",
            'away_ats' => "{$record['away_wins']}-{$record['away_losses']}-{$record['away_pushes']}",
            'avg_cover_margin' => $games > 0 ? round((float) $record['cover_margin_sum'] / $games, 2) : 0.0,
            'games_with_line' => $games,
            'missing_line_games' => (int) $record['missing_line_games'],
        ];

        if ($includeTeam) {
            return ['team' => $record['team'], ...$payload];
        }

        return $payload;
    }

    private function nullableDiff(mixed $left, mixed $right): ?float
    {
        if (! is_numeric($left) || ! is_numeric($right)) {
            return null;
        }

        return round((float) $left - (float) $right, 2);
    }

    private function gameStart(Game $game): CarbonImmutable
    {
        $date = $game->game_date instanceof \DateTimeInterface
            ? $game->game_date->format('Y-m-d')
            : (string) $game->game_date;
        $time = trim((string) ($game->game_time ?? '00:00:00')) ?: '00:00:00';

        return CarbonImmutable::parse("{$date} {$time}", 'UTC');
    }

    private function teamMatchesOutcome(mixed $team, string $outcomeName): bool
    {
        $haystack = $this->normalizeTeamName($outcomeName);

        foreach ($this->teamNameCandidates($team) as $candidate) {
            $normalized = $this->normalizeTeamName($candidate);

            if ($normalized !== '' && ($haystack === $normalized || str_contains($haystack, $normalized) || str_contains($normalized, $haystack))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function teamNameCandidates(mixed $team): array
    {
        if (! $team) {
            return [];
        }

        return array_values(array_filter([
            $team->display_name ?? null,
            $this->teamName($team),
            $team->location ?? $team->school ?? null,
            $team->name ?? $team->mascot ?? null,
            $team->short_display_name ?? null,
            $team->abbreviation ?? null,
        ], fn (mixed $value): bool => trim((string) $value) !== ''));
    }

    private function normalizeTeamName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['.', '-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return str_replace([
            'la sparks',
            'ny liberty',
            'lv aces',
        ], [
            'los angeles sparks',
            'new york liberty',
            'las vegas aces',
        ], $normalized);
    }

    public function teamName(mixed $team): string
    {
        $location = trim((string) ($team->location ?? $team->school ?? ''));
        $name = trim((string) ($team->name ?? $team->mascot ?? ''));
        $fullName = trim("{$location} {$name}");

        return $fullName !== '' ? $fullName : (string) ($team->abbreviation ?? 'UNK');
    }
}
