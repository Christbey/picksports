<?php

namespace App\Services\MLB;

use App\Models\MarketQuote;
use App\Models\MLB\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Support\MLB\MlbGameStart;
use App\Support\MLB\MlbLineScores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MlbPeriodFeatureBuilder
{
    /**
     * @var array<string, int>
     */
    public const MARKETS = [
        'first_3_moneyline' => 3,
        'first_5_moneyline' => 5,
    ];

    /**
     * @param  list<int>  $seasons
     * @return Collection<int, array<string, mixed>>
     */
    public function historicalRows(array $seasons): Collection
    {
        $games = Game::query()
            ->whereIn('season', $seasons)
            ->where('season_type', config('mlb.season.types.regular', 2))
            ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
            ->whereNotNull('home_linescores')
            ->whereNotNull('away_linescores')
            ->orderBy('season')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id')
            ->get();

        return $this->buildRows($games, true);
    }

    /**
     * @return array<string, array<string, float|int|null>>
     */
    public function liveFeatures(Game $game, ?PredictionFeatureSnapshot $snapshot = null): array
    {
        $gameStart = MlbGameStart::for($game);
        $history = Game::query()
            ->where('season', '<=', $game->season)
            ->where('season', '>=', max(2000, (int) $game->season - 6))
            ->where('season_type', config('mlb.season.types.regular', 2))
            ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
            ->whereNotNull('home_linescores')
            ->whereNotNull('away_linescores')
            ->when($gameStart, function ($query) use ($gameStart) {
                $query->where(function ($dateQuery) use ($gameStart) {
                    $dateQuery
                        ->whereDate('game_date', '<', $gameStart->toDateString())
                        ->orWhere(function ($sameDateQuery) use ($gameStart) {
                            $sameDateQuery
                                ->whereDate('game_date', $gameStart->toDateString())
                                ->whereTime('game_time', '<', $gameStart->format('H:i:s'));
                        });
                });
            })
            ->orderBy('season')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id')
            ->get();

        $states = $this->replay($history);
        $trustedPitchers = $this->trustedPitcherFeatures($snapshot);
        $features = [];

        foreach (self::MARKETS as $marketType => $innings) {
            $features[$marketType] = $this->features(
                $game,
                $marketType,
                $innings,
                $states[$marketType] ?? [],
                $trustedPitchers,
            );
        }

        return $features;
    }

    /**
     * Build point-in-time period features for a group of games with one history replay.
     *
     * @param  Collection<int, Game>  $games
     * @return array<int, array<string, array<string, float|int|null>>>
     */
    public function liveFeaturesForGames(Collection $games): array
    {
        $targets = $games
            ->filter(fn (Game $game): bool => is_numeric($game->id) && is_numeric($game->season))
            ->keyBy(fn (Game $game): int => (int) $game->id);
        if ($targets->isEmpty()) {
            return [];
        }

        $targetIds = $targets->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $minimumSeason = max(2000, (int) $targets->min('season') - 6);
        $maximumSeason = (int) $targets->max('season');
        $finalStatus = config('mlb.statuses.final', 'STATUS_FINAL');
        $regularSeason = config('mlb.season.types.regular', 2);
        $timeline = Game::query()
            ->whereBetween('season', [$minimumSeason, $maximumSeason])
            ->where(function ($query) use ($finalStatus, $regularSeason, $targetIds): void {
                $query->where(function ($history) use ($finalStatus, $regularSeason): void {
                    $history
                        ->where('season_type', $regularSeason)
                        ->where('status', $finalStatus)
                        ->whereNotNull('home_linescores')
                        ->whereNotNull('away_linescores');
                })->orWhereIn('id', $targetIds);
            })
            ->orderBy('season')
            ->orderBy('game_date')
            ->orderBy('game_time')
            ->orderBy('id')
            ->get();

        $trustedPitchers = $this->trustedPitcherFeaturesByGame($targetIds);
        $states = [];
        $featuresByGame = [];
        $season = null;

        foreach ($timeline as $game) {
            if ($season !== (int) $game->season) {
                $this->startSeason($states);
                $season = (int) $game->season;
            }

            $gameId = (int) $game->id;
            if (isset($targets[$gameId])) {
                foreach (self::MARKETS as $marketType => $innings) {
                    $featuresByGame[$gameId][$marketType] = $this->features(
                        $game,
                        $marketType,
                        $innings,
                        $states[$marketType] ?? [],
                        $trustedPitchers[$gameId] ?? [],
                    );
                }
            }

            if ($game->status !== $finalStatus || (int) $game->season_type !== (int) $regularSeason) {
                continue;
            }

            $homeScores = MlbLineScores::normalize($game->home_linescores);
            $awayScores = MlbLineScores::normalize($game->away_linescores);
            foreach (self::MARKETS as $marketType => $innings) {
                $homeRuns = $this->periodRuns($homeScores, $innings);
                $awayRuns = $this->periodRuns($awayScores, $innings);
                if ($homeRuns !== null && $awayRuns !== null) {
                    $this->updateState($states[$marketType], $game, $homeRuns, $awayRuns);
                }
            }
        }

        return $featuresByGame;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(Collection $games, bool $targets): Collection
    {
        $trustedPitchers = $this->trustedPitcherFeaturesByGame($games->pluck('id')->all());
        $marketQuotes = $this->marketQuotesByGame($games->pluck('id')->all());
        $states = [];
        $rows = collect();
        $season = null;

        foreach ($games as $game) {
            if ($season !== (int) $game->season) {
                $this->startSeason($states);
                $season = (int) $game->season;
            }

            $homeScores = MlbLineScores::normalize($game->home_linescores);
            $awayScores = MlbLineScores::normalize($game->away_linescores);

            foreach (self::MARKETS as $marketType => $innings) {
                $homeRuns = $this->periodRuns($homeScores, $innings);
                $awayRuns = $this->periodRuns($awayScores, $innings);
                if ($homeRuns === null || $awayRuns === null) {
                    continue;
                }

                $features = $this->features(
                    $game,
                    $marketType,
                    $innings,
                    $states[$marketType] ?? [],
                    $trustedPitchers[(int) $game->id] ?? [],
                );
                $row = [
                    'game_id' => (int) $game->id,
                    'season' => (int) $game->season,
                    'market_type' => $marketType,
                    'game_start_at' => MlbGameStart::for($game)?->toIso8601String(),
                    'home_team_id' => (int) $game->home_team_id,
                    'away_team_id' => (int) $game->away_team_id,
                    'pregame_safe' => true,
                    'availability_status' => 'verified_reconstruction',
                    ...$features,
                    ...$this->marketQuoteColumns(
                        $marketQuotes[(int) $game->id][$marketType] ?? [],
                    ),
                ];

                if ($targets) {
                    $margin = $homeRuns - $awayRuns;
                    $row = [
                        ...$row,
                        'target_class' => $margin > 0 ? 2 : ($margin < 0 ? 0 : 1),
                        'target_home_win' => $margin > 0 ? 1 : 0,
                        'target_away_win' => $margin < 0 ? 1 : 0,
                        'target_tie' => $margin === 0.0 ? 1 : 0,
                        'target_home_margin' => $margin,
                        'target_total_runs' => $homeRuns + $awayRuns,
                    ];
                }

                $rows->push($row);
                $this->updateState(
                    $states[$marketType],
                    $game,
                    $homeRuns,
                    $awayRuns,
                );
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function replay(Collection $games): array
    {
        $states = [];
        $season = null;

        foreach ($games as $game) {
            if ($season !== (int) $game->season) {
                $this->startSeason($states);
                $season = (int) $game->season;
            }
            $homeScores = MlbLineScores::normalize($game->home_linescores);
            $awayScores = MlbLineScores::normalize($game->away_linescores);

            foreach (self::MARKETS as $marketType => $innings) {
                $homeRuns = $this->periodRuns($homeScores, $innings);
                $awayRuns = $this->periodRuns($awayScores, $innings);
                if ($homeRuns !== null && $awayRuns !== null) {
                    $this->updateState($states[$marketType], $game, $homeRuns, $awayRuns);
                }
            }
        }

        return $states;
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     * @param  array<string, float|int|null>  $pitchers
     * @return array<string, float|int|null>
     */
    private function features(
        Game $game,
        string $marketType,
        int $innings,
        array $state,
        array $pitchers,
    ): array {
        $home = $state[(int) $game->home_team_id] ?? $this->emptyTeam();
        $away = $state[(int) $game->away_team_id] ?? $this->emptyTeam();
        $homeVenue = $home['home'];
        $awayVenue = $away['away'];
        $homeElo = (float) $home['elo'];
        $awayElo = (float) $away['elo'];

        return [
            'feature_period_innings' => $innings,
            'feature_home_period_elo' => round($homeElo, 4),
            'feature_away_period_elo' => round($awayElo, 4),
            'feature_period_elo_diff' => round($homeElo - $awayElo, 4),
            'feature_elo_home_win_probability' => round($this->eloProbability($homeElo, $awayElo), 6),
            ...$this->teamFeatures('home', $home),
            ...$this->teamFeatures('away', $away),
            'feature_home_venue_games' => (int) $homeVenue['games'],
            'feature_home_venue_win_pct' => $this->winPct($homeVenue),
            'feature_home_venue_tie_rate' => $this->rate($homeVenue['ties'], $homeVenue['games']),
            'feature_away_venue_games' => (int) $awayVenue['games'],
            'feature_away_venue_win_pct' => $this->winPct($awayVenue),
            'feature_away_venue_tie_rate' => $this->rate($awayVenue['ties'], $awayVenue['games']),
            'feature_home_rest_days' => $this->restDays($home['last_start'], $game),
            'feature_away_rest_days' => $this->restDays($away['last_start'], $game),
            'feature_home_pitcher_elo' => $pitchers['feature_home_pitcher_elo'] ?? null,
            'feature_away_pitcher_elo' => $pitchers['feature_away_pitcher_elo'] ?? null,
            'feature_pitcher_elo_diff' => isset(
                $pitchers['feature_home_pitcher_elo'],
                $pitchers['feature_away_pitcher_elo'],
            )
                ? round(
                    (float) $pitchers['feature_home_pitcher_elo']
                    - (float) $pitchers['feature_away_pitcher_elo'],
                    4,
                )
                : null,
            'feature_pitcher_context_known' => (int) (
                isset($pitchers['feature_home_pitcher_elo'])
                && isset($pitchers['feature_away_pitcher_elo'])
            ),
            'feature_market_type_f3' => (int) ($marketType === 'first_3_moneyline'),
            'feature_market_type_f5' => (int) ($marketType === 'first_5_moneyline'),
        ];
    }

    /**
     * @param  array<string, mixed>  $team
     * @return array<string, float|int|null>
     */
    private function teamFeatures(string $side, array $team): array
    {
        $games = (int) $team['games'];
        $rolling = collect($team['rolling']);

        return [
            "feature_{$side}_prior_games" => $games,
            "feature_{$side}_win_pct" => $this->winPct($team),
            "feature_{$side}_tie_rate" => $this->rate($team['ties'], $games),
            "feature_{$side}_runs_for_per_game" => $this->average($team['runs_for'], $games),
            "feature_{$side}_runs_against_per_game" => $this->average($team['runs_against'], $games),
            "feature_{$side}_run_diff_per_game" => $this->average(
                $team['runs_for'] - $team['runs_against'],
                $games,
            ),
            "feature_{$side}_rolling_10_games" => $rolling->count(),
            "feature_{$side}_rolling_10_win_pct" => $this->rollingWinPct($rolling),
            "feature_{$side}_rolling_10_tie_rate" => $rolling->isEmpty()
                ? null
                : round($rolling->where('result', 0.5)->count() / $rolling->count(), 6),
            "feature_{$side}_rolling_10_run_diff" => $rolling->isEmpty()
                ? null
                : round((float) $rolling->avg('margin'), 6),
        ];
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $states
     */
    private function startSeason(array &$states): void
    {
        foreach ($states as &$marketState) {
            foreach ($marketState as $teamId => $team) {
                $elo = 1500 + (((float) $team['elo'] - 1500) * 0.75);
                $marketState[$teamId] = [
                    ...$this->emptyTeam(),
                    'elo' => $elo,
                ];
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $state
     */
    private function updateState(
        ?array &$state,
        Game $game,
        float $homeRuns,
        float $awayRuns,
    ): void {
        $state ??= [];
        $homeId = (int) $game->home_team_id;
        $awayId = (int) $game->away_team_id;
        $state[$homeId] ??= $this->emptyTeam();
        $state[$awayId] ??= $this->emptyTeam();
        $homeElo = (float) $state[$homeId]['elo'];
        $awayElo = (float) $state[$awayId]['elo'];
        $homeResult = $homeRuns > $awayRuns ? 1.0 : ($homeRuns < $awayRuns ? 0.0 : 0.5);
        $awayResult = 1.0 - $homeResult;
        $expected = $this->eloProbability($homeElo, $awayElo);
        $eloDelta = 20.0 * ($homeResult - $expected);

        $this->recordTeam($state[$homeId], 'home', $homeResult, $homeRuns, $awayRuns, $game);
        $this->recordTeam($state[$awayId], 'away', $awayResult, $awayRuns, $homeRuns, $game);
        $state[$homeId]['elo'] = $homeElo + $eloDelta;
        $state[$awayId]['elo'] = $awayElo - $eloDelta;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private function recordTeam(
        array &$team,
        string $venue,
        float $result,
        float $runsFor,
        float $runsAgainst,
        Game $game,
    ): void {
        $team['games']++;
        $team['wins'] += (int) ($result === 1.0);
        $team['losses'] += (int) ($result === 0.0);
        $team['ties'] += (int) ($result === 0.5);
        $team['runs_for'] += $runsFor;
        $team['runs_against'] += $runsAgainst;
        $team[$venue]['games']++;
        $team[$venue]['wins'] += (int) ($result === 1.0);
        $team[$venue]['losses'] += (int) ($result === 0.0);
        $team[$venue]['ties'] += (int) ($result === 0.5);
        $team['rolling'][] = [
            'result' => $result,
            'margin' => $runsFor - $runsAgainst,
        ];
        $team['rolling'] = array_slice($team['rolling'], -10);
        $team['last_start'] = MlbGameStart::for($game)?->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTeam(): array
    {
        return [
            'elo' => 1500.0,
            'games' => 0,
            'wins' => 0,
            'losses' => 0,
            'ties' => 0,
            'runs_for' => 0.0,
            'runs_against' => 0.0,
            'rolling' => [],
            'home' => ['games' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0],
            'away' => ['games' => 0, 'wins' => 0, 'losses' => 0, 'ties' => 0],
            'last_start' => null,
        ];
    }

    /**
     * @param  list<int>  $gameIds
     * @return array<int, array<string, float>>
     */
    private function trustedPitcherFeaturesByGame(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        return PredictionFeatureSnapshot::query()
            ->where('sport', 'mlb')
            ->whereIn('game_id', $gameIds)
            ->where('pregame_safe', true)
            ->whereIn('availability_status', ['observed_pregame', 'verified_reconstruction'])
            ->whereNotNull('features_available_at')
            ->whereColumn('features_available_at', '<=', 'game_start_at')
            ->orderBy('features_available_at')
            ->get()
            ->groupBy('game_id')
            ->map(fn (Collection $rows): array => $this->trustedPitcherFeatures($rows->last()))
            ->filter()
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function trustedPitcherFeatures(?PredictionFeatureSnapshot $snapshot): array
    {
        if (! $snapshot
            || ! $snapshot->pregame_safe
            || ! in_array($snapshot->availability_status, ['observed_pregame', 'verified_reconstruction'], true)
            || ! $snapshot->game_start_at
            || ($snapshot->features_available_at && $snapshot->features_available_at->gt($snapshot->game_start_at))) {
            return [];
        }

        $features = (array) $snapshot->features;
        $home = $features['home_pitcher_elo'] ?? $features['feature_home_pitcher_elo'] ?? null;
        $away = $features['away_pitcher_elo'] ?? $features['feature_away_pitcher_elo'] ?? null;

        return array_filter([
            'feature_home_pitcher_elo' => is_numeric($home) ? (float) $home : null,
            'feature_away_pitcher_elo' => is_numeric($away) ? (float) $away : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  list<int>  $gameIds
     * @return array<int, array<string, array<string, MarketQuote>>>
     */
    private function marketQuotesByGame(array $gameIds): array
    {
        if ($gameIds === []) {
            return [];
        }

        $marketTypes = [
            'h2h_1st_3_innings' => 'first_3_moneyline',
            'h2h_1st_5_innings' => 'first_5_moneyline',
        ];
        $result = [];

        foreach (MarketQuote::query()
            ->where('sport', 'mlb')
            ->whereIn('game_id', $gameIds)
            ->whereIn('market_key', array_keys($marketTypes))
            ->where('is_pregame', true)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get() as $quote) {
            if (! in_array($quote->side, ['home', 'away'], true)) {
                continue;
            }
            $marketType = $marketTypes[$quote->market_key] ?? null;
            if ($marketType !== null) {
                $result[(int) $quote->game_id][$marketType][$quote->side] = $quote;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, MarketQuote>  $quotes
     * @return array<string, float|int|string|null>
     */
    private function marketQuoteColumns(array $quotes): array
    {
        $home = $quotes['home'] ?? null;
        $away = $quotes['away'] ?? null;

        return [
            'market_home_price' => $home?->price,
            'market_away_price' => $away?->price,
            'market_home_no_vig_probability' => is_numeric($home?->no_vig_probability)
                ? (float) $home->no_vig_probability
                : null,
            'market_away_no_vig_probability' => is_numeric($away?->no_vig_probability)
                ? (float) $away->no_vig_probability
                : null,
            'market_bookmaker' => $home?->bookmaker_key ?? $away?->bookmaker_key,
            'market_captured_at' => $home?->captured_at?->toIso8601String()
                ?? $away?->captured_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<mixed>  $scores
     */
    private function periodRuns(array $scores, int $innings): ?float
    {
        if (count($scores) < $innings) {
            return null;
        }
        $period = array_slice($scores, 0, $innings);
        if (collect($period)->contains(fn (mixed $score): bool => ! is_numeric($score))) {
            return null;
        }

        return (float) array_sum(array_map('floatval', $period));
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function winPct(array $stats): ?float
    {
        $decisions = (int) $stats['wins'] + (int) $stats['losses'];

        return $decisions > 0 ? round((int) $stats['wins'] / $decisions, 6) : null;
    }

    private function rollingWinPct(Collection $rows): ?float
    {
        $decisions = $rows->whereIn('result', [0.0, 1.0]);

        return $decisions->isEmpty()
            ? null
            : round($decisions->where('result', 1.0)->count() / $decisions->count(), 6);
    }

    private function rate(int $value, int $count): ?float
    {
        return $count > 0 ? round($value / $count, 6) : null;
    }

    private function average(float|int $value, int $count): ?float
    {
        return $count > 0 ? round($value / $count, 6) : null;
    }

    private function eloProbability(float $home, float $away): float
    {
        return 1 / (1 + 10 ** (($away - $home) / 400));
    }

    private function restDays(mixed $lastStart, Game $game): ?int
    {
        $start = MlbGameStart::for($game);
        if (! is_string($lastStart) || ! $start) {
            return null;
        }

        $days = $start->copy()->startOfDay()->diffInDays(
            Carbon::parse($lastStart)->startOfDay(),
        );

        return max(0, min(14, (int) $days));
    }
}
