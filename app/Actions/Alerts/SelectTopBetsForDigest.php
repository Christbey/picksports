<?php

namespace App\Actions\Alerts;

use App\Actions\CBB\CalculateBettingValue;
use App\Models\CBB\Game;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class SelectTopBetsForDigest
{
    public function __construct(
        protected CalculateBettingValue $calculateBettingValue
    ) {}

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
        // Get user's tier limit for number of bets
        $tierLimit = $this->getUserTierLimit($user);

        // Get all scheduled games for the date
        $games = $this->getScheduledGames($sport, $date);

        // Analyze each game and collect all betting recommendations
        $allRecommendations = $this->analyzeGames($games);

        // Score and rank recommendations
        $rankedRecommendations = $this->scoreRecommendations($allRecommendations);

        // Apply diversification rules and tier limit
        $selectedBets = $this->applyDiversificationAndLimit($rankedRecommendations, $tierLimit);

        return $selectedBets;
    }

    /**
     * Get bet limit based on user's subscription tier
     */
    protected function getUserTierLimit(User $user): int
    {
        $tier = $user->subscriptionTier();

        if (! $tier) {
            return config('alerts.digest.bets_per_tier.free', 3);
        }

        $tierSlug = $tier->slug;

        return config("alerts.digest.bets_per_tier.{$tierSlug}", 3);
    }

    /**
     * Get scheduled games for the given date
     */
    protected function getScheduledGames(string $sport, Carbon $date): Collection
    {
        // Currently only CBB is supported
        if ($sport !== 'cbb') {
            return collect([]);
        }

        return Game::query()
            ->whereDate('game_date', $date)
            ->where('status', 'STATUS_SCHEDULED')
            ->with(['homeTeam', 'awayTeam', 'prediction'])
            ->get();
    }

    /**
     * Analyze all games and collect betting recommendations
     */
    protected function analyzeGames(Collection $games): Collection
    {
        $recommendations = collect([]);

        foreach ($games as $game) {
            $gameRecs = $this->calculateBettingValue->execute($game);

            if ($gameRecs && is_array($gameRecs)) {
                foreach ($gameRecs as $rec) {
                    // Add game data to recommendation for context
                    $rec['game'] = $game;
                    $recommendations->push($rec);
                }
            }
        }

        return $recommendations;
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
