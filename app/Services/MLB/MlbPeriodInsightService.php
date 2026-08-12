<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Services\MLB\Picks\MlbPickMarketService;
use Illuminate\Support\Collection;

class MlbPeriodInsightService
{
    public function __construct(
        private readonly MlbPeriodFeatureStore $featureStore,
        private readonly MlbPickMarketService $markets,
        private readonly MlbPeriodModelContextService $periodModels,
    ) {}

    /**
     * @param  Collection<int, Game>  $games
     * @return array<int, list<array<string, mixed>>>
     */
    public function forGames(Collection $games): array
    {
        $games = $games
            ->filter(fn (mixed $game): bool => $game instanceof Game)
            ->unique('id')
            ->values();
        if ($games->isEmpty()) {
            return [];
        }

        $games->each->loadMissing(['homeTeam', 'awayTeam']);
        $gameIds = $games->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $featuresByGame = $this->featureStore->forGames($games);
        $this->periodModels->prime($gameIds);

        $candidateMarkets = PickCandidate::query()
            ->whereIn('game_id', $gameIds)
            ->whereIn('market_type', ['first_3_moneyline', 'first_5_moneyline'])
            ->whereNull('superseded_at')
            ->get(['game_id', 'market_type'])
            ->groupBy('game_id')
            ->map(fn (Collection $rows): array => $rows->pluck('market_type')->unique()->all());

        return $games->mapWithKeys(function (Game $game) use ($featuresByGame, $candidateMarkets): array {
            $gameId = (int) $game->id;
            $rows = [];

            foreach (MlbPeriodFeatureBuilder::MARKETS as $marketType => $innings) {
                $features = $featuresByGame[$gameId][$marketType] ?? null;
                if (! is_array($features)) {
                    $rows[] = $this->pending($game, $marketType, $innings);

                    continue;
                }

                $models = $this->periodModels->forGame($gameId, $marketType);
                $hasMarket = $this->hasMarket($game, $marketType);
                $hasCandidate = in_array($marketType, $candidateMarkets->get($gameId, []), true);
                $rows[] = $this->present(
                    $game,
                    $marketType,
                    $innings,
                    $features,
                    $hasMarket,
                    $hasCandidate,
                    $models,
                );
            }

            return [$gameId => $rows];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forGame(Game $game): array
    {
        return $this->forGames(collect([$game]))[(int) $game->id] ?? [];
    }

    /** @return array<string, mixed> */
    private function pending(Game $game, string $marketType, int $innings): array
    {
        return [
            'market_type' => $marketType,
            'label' => "F{$innings}",
            'innings' => $innings,
            'state' => 'features_pending',
            'market_available' => $this->hasMarket($game, $marketType),
            'candidate_available' => false,
            'shadow_model_available' => false,
            'pregame_safe' => null,
            'lean' => [
                'side' => 'neutral',
                'team_id' => null,
                'team_abbreviation' => null,
                'period_elo_difference' => null,
                'two_way_home_probability' => null,
                'two_way_away_probability' => null,
            ],
            'home' => null,
            'away' => null,
            'starter_context' => [
                'known' => false,
                'home_rating' => null,
                'away_rating' => null,
                'rating_difference' => null,
            ],
            'confidence' => [
                'level' => 'unavailable',
                'sample_games' => 0,
            ],
            'signals' => [],
            'risk_flags' => ['period_features_pending'],
        ];
    }

    /**
     * @param  array<string, float|int|null>  $features
     * @param  list<array<string, mixed>>  $models
     * @return array<string, mixed>
     */
    private function present(
        Game $game,
        string $marketType,
        int $innings,
        array $features,
        bool $hasMarket,
        bool $hasCandidate,
        array $models,
    ): array {
        $homeEloProbability = $this->probability($features['feature_elo_home_win_probability'] ?? null);
        $homeRolling = $this->probability($features['feature_home_rolling_10_win_pct'] ?? null);
        $awayRolling = $this->probability($features['feature_away_rolling_10_win_pct'] ?? null);
        $eloDiff = (float) ($features['feature_period_elo_diff'] ?? 0.0);
        $pitcherDiff = is_numeric($features['feature_pitcher_elo_diff'] ?? null)
            ? (float) $features['feature_pitcher_elo_diff']
            : null;
        $sampleGames = min(
            (int) ($features['feature_home_prior_games'] ?? 0),
            (int) ($features['feature_away_prior_games'] ?? 0),
        );
        $signals = [];
        $risks = [];

        if (abs($eloDiff) >= 10.0) {
            $signals[] = $eloDiff > 0 ? 'home_period_elo_edge' : 'away_period_elo_edge';
        }
        if ($homeRolling !== null && $awayRolling !== null && abs($homeRolling - $awayRolling) >= 0.08) {
            $signals[] = $homeRolling > $awayRolling ? 'home_recent_period_form_edge' : 'away_recent_period_form_edge';
        }
        if ($pitcherDiff !== null && abs($pitcherDiff) >= 15.0) {
            $signals[] = $pitcherDiff > 0 ? 'home_starter_edge' : 'away_starter_edge';
        }
        if ($sampleGames < 20) {
            $risks[] = 'limited_period_sample';
        }
        if ((int) ($features['feature_pitcher_context_known'] ?? 0) !== 1) {
            $risks[] = 'pregame_pitcher_context_missing';
        }
        if (! $hasMarket) {
            $risks[] = 'period_market_quote_missing';
        }
        if ($innings === 3) {
            $risks[] = 'short_period_variance';
        }

        return [
            'market_type' => $marketType,
            'label' => "F{$innings}",
            'innings' => $innings,
            'state' => $this->state($hasMarket, $hasCandidate, $models !== []),
            'market_available' => $hasMarket,
            'candidate_available' => $hasCandidate,
            'shadow_model_available' => $models !== [],
            'pregame_safe' => true,
            'lean' => [
                'side' => abs($eloDiff) < 5.0 ? 'neutral' : ($eloDiff > 0 ? 'home' : 'away'),
                'team_id' => abs($eloDiff) < 5.0
                    ? null
                    : (int) ($eloDiff > 0 ? $game->home_team_id : $game->away_team_id),
                'team_abbreviation' => abs($eloDiff) < 5.0
                    ? null
                    : ($eloDiff > 0 ? $game->homeTeam?->abbreviation : $game->awayTeam?->abbreviation),
                'period_elo_difference' => round($eloDiff, 1),
                'two_way_home_probability' => $homeEloProbability,
                'two_way_away_probability' => $homeEloProbability === null ? null : round(1 - $homeEloProbability, 6),
            ],
            'home' => $this->teamContext($features, 'home'),
            'away' => $this->teamContext($features, 'away'),
            'starter_context' => [
                'known' => (int) ($features['feature_pitcher_context_known'] ?? 0) === 1,
                'home_rating' => $this->number($features['feature_home_pitcher_elo'] ?? null),
                'away_rating' => $this->number($features['feature_away_pitcher_elo'] ?? null),
                'rating_difference' => $pitcherDiff === null ? null : round($pitcherDiff, 1),
            ],
            'confidence' => [
                'level' => $sampleGames >= 50 ? 'high' : ($sampleGames >= 20 ? 'medium' : 'low'),
                'sample_games' => $sampleGames,
            ],
            'signals' => array_values(array_unique($signals)),
            'risk_flags' => array_values(array_unique($risks)),
        ];
    }

    /** @return array<string, mixed> */
    private function teamContext(array $features, string $side): array
    {
        $games = (int) ($features["feature_{$side}_prior_games"] ?? 0);
        $tieRate = $this->probability($features["feature_{$side}_tie_rate"] ?? null);
        $winPct = $this->probability($features["feature_{$side}_win_pct"] ?? null);
        $ties = $tieRate === null ? 0 : (int) round($games * $tieRate);
        $decisions = max(0, $games - $ties);
        $wins = $winPct === null ? 0 : (int) round($decisions * $winPct);

        return [
            'games' => $games,
            'record' => [
                'wins' => $wins,
                'losses' => max(0, $decisions - $wins),
                'ties' => $ties,
            ],
            'win_probability' => $winPct,
            'tie_rate' => $tieRate,
            'runs_for_per_game' => $this->number($features["feature_{$side}_runs_for_per_game"] ?? null),
            'runs_against_per_game' => $this->number($features["feature_{$side}_runs_against_per_game"] ?? null),
            'run_difference_per_game' => $this->number($features["feature_{$side}_run_diff_per_game"] ?? null),
            'last_10' => [
                'games' => (int) ($features["feature_{$side}_rolling_10_games"] ?? 0),
                'win_probability' => $this->probability($features["feature_{$side}_rolling_10_win_pct"] ?? null),
                'tie_rate' => $this->probability($features["feature_{$side}_rolling_10_tie_rate"] ?? null),
                'run_difference_per_game' => $this->number($features["feature_{$side}_rolling_10_run_diff"] ?? null),
            ],
            'venue' => [
                'games' => (int) ($features["feature_{$side}_venue_games"] ?? 0),
                'win_probability' => $this->probability($features["feature_{$side}_venue_win_pct"] ?? null),
                'tie_rate' => $this->probability($features["feature_{$side}_venue_tie_rate"] ?? null),
            ],
            'rest_days' => is_numeric($features["feature_{$side}_rest_days"] ?? null)
                ? (int) $features["feature_{$side}_rest_days"]
                : null,
        ];
    }

    private function hasMarket(Game $game, string $marketType): bool
    {
        $keys = $marketType === 'first_3_moneyline'
            ? ['h2h_1st_3_innings', 'h2h_1st_3']
            : ['h2h_1st_5_innings', 'h2h_1st_5'];
        $outcomes = $this->markets->sideOutcomes($game, $keys);

        return $outcomes['home'] !== null && $outcomes['away'] !== null;
    }

    private function state(bool $hasMarket, bool $hasCandidate, bool $hasModel): string
    {
        if ($hasCandidate) {
            return 'priced_candidate';
        }
        if ($hasModel && ! $hasMarket) {
            return 'no_bet_missing_quote';
        }
        if ($hasModel) {
            return 'shadow_model_available';
        }
        if ($hasMarket) {
            return 'market_available_pending_candidate';
        }

        return 'insight_only_no_market';
    }

    private function probability(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return $number >= 0.0 && $number <= 1.0 ? $number : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 4) : null;
    }
}
