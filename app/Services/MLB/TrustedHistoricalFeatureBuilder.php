<?php

namespace App\Services\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\EloRating;
use App\Models\MLB\Game;
use App\Support\MLB\MlbGamePhase;
use App\Support\Odds\MarketSpread;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TrustedHistoricalFeatureBuilder
{
    public const PROFILE = 'trusted-core-v1';

    public const FEATURE_VERSION = 'mlb-trusted-core-v1';

    private const ROLLING_GAMES = 20;

    private const RECENT_GAMES = 5;

    /**
     * @return array{
     *     features: array<string, mixed>,
     *     market_context: array<string, mixed>,
     *     source_timestamps: array<string, mixed>,
     *     features_available_at: CarbonInterface,
     *     evidence: array<string, mixed>
     * }|null
     */
    public function build(Game $game): ?array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $gameStartAt = MlbGamePhase::scheduledStartAt($game);

        if ($gameStartAt === null || $game->homeTeam === null || $game->awayTeam === null) {
            return null;
        }

        $homeHistory = $this->teamHistory($game, (int) $game->home_team_id);
        $awayHistory = $this->teamHistory($game, (int) $game->away_team_id);
        $homeForm = $this->teamForm($homeHistory, (int) $game->home_team_id, $gameStartAt);
        $awayForm = $this->teamForm($awayHistory, (int) $game->away_team_id, $gameStartAt);
        $homeTeamElo = $this->teamElo((int) $game->home_team_id, $game);
        $awayTeamElo = $this->teamElo((int) $game->away_team_id, $game);
        $homePitcherElo = $this->neutralPitcherElo();
        $awayPitcherElo = $this->neutralPitcherElo();
        $market = $this->pregameMarket($game, $gameStartAt);

        $features = [
            'home_field_indicator' => 1,
            ...$this->sideFeatures('home', $homeForm),
            ...$this->sideFeatures('away', $awayForm),
            'home_team_elo' => $homeTeamElo['elo'],
            'away_team_elo' => $awayTeamElo['elo'],
            'team_elo_diff' => round($homeTeamElo['elo'] - $awayTeamElo['elo'], 4),
            'home_team_elo_known' => $homeTeamElo['known'],
            'away_team_elo_known' => $awayTeamElo['known'],
            'home_pitcher_elo' => $homePitcherElo['elo'],
            'away_pitcher_elo' => $awayPitcherElo['elo'],
            'pitcher_elo_diff' => round($homePitcherElo['elo'] - $awayPitcherElo['elo'], 4),
            'home_pitcher_known' => $homePitcherElo['known'],
            'away_pitcher_known' => $awayPitcherElo['known'],
            'home_pitcher_confidence' => $homePitcherElo['confidence'],
            'away_pitcher_confidence' => $awayPitcherElo['confidence'],
            'pregame_market_available' => $market['snapshot_id'] === null ? 0 : 1,
            'market_home_moneyline' => $market['home_moneyline'],
            'market_away_moneyline' => $market['away_moneyline'],
            'market_home_no_vig_probability' => $market['home_no_vig_probability'],
            'market_bookmaker_home_line' => $market['home_spread'],
            'market_home_margin' => $market['home_spread'] === null
                ? null
                : MarketSpread::bookmakerHomeLineToHomeMargin($market['home_spread']),
            'market_total' => $market['total'],
        ];

        $sourceTimestamps = array_filter([
            'history_cutoff_at' => $gameStartAt->copy()->startOfDay()->subSecond()->toIso8601String(),
            'home_latest_prior_game_at' => $homeForm['latest_game_at'],
            'away_latest_prior_game_at' => $awayForm['latest_game_at'],
            'home_team_elo_date' => $homeTeamElo['date'],
            'away_team_elo_date' => $awayTeamElo['date'],
            'odds_captured_at' => $market['captured_at'],
        ], fn (mixed $value): bool => $value !== null);

        $featuresAvailableAt = collect($sourceTimestamps)
            ->map(fn (string $timestamp): Carbon => Carbon::parse($timestamp))
            ->push($gameStartAt->copy()->subSecond())
            ->max();

        return [
            'features' => $features,
            'market_context' => [
                'source' => $market['snapshot_id'] === null ? 'unavailable' : 'pregame_odds_snapshot',
                'snapshot_id' => $market['snapshot_id'],
                'bookmaker' => $market['bookmaker'],
                'captured_at' => $market['captured_at'],
                'home_moneyline' => $market['home_moneyline'],
                'away_moneyline' => $market['away_moneyline'],
                'home_no_vig_probability' => $market['home_no_vig_probability'],
                'bookmaker_home_line' => $market['home_spread'],
                'market_home_margin' => $market['home_spread'] === null
                    ? null
                    : MarketSpread::bookmakerHomeLineToHomeMargin($market['home_spread']),
                'vegas_spread' => $market['home_spread'],
                'market_total' => $market['total'],
                'pregame_safe' => $market['snapshot_id'] !== null,
            ],
            'source_timestamps' => [
                ...$sourceTimestamps,
                'features_available_at' => $featuresAvailableAt->toIso8601String(),
                'game_start_at' => $gameStartAt->toIso8601String(),
            ],
            'features_available_at' => $featuresAvailableAt,
            'evidence' => [
                'profile' => self::PROFILE,
                'verification_method' => 'strict_prior_date_results_dated_team_ratings_neutral_pitchers_and_pregame_odds_cutoff',
                'history_window_games' => self::ROLLING_GAMES,
                'recent_window_games' => self::RECENT_GAMES,
                'same_day_results_excluded' => true,
                'home_team_elo_source_id' => $homeTeamElo['source_id'],
                'away_team_elo_source_id' => $awayTeamElo['source_id'],
                'historical_pitcher_features' => [
                    'source' => 'neutral_default',
                    'reason' => 'timestamped_pregame_probable_pitcher_history_unavailable',
                    'mutable_game_probable_pitcher_ids_used' => false,
                ],
                'odds_snapshot_id' => $market['snapshot_id'],
                'excluded_feature_groups' => [
                    'mutable_current_probable_pitchers',
                    'current_injuries',
                    'current_depth_charts',
                    'current_weather',
                    'mutable_team_metrics',
                    'final_season_aggregates',
                    'same_day_completed_game_results',
                    'post_start_odds',
                ],
            ],
        ];
    }

    /**
     * @return Collection<int, Game>
     */
    private function teamHistory(Game $game, int $teamId): Collection
    {
        return Game::query()
            ->where('season', (int) $game->season)
            ->where('season_type', (string) $game->season_type)
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->whereDate('game_date', '<', $game->game_date->toDateString())
            ->where(function ($query) use ($teamId): void {
                $query->where('home_team_id', $teamId)
                    ->orWhere('away_team_id', $teamId);
            })
            ->orderByDesc('game_date')
            ->orderByDesc('game_time')
            ->orderByDesc('id')
            ->limit(self::ROLLING_GAMES)
            ->get();
    }

    /**
     * @param  Collection<int, Game>  $games
     * @return array<string, int|float|string|null>
     */
    private function teamForm(Collection $games, int $teamId, CarbonInterface $gameStartAt): array
    {
        $results = $games->map(function (Game $game) use ($teamId): array {
            $isHome = (int) $game->home_team_id === $teamId;
            $runsFor = (int) ($isHome ? $game->home_score : $game->away_score);
            $runsAgainst = (int) ($isHome ? $game->away_score : $game->home_score);

            return [
                'won' => $runsFor > $runsAgainst ? 1 : 0,
                'runs_for' => $runsFor,
                'runs_against' => $runsAgainst,
                'run_differential' => $runsFor - $runsAgainst,
            ];
        });
        $recent = $results->take(self::RECENT_GAMES);
        $latestGame = $games->first();
        $latestGameAt = $latestGame === null
            ? null
            : MlbGamePhase::scheduledStartAt($latestGame);
        $restDays = $latestGameAt === null
            ? null
            : max(0, min(7, $latestGameAt->copy()->startOfDay()->diffInDays($gameStartAt->copy()->startOfDay()) - 1));

        return [
            'games' => $results->count(),
            'win_pct' => $this->average($results, 'won'),
            'runs_for' => $this->average($results, 'runs_for'),
            'runs_against' => $this->average($results, 'runs_against'),
            'run_differential' => $this->average($results, 'run_differential'),
            'recent_games' => $recent->count(),
            'recent_win_pct' => $this->average($recent, 'won'),
            'recent_run_differential' => $this->average($recent, 'run_differential'),
            'rest_days' => $restDays,
            'rest_known' => $restDays === null ? 0 : 1,
            'latest_game_at' => $latestGameAt?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, int|float|string|null>  $form
     * @return array<string, int|float|null>
     */
    private function sideFeatures(string $side, array $form): array
    {
        return [
            "{$side}_prior_games" => $form['games'],
            "{$side}_rolling_win_pct_20" => $form['win_pct'],
            "{$side}_rolling_runs_scored_20" => $form['runs_for'],
            "{$side}_rolling_runs_allowed_20" => $form['runs_against'],
            "{$side}_rolling_run_differential_20" => $form['run_differential'],
            "{$side}_recent_games" => $form['recent_games'],
            "{$side}_recent_win_pct_5" => $form['recent_win_pct'],
            "{$side}_recent_run_differential_5" => $form['recent_run_differential'],
            "{$side}_rest_days" => $form['rest_days'],
            "{$side}_rest_known" => $form['rest_known'],
        ];
    }

    /**
     * @return array{elo: float, known: int, date: ?string, source_id: ?int}
     */
    private function teamElo(int $teamId, Game $game): array
    {
        $rating = EloRating::query()
            ->where('team_id', $teamId)
            ->whereNotNull('date')
            ->whereDate('date', '<', $game->game_date->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        return [
            'elo' => round((float) ($rating?->elo_rating ?? config('mlb.elo.default_rating', 1500)), 4),
            'known' => $rating === null ? 0 : 1,
            'date' => $rating?->date?->endOfDay()->toIso8601String(),
            'source_id' => $rating?->id,
        ];
    }

    /**
     * Historical game rows only retain the latest probable pitchers. Without an
     * append-only pregame source, using those IDs would leak postgame corrections.
     *
     * @return array{elo: float, known: int, confidence: float}
     */
    private function neutralPitcherElo(): array
    {
        return [
            'elo' => round((float) config('mlb.elo.default_rating', 1500), 4),
            'known' => 0,
            'confidence' => 0.0,
        ];
    }

    /**
     * @return array{
     *     snapshot_id: ?int,
     *     bookmaker: ?string,
     *     captured_at: ?string,
     *     home_moneyline: ?int,
     *     away_moneyline: ?int,
     *     home_no_vig_probability: ?float,
     *     home_spread: ?float,
     *     total: ?float
     * }
     */
    private function pregameMarket(Game $game, CarbonInterface $gameStartAt): array
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'mlb')
            ->where('game_table', $game->getTable())
            ->where('game_id', (int) $game->id)
            ->whereNotNull('captured_at')
            ->where('captured_at', '<', $gameStartAt)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return $this->emptyMarket();
        }

        $oddsData = is_array($snapshot->odds_data) ? $snapshot->odds_data : [];
        $homeNames = $this->teamNames($game, 'home', $oddsData);
        $awayNames = $this->teamNames($game, 'away', $oddsData);
        $homeMoneyline = null;
        $awayMoneyline = null;
        $homeSpread = null;
        $total = null;

        foreach (($oddsData['bookmakers'][0]['markets'] ?? []) as $market) {
            foreach (($market['outcomes'] ?? []) as $outcome) {
                $key = (string) ($market['key'] ?? '');
                $name = $this->normalizeName((string) ($outcome['name'] ?? ''));

                if ($key === 'h2h' && is_numeric($outcome['price'] ?? null)) {
                    if ($this->matchesAny($name, $homeNames)) {
                        $homeMoneyline = (int) $outcome['price'];
                    } elseif ($this->matchesAny($name, $awayNames)) {
                        $awayMoneyline = (int) $outcome['price'];
                    }
                }

                if ($key === 'spreads' && is_numeric($outcome['point'] ?? null) && $this->matchesAny($name, $homeNames)) {
                    $homeSpread = (float) $outcome['point'];
                }

                if ($key === 'totals' && strtolower((string) ($outcome['name'] ?? '')) === 'over' && is_numeric($outcome['point'] ?? null)) {
                    $total = (float) $outcome['point'];
                }
            }
        }

        return [
            'snapshot_id' => (int) $snapshot->id,
            'bookmaker' => $snapshot->bookmaker_key
                ?? data_get($oddsData, 'bookmakers.0.key'),
            'captured_at' => $snapshot->captured_at?->toIso8601String(),
            'home_moneyline' => $homeMoneyline,
            'away_moneyline' => $awayMoneyline,
            'home_no_vig_probability' => $this->homeNoVigProbability($homeMoneyline, $awayMoneyline),
            'home_spread' => $homeSpread,
            'total' => $total,
        ];
    }

    /**
     * @return array{snapshot_id: null, bookmaker: null, captured_at: null, home_moneyline: null, away_moneyline: null, home_no_vig_probability: null, home_spread: null, total: null}
     */
    private function emptyMarket(): array
    {
        return [
            'snapshot_id' => null,
            'bookmaker' => null,
            'captured_at' => null,
            'home_moneyline' => null,
            'away_moneyline' => null,
            'home_no_vig_probability' => null,
            'home_spread' => null,
            'total' => null,
        ];
    }

    /**
     * @param  Collection<int, array<string, int>>  $rows
     */
    private function average(Collection $rows, string $key): float
    {
        return $rows->isEmpty() ? 0.0 : round((float) $rows->avg($key), 4);
    }

    /**
     * @param  array<string, mixed>  $oddsData
     * @return list<string>
     */
    private function teamNames(Game $game, string $side, array $oddsData): array
    {
        $team = $side === 'home' ? $game->homeTeam : $game->awayTeam;

        return collect([
            $team?->location,
            $team?->name,
            $team?->abbreviation,
            trim(((string) ($team?->location ?? '')).' '.((string) ($team?->name ?? ''))),
            $oddsData["{$side}_team"] ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->normalizeName($value))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $candidates
     */
    private function matchesAny(string $name, array $candidates): bool
    {
        return $name !== '' && collect($candidates)->contains(
            fn (string $candidate): bool => $name === $candidate
                || str_contains($name, $candidate)
                || str_contains($candidate, $name)
        );
    }

    private function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower($name)));
    }

    private function homeNoVigProbability(?int $homePrice, ?int $awayPrice): ?float
    {
        if ($homePrice === null || $awayPrice === null) {
            return null;
        }

        $homeImplied = $this->impliedProbability($homePrice);
        $awayImplied = $this->impliedProbability($awayPrice);
        $total = $homeImplied + $awayImplied;

        return $total <= 0.0 ? null : round($homeImplied / $total, 6);
    }

    private function impliedProbability(int $price): float
    {
        return $price < 0
            ? abs($price) / (abs($price) + 100)
            : 100 / ($price + 100);
    }
}
