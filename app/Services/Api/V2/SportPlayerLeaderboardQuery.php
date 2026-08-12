<?php

namespace App\Services\Api\V2;

use App\Services\Api\V2\Concerns\BuildsSportQueries;
use App\Services\PlayerStats\BasketballLeaderboardService;
use App\Services\PlayerStats\CfbPlayerLeaderboardService;
use App\Services\PlayerStats\MlbPlayerLeaderboardService;
use App\Services\PlayerStats\NbaPlayerEpaCalculator;
use App\Services\PlayerStats\NflPlayerLeaderboardService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

class SportPlayerLeaderboardQuery
{
    use BuildsSportQueries;

    public function __construct(
        private readonly BasketballLeaderboardService $basketballLeaderboards,
        private readonly NbaPlayerEpaCalculator $basketballEpa,
        private readonly CfbPlayerLeaderboardService $cfbLeaderboards,
        private readonly MlbPlayerLeaderboardService $mlbLeaderboards,
        private readonly NflPlayerLeaderboardService $nflLeaderboards,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function get(
        SportContext $context,
        array $filters = [],
        ?Authenticatable $user = null,
    ): Collection {
        if ($this->isBasketballLeaderboard($context)) {
            return $this->basketballLeaderboard($context, $filters);
        }

        if ($context->slug === 'cfb') {
            return $this->cfbLeaderboard($context, $filters);
        }

        if ($context->slug === 'mlb') {
            return $this->mlbLeaderboard($context, $filters);
        }

        if ($context->slug === 'nfl') {
            return $this->nflLeaderboard($context, $filters);
        }

        abort(404, 'Leaderboard not available for this sport');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function nflLeaderboard(SportContext $context, array $filters): Collection
    {
        if (! $context->supports('player_stats_leaderboard')) {
            abort(404, "Player leaderboard is not available for {$context->slug}.");
        }

        return $this->nflLeaderboards->execute(
            minGames: (int) ($filters['min_games'] ?? 4),
            season: array_key_exists('season', $filters) ? (int) $filters['season'] : null,
            seasonTypeCandidates: $this->seasonTypeCandidates($context, (string) ($filters['season_type'] ?? '')),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function mlbLeaderboard(SportContext $context, array $filters): Collection
    {
        if (! $context->supports('player_stats_leaderboard')) {
            abort(404, "Player leaderboard is not available for {$context->slug}.");
        }

        return $this->mlbLeaderboards->execute(
            minGames: (int) ($filters['min_games'] ?? 10),
            season: array_key_exists('season', $filters) ? (int) $filters['season'] : null,
            seasonTypeCandidates: $this->seasonTypeCandidates($context, (string) ($filters['season_type'] ?? '')),
            statType: array_key_exists('stat_type', $filters) ? (string) $filters['stat_type'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function cfbLeaderboard(SportContext $context, array $filters): Collection
    {
        if (! $context->supports('player_stats_leaderboard')) {
            abort(404, "Player leaderboard is not available for {$context->slug}.");
        }

        return $this->cfbLeaderboards->execute(
            minGames: (int) ($filters['min_games'] ?? 4),
            season: array_key_exists('season', $filters) ? (int) $filters['season'] : null,
            seasonTypeCandidates: $this->seasonTypeCandidates($context, (string) ($filters['season_type'] ?? '')),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function basketballLeaderboard(SportContext $context, array $filters): Collection
    {
        $playerStatModel = $this->modelClass($context, 'player_stat', 'Player stats');
        $playerModel = $this->modelClass($context, 'player', 'Players');
        $gameModel = $this->modelClass($context, 'game', 'Games');
        $minGames = (int) ($filters['min_games'] ?? 10);
        $season = array_key_exists('season', $filters) ? (int) $filters['season'] : null;
        $seasonTypeCandidates = $this->seasonTypeCandidates($context, (string) ($filters['season_type'] ?? ''));
        $profile = in_array($context->slug, ['cbb', 'wcbb'], true)
            ? NbaPlayerEpaCalculator::PROFILE_CBB
            : NbaPlayerEpaCalculator::PROFILE_NBA;

        return $this->basketballLeaderboards
            ->execute($playerStatModel, $playerModel, $minGames, $gameModel, $season, $seasonTypeCandidates)
            ->map(function (array $entry) use ($profile): array {
                $estimatedEpaPerGame = $this->basketballEpa->estimateFromBoxScore(
                    $entry['points_per_game'] ?? 0,
                    $entry['assists_per_game'] ?? 0,
                    $entry['rebounds_per_game'] ?? 0,
                    $entry['steals_per_game'] ?? 0,
                    $entry['blocks_per_game'] ?? 0,
                    $entry['turnovers_per_game'] ?? 0,
                    $entry['field_goals_made_per_game'] ?? 0,
                    $entry['field_goals_attempted_per_game'] ?? 0,
                    $entry['free_throws_made_per_game'] ?? 0,
                    $entry['free_throws_attempted_per_game'] ?? 0,
                    $profile,
                );

                $entry['estimated_epa_per_game'] = $estimatedEpaPerGame;
                $entry['estimated_epa_per_36'] = $this->basketballEpa->estimatePer36(
                    $estimatedEpaPerGame,
                    $entry['minutes_per_game'] ?? 0,
                );

                return $entry;
            })
            ->values();
    }

    private function isBasketballLeaderboard(SportContext $context): bool
    {
        return $context->supports('player_stats_leaderboard')
            && in_array($context->slug, ['nba', 'cbb', 'wnba', 'wcbb'], true);
    }

    /**
     * @return class-string
     */
    private function modelClass(SportContext $context, string $key, string $label): string
    {
        return $this->requireModel($context, $key, $label);
    }
}
