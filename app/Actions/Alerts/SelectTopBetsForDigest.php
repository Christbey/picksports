<?php

namespace App\Actions\Alerts;

use App\Models\User;
use App\Support\SportCatalog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SelectTopBetsForDigest
{
    private const SPORT_GAME_MODELS = [
        'nfl' => \App\Models\NFL\Game::class,
        'nba' => \App\Models\NBA\Game::class,
        'cbb' => \App\Models\CBB\Game::class,
        'wcbb' => \App\Models\WCBB\Game::class,
        'mlb' => \App\Models\MLB\Game::class,
        'cfb' => \App\Models\CFB\Game::class,
        'wnba' => \App\Models\WNBA\Game::class,
    ];

    private const SPORT_CALCULATORS = [
        'nfl' => \App\Actions\NFL\CalculateBettingValue::class,
        'nba' => \App\Actions\NBA\CalculateBettingValue::class,
        'cbb' => \App\Actions\CBB\CalculateBettingValue::class,
    ];

    /**
     * Select top betting opportunities for a user's daily digest
     *
     * @param  User  $user  The user to generate digest for
     * @param  string  $sport  Sport code (e.g., 'cbb')
     * @param  Carbon  $date  Date to generate digest for
     * @return Collection Collection of ranked betting recommendations
     */
    public function execute(User $user, string $sport, Carbon $date): Collection
    {
        $sport = strtolower($sport);

        // Get user's tier limit for number of bets
        $tierLimit = $this->getUserTierLimit($user);

        // Get all scheduled games for the date
        $games = $this->getScheduledGames($sport, $date);

        // Analyze each game and collect all betting recommendations
        $allRecommendations = $this->analyzeGames($sport, $games);

        // Score and rank recommendations
        $rankedRecommendations = $this->scoreRecommendations($allRecommendations);

        // Apply diversification rules and tier limit
        $selectedBets = $this->applyDiversificationAndLimit($rankedRecommendations, $tierLimit);

        return $selectedBets;
    }

    /**
     * Select top betting opportunities across multiple sports for a single digest.
     *
     * @param  array<int, string>  $sports
     */
    public function executeAcrossSports(User $user, array $sports, Carbon $date): Collection
    {
        $tierLimit = $this->getUserTierLimit($user);
        $allRecommendations = collect([]);

        foreach ($sports as $sport) {
            $normalizedSport = strtolower((string) $sport);
            $games = $this->getScheduledGames($normalizedSport, $date);
            $allRecommendations = $allRecommendations->concat(
                $this->analyzeGames($normalizedSport, $games)
            );
        }

        $rankedRecommendations = $this->scoreRecommendations($allRecommendations);

        return $this->applyDiversificationAndLimit($rankedRecommendations, $tierLimit);
    }

    /**
     * Get bet limit based on user's subscription tier
     */
    protected function getUserTierLimit(User $user): int
    {
        $tier = $user->subscriptionTier();

        if (! $tier) {
            return max(5, config('alerts.digest.bets_per_tier.free', 3));
        }

        $tierSlug = $tier->slug;

        return max(5, config("alerts.digest.bets_per_tier.{$tierSlug}", 3));
    }

    /**
     * Get scheduled games for the given date
     */
    protected function getScheduledGames(string $sport, Carbon $date): Collection
    {
        $gameModel = self::SPORT_GAME_MODELS[$sport] ?? null;
        if (! $gameModel) {
            return collect([]);
        }

        return $gameModel::query()
            ->whereDate('game_date', $date->toDateString())
            ->where('status', 'STATUS_SCHEDULED')
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->get();
    }

    /**
     * Analyze all games and collect betting recommendations
     */
    protected function analyzeGames(string $sport, Collection $games): Collection
    {
        $recommendations = collect([]);
        $calculatorClass = self::SPORT_CALCULATORS[$sport] ?? null;

        if (! $calculatorClass) {
            return $recommendations;
        }

        $calculator = app($calculatorClass);

        foreach ($games as $game) {
            $gameRecs = $calculator->execute($game);
            if (empty($gameRecs)) {
                $gameRecs = $this->buildFallbackRecommendationsForDigest($game);
            }

            if ($gameRecs && is_array($gameRecs)) {
                foreach ($gameRecs as $rec) {
                    // Add game data to recommendation for context
                    $rec['game'] = $game;
                    $rec['sport'] = $sport;
                    $recommendations->push($rec);
                }
            }
        }

        return $recommendations;
    }

    /**
     * Build non-threshold fallback recommendations so digest can still rank top opportunities.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildFallbackRecommendationsForDigest(object $game): array
    {
        $prediction = $game->prediction;
        $oddsData = $game->odds_data;

        if (! $prediction || ! is_array($oddsData) || ! isset($oddsData['bookmakers'])) {
            return [];
        }

        $recommendations = [];
        $spreadsMarket = $this->extractMarket($oddsData, 'spreads');
        $totalsMarket = $this->extractMarket($oddsData, 'totals');
        $moneylineMarket = $this->extractMarket($oddsData, 'h2h');

        $homeName = $this->teamDisplayName($game->homeTeam, (string) ($oddsData['home_team'] ?? 'Home'));
        $awayName = $this->teamDisplayName($game->awayTeam, (string) ($oddsData['away_team'] ?? 'Away'));

        if ($spreadsMarket && $prediction->predicted_spread !== null) {
            $homeOutcome = $this->findTeamOutcome($spreadsMarket['outcomes'] ?? [], $oddsData['home_team'] ?? '', $homeName);
            $awayOutcome = $this->findTeamOutcome($spreadsMarket['outcomes'] ?? [], $oddsData['away_team'] ?? '', $awayName);

            if ($homeOutcome && isset($homeOutcome['point'])) {
                $marketHomeLine = (float) $homeOutcome['point'];
                $marketSpreadModelConvention = -$marketHomeLine;
                $predictedSpread = (float) $prediction->predicted_spread;
                $edge = abs($predictedSpread - $marketSpreadModelConvention);
                $betHome = $predictedSpread > $marketSpreadModelConvention;
                $betLine = $betHome ? $marketHomeLine : -$marketHomeLine;
                $selectedOdds = $betHome
                    ? (float) ($homeOutcome['price'] ?? -110)
                    : (float) ($awayOutcome['price'] ?? -110);

                $recommendations[] = [
                    'type' => 'spread',
                    'recommendation' => ($betHome ? "Bet {$homeName}" : "Bet {$awayName}").' '.$this->formatLine($betLine),
                    'edge' => round($edge, 1),
                    'odds' => $selectedOdds,
                    'confidence' => round((float) ($prediction->confidence_score ?? 50), 2),
                    'reasoning' => 'Digest fallback ranking by model-vs-market spread difference',
                ];
            }
        }

        if ($totalsMarket && $prediction->predicted_total !== null) {
            $overOutcome = collect($totalsMarket['outcomes'] ?? [])->firstWhere('name', 'Over');
            $underOutcome = collect($totalsMarket['outcomes'] ?? [])->firstWhere('name', 'Under');
            if ($overOutcome && isset($overOutcome['point'])) {
                $marketTotal = (float) $overOutcome['point'];
                $predictedTotal = (float) $prediction->predicted_total;
                $edge = abs($predictedTotal - $marketTotal);
                $betOver = $predictedTotal > $marketTotal;

                $recommendations[] = [
                    'type' => 'total',
                    'recommendation' => $betOver ? 'Bet Over' : 'Bet Under',
                    'edge' => round($edge, 1),
                    'odds' => (float) (($betOver ? ($overOutcome['price'] ?? null) : ($underOutcome['price'] ?? null)) ?? -110),
                    'confidence' => round((float) ($prediction->confidence_score ?? 50), 2),
                    'reasoning' => 'Digest fallback ranking by model-vs-market total difference',
                ];
            }
        }

        if ($moneylineMarket && $prediction->win_probability !== null) {
            $homeOutcome = $this->findTeamOutcome($moneylineMarket['outcomes'] ?? [], $oddsData['home_team'] ?? '', $homeName);
            $awayOutcome = $this->findTeamOutcome($moneylineMarket['outcomes'] ?? [], $oddsData['away_team'] ?? '', $awayName);
            $homePrice = $this->toNumeric($homeOutcome['price'] ?? null);
            $awayPrice = $this->toNumeric($awayOutcome['price'] ?? null);

            if ($homePrice !== null && $awayPrice !== null) {
                $homeModelProb = (float) $prediction->win_probability;
                $awayModelProb = 1 - $homeModelProb;
                $homeEdge = $homeModelProb - $this->americanToImplied($homePrice);
                $awayEdge = $awayModelProb - $this->americanToImplied($awayPrice);
                $betHome = $homeEdge >= $awayEdge;
                $edge = $betHome ? $homeEdge : $awayEdge;
                $price = $betHome ? $homePrice : $awayPrice;

                $recommendations[] = [
                    'type' => 'moneyline',
                    'recommendation' => $betHome ? "Bet {$homeName} ML" : "Bet {$awayName} ML",
                    'edge' => round($edge * 100, 1),
                    'odds' => $price,
                    'confidence' => round((float) ($prediction->confidence_score ?? 50), 2),
                    'reasoning' => 'Digest fallback ranking by model-vs-implied win probability',
                ];
            }
        }

        return $recommendations;
    }

    protected function extractMarket(array $oddsData, string $marketKey): ?array
    {
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) === $marketKey) {
                    return $market;
                }
            }
        }

        return null;
    }

    protected function findTeamOutcome(array $outcomes, string $oddsTeamName, string $displayName): ?array
    {
        foreach ($outcomes as $outcome) {
            $name = (string) ($outcome['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if ($oddsTeamName !== '' && strcasecmp($name, $oddsTeamName) === 0) {
                return $outcome;
            }

            if (str_contains(strtolower($name), strtolower($displayName))) {
                return $outcome;
            }
        }

        return null;
    }

    protected function teamDisplayName(mixed $team, string $fallback): string
    {
        if (! $team) {
            return $fallback;
        }

        $locationAndName = trim(implode(' ', array_filter([
            $team->location ?? null,
            $team->name ?? null,
        ])));

        return $team->school
            ?? ($locationAndName !== '' ? $locationAndName : null)
            ?? $team->abbreviation
            ?? $fallback;
    }

    protected function toNumeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function americanToImplied(float $odds): float
    {
        if ($odds > 0) {
            return 100 / ($odds + 100);
        }

        return abs($odds) / (abs($odds) + 100);
    }

    protected function formatLine(float $line): string
    {
        return $line > 0 ? '+'.number_format($line, 1) : number_format($line, 1);
    }

    public function totalGamesForDate(string $sport, Carbon $date): int
    {
        $gameModel = self::SPORT_GAME_MODELS[strtolower($sport)] ?? null;
        if (! $gameModel) {
            return 0;
        }

        return $gameModel::query()
            ->whereDate('game_date', $date->toDateString())
            ->where('status', 'STATUS_SCHEDULED')
            ->count();
    }

    /**
     * @return array<int, string>
     */
    public static function supportedSports(): array
    {
        return SportCatalog::ALL;
    }

    /**
     * Score recommendations using multi-factor weighted algorithm
     */
    protected function scoreRecommendations(Collection $recommendations): Collection
    {
        $weights = config('alerts.digest.ranking_weights');
        $betTypeScores = config('alerts.digest.bet_type_scores');

        return $recommendations->map(function ($rec) use ($weights, $betTypeScores) {
            // Normalize edge value (0-100 scale)
            // For spread/total: edge is in points (typically 0-20)
            // For moneyline: edge is already in percentage (0-100)
            $edgeNormalized = $rec['type'] === 'moneyline'
                ? $rec['edge'] / 100
                : min($rec['edge'] / 20, 1); // Cap spread/total at 20 points = 100%

            // Normalize confidence (0-100 to 0-1)
            $confidenceNormalized = $rec['confidence'] / 100;

            // Normalize Kelly size (0-100 to 0-1)
            $kellySizeNormalized = isset($rec['kelly_bet_size_percent'])
                ? $rec['kelly_bet_size_percent'] / 100
                : 0.5; // Default for non-ML bets

            // Get bet type quality score
            $betTypeScore = $betTypeScores[$rec['type']] ?? 0.5;

            // Calculate composite score
            $compositeScore =
                ($edgeNormalized * $weights['edge']) +
                ($confidenceNormalized * $weights['confidence']) +
                ($kellySizeNormalized * $weights['kelly_size']) +
                ($betTypeScore * $weights['bet_type']);

            $rec['composite_score'] = $compositeScore;

            return $rec;
        })->sortByDesc('composite_score')->values();
    }

    /**
     * Apply diversification rules and tier limit
     */
    protected function applyDiversificationAndLimit(Collection $recommendations, int $limit): Collection
    {
        $maxSameType = config('alerts.digest.diversification.max_same_type', 4);
        $typeCounts = ['spread' => 0, 'total' => 0, 'moneyline' => 0];
        $selected = collect([]);

        foreach ($recommendations as $rec) {
            // Stop if we've reached the tier limit
            if ($selected->count() >= $limit) {
                break;
            }

            $type = $rec['type'];

            // Skip if we've hit the max for this bet type
            if ($typeCounts[$type] >= $maxSameType) {
                continue;
            }

            $selected->push($rec);
            $typeCounts[$type]++;
        }

        return $selected;
    }
}
