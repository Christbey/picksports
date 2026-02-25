<?php

namespace App\Services\BettingRecommendations;

use App\Models\PlayerProp;
use Illuminate\Support\Collection;

class PlayerPropAnalyzer
{
    /**
     * Analyze player props for any sport and generate betting recommendations
     */
    public function analyzeProps(string $sport = 'NBA', ?int $minGames = 3, ?string $dateFilter = null, ?int $gameFilter = null): Collection
    {
        $sportConfig = $this->getSportConfig($sport);

        // Get all upcoming player props with game data
        $props = PlayerProp::query()
            ->where('sport', $sportConfig['odds_api_key'])
            ->where('gameable_type', $sportConfig['game_model'])
            ->whereHas('gameable', function ($q) use ($dateFilter, $gameFilter) {
                $q->where('status', 'STATUS_SCHEDULED')
                    ->whereDate('game_date', '>=', now());

                if ($dateFilter) {
                    $q->whereDate('game_date', $dateFilter);
                }

                if ($gameFilter) {
                    $q->where('id', $gameFilter);
                }
            })
            ->with(['gameable.homeTeam', 'gameable.awayTeam'])
            ->get();

        $recommendations = collect();

        foreach ($props as $prop) {
            $recommendation = $this->analyzeProp($prop, $minGames, $sportConfig);

            if ($recommendation && $recommendation['confidence'] >= 60) {
                $recommendations->push($recommendation);
            }
        }

        return $recommendations->sortByDesc('confidence')->values();
    }

    /**
     * Analyze a single prop and generate recommendation
     */
    protected function analyzeProp(PlayerProp $prop, int $minGames, array $sportConfig): ?array
    {
        // Try to find player by name fuzzy matching
        $player = $this->findPlayerByName($prop->player_name, $sportConfig['player_model']);

        if (! $player) {
            return null;
        }

        // Get stat field based on market
        $statField = $this->getStatFieldForMarket($prop->market);

        if (! $statField) {
            return null;
        }

        $game = $prop->gameable;
        $opponentId = $game->home_team_id === $player->team_id ? $game->away_team_id : $game->home_team_id;
        $isHome = $game->home_team_id === $player->team_id;

        // Calculate player averages
        $seasonAvg = $this->calculateSeasonAverage($player->id, $statField, $minGames, $sportConfig['player_stat_model']);
        $recentAvg = $this->calculateRecentAverage($player->id, $statField, 10, $sportConfig['player_stat_model']);
        $last5Avg = $this->calculateRecentAverage($player->id, $statField, 5, $sportConfig['player_stat_model']);

        // Advanced stats
        $vsOpponentAvg = $this->calculateVsOpponentAverage($player->id, $opponentId, $statField, $sportConfig['player_stat_model'], $sportConfig['game_model']);
        $homeAwayAvg = $this->calculateHomeAwayAverage($player->id, $isHome, $statField, $sportConfig['player_stat_model'], $sportConfig['game_model']);
        $hitRate = $this->calculateHitRateVsOpponent($player->id, $opponentId, $statField, $prop->line, $sportConfig['player_stat_model'], $sportConfig['game_model']);

        if ($seasonAvg === null) {
            return null;
        }

        // Calculate edge and confidence with advanced factors
        $analysis = $this->calculateEdge(
            $prop,
            $seasonAvg,
            $recentAvg,
            $last5Avg,
            $vsOpponentAvg,
            $homeAwayAvg,
            $hitRate,
            $isHome
        );

        if (! $analysis['recommendation']) {
            return null;
        }

        return [
            'prop' => $prop,
            'player' => $player,
            'game' => $prop->gameable,
            'market' => $this->formatMarketName($prop->market),
            'line' => $prop->line,
            'recommendation' => $analysis['recommendation'],
            'odds' => $analysis['odds'],
            'confidence' => $analysis['confidence'],
            'season_avg' => round($seasonAvg, 1),
            'recent_avg' => round($recentAvg ?? $seasonAvg, 1),
            'last5_avg' => round($last5Avg ?? $seasonAvg, 1),
            'vs_opponent_avg' => $vsOpponentAvg ? round($vsOpponentAvg, 1) : null,
            'home_away_avg' => $homeAwayAvg ? round($homeAwayAvg, 1) : null,
            'hit_rate_vs_opponent' => $hitRate,
            'edge' => $analysis['edge'],
            'reasoning' => $analysis['reasoning'],
        ];
    }

    /**
     * Calculate edge and generate recommendation
     */
    protected function calculateEdge(
        PlayerProp $prop,
        float $seasonAvg,
        ?float $recentAvg,
        ?float $last5Avg,
        ?float $vsOpponentAvg,
        ?float $homeAwayAvg,
        ?array $hitRate,
        bool $isHome
    ): array {
        $line = (float) $prop->line;
        $diff = $seasonAvg - $line;
        $recentDiff = ($recentAvg ?? $seasonAvg) - $line;
        $last5Diff = ($last5Avg ?? $seasonAvg) - $line;

        // Determine recommendation (Over or Under)
        $recommendation = null;
        $odds = null;
        $confidence = 0;
        $reasoning = [];

        // Strong over indicators
        if ($diff > 0) {
            $recommendation = 'Over';
            $odds = $prop->over_price;
            $confidence = min(100, 50 + ($diff / $line * 100));

            $reasoning[] = sprintf('Season average (%.1f) is %.1f above line', $seasonAvg, $diff);

            if ($recentDiff > $diff) {
                $confidence += 10;
                $reasoning[] = sprintf('Trending up: Recent avg (%.1f) better than season', $recentAvg);
            }

            if ($last5Diff > $recentDiff) {
                $confidence += 5;
                $reasoning[] = sprintf('Hot streak: Last 5 games avg %.1f', $last5Avg);
            }

            // Vs opponent history
            if ($vsOpponentAvg && $vsOpponentAvg > $line) {
                $confidence += 8;
                $reasoning[] = sprintf('Strong vs opponent: %.1f avg in matchups', $vsOpponentAvg);
            }

            // Hit rate vs opponent
            if ($hitRate && $hitRate['games'] >= 3) {
                $hitRatePercent = ($hitRate['hits'] / $hitRate['games']) * 100;
                if ($hitRatePercent >= 65) {
                    $confidence += 10;
                    $reasoning[] = sprintf('Hits over %.1f in %d of %d games vs opponent (%.0f%%)', $line, $hitRate['hits'], $hitRate['games'], $hitRatePercent);
                } elseif ($hitRatePercent >= 50) {
                    $confidence += 5;
                    $reasoning[] = sprintf('Hits over %.1f in %d of %d games vs opponent (%.0f%%)', $line, $hitRate['hits'], $hitRate['games'], $hitRatePercent);
                }
            }

            // Home/away split
            if ($homeAwayAvg && $homeAwayAvg > $seasonAvg) {
                $homeSplit = $isHome ? 'home' : 'away';
                $confidence += 6;
                $reasoning[] = sprintf('Better on %s: %.1f avg vs %.1f season', $homeSplit, $homeAwayAvg, $seasonAvg);
            }
        }
        // Strong under indicators
        elseif ($diff < -1) {
            $recommendation = 'Under';
            $odds = $prop->under_price;
            $confidence = min(100, 50 + (abs($diff) / $line * 100));

            $reasoning[] = sprintf('Season average (%.1f) is %.1f below line', $seasonAvg, abs($diff));

            if ($recentDiff < $diff) {
                $confidence += 10;
                $reasoning[] = sprintf('Trending down: Recent avg (%.1f) worse than season', $recentAvg);
            }

            if ($last5Diff < $recentDiff) {
                $confidence += 5;
                $reasoning[] = sprintf('Cold streak: Last 5 games avg %.1f', $last5Avg);
            }

            // Vs opponent history
            if ($vsOpponentAvg && $vsOpponentAvg < $line) {
                $confidence += 8;
                $reasoning[] = sprintf('Struggles vs opponent: %.1f avg in matchups', $vsOpponentAvg);
            }

            // Hit rate vs opponent (for unders, we want LOW hit rate)
            if ($hitRate && $hitRate['games'] >= 3) {
                $hitRatePercent = ($hitRate['hits'] / $hitRate['games']) * 100;
                if ($hitRatePercent <= 35) {
                    $confidence += 10;
                    $reasoning[] = sprintf('Rarely hits over %.1f vs opponent (%d of %d games, %.0f%%)', $line, $hitRate['hits'], $hitRate['games'], $hitRatePercent);
                } elseif ($hitRatePercent <= 50) {
                    $confidence += 5;
                    $reasoning[] = sprintf('Under in most matchups (%d of %d games, %.0f%%)', $hitRate['hits'], $hitRate['games'], $hitRatePercent);
                }
            }

            // Home/away split
            if ($homeAwayAvg && $homeAwayAvg < $seasonAvg) {
                $homeSplit = $isHome ? 'home' : 'away';
                $confidence += 6;
                $reasoning[] = sprintf('Worse on %s: %.1f avg vs %.1f season', $homeSplit, $homeAwayAvg, $seasonAvg);
            }
        }

        // Adjust confidence based on odds value
        if ($odds && $odds > 0) {
            $confidence += 5; // Plus odds = better value
            $reasoning[] = sprintf('Positive odds (+%d) provide extra value', $odds);
        }

        return [
            'recommendation' => $recommendation,
            'odds' => $odds,
            'confidence' => round(min(100, $confidence)), // Cap at 100%
            'edge' => round($diff, 1),
            'reasoning' => $reasoning,
        ];
    }

    /**
     * Calculate season average for a stat
     */
    protected function calculateSeasonAverage(int $playerId, string $statField, int $minGames, string $playerStatModel): ?float
    {
        $stats = $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->orderBy('id', 'desc')
            ->take(82) // Full season
            ->get();

        if ($stats->count() < $minGames) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate recent N games average
     */
    protected function calculateRecentAverage(int $playerId, string $statField, int $games, string $playerStatModel): ?float
    {
        $stats = $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL'))
            ->orderBy('id', 'desc')
            ->take($games)
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate average vs specific opponent
     */
    protected function calculateVsOpponentAverage(
        int $playerId,
        int $opponentId,
        string $statField,
        string $playerStatModel,
        string $gameModel
    ): ?float {
        $stats = $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', function ($q) use ($opponentId) {
                $q->where('status', 'STATUS_FINAL')
                    ->where(function ($query) use ($opponentId) {
                        $query->where('home_team_id', $opponentId)
                            ->orWhere('away_team_id', $opponentId);
                    });
            })
            ->orderBy('id', 'desc')
            ->take(10) // Last 10 games vs this opponent
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate home or away average
     */
    protected function calculateHomeAwayAverage(
        int $playerId,
        bool $isHome,
        string $statField,
        string $playerStatModel,
        string $gameModel
    ): ?float {
        // Get player's team ID first
        $playerStat = $playerStatModel::where('player_id', $playerId)->first();
        if (! $playerStat || ! $playerStat->player || ! $playerStat->player->team_id) {
            return null;
        }

        $teamId = $playerStat->player->team_id;

        $stats = $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', function ($q) use ($isHome, $teamId) {
                $q->where('status', 'STATUS_FINAL');

                if ($isHome) {
                    $q->where('home_team_id', $teamId);
                } else {
                    $q->where('away_team_id', $teamId);
                }
            })
            ->orderBy('id', 'desc')
            ->take(20) // Last 20 home or away games
            ->get();

        if ($stats->count() < 3) {
            return null;
        }

        return $stats->avg($statField);
    }

    /**
     * Calculate hit rate vs opponent (how often player crosses the line)
     */
    protected function calculateHitRateVsOpponent(
        int $playerId,
        int $opponentId,
        string $statField,
        float $line,
        string $playerStatModel,
        string $gameModel
    ): ?array {
        $stats = $playerStatModel::where('player_id', $playerId)
            ->whereHas('game', function ($q) use ($opponentId) {
                $q->where('status', 'STATUS_FINAL')
                    ->where(function ($query) use ($opponentId) {
                        $query->where('home_team_id', $opponentId)
                            ->orWhere('away_team_id', $opponentId);
                    });
            })
            ->orderBy('id', 'desc')
            ->take(10) // Last 10 games vs this opponent
            ->get();

        if ($stats->isEmpty()) {
            return null;
        }

        $hits = $stats->filter(fn ($stat) => $stat->{$statField} > $line)->count();

        return [
            'hits' => $hits,
            'games' => $stats->count(),
        ];
    }

    /**
     * Find player by fuzzy name matching
     */
    protected function findPlayerByName(string $name, string $playerModel)
    {
        // Try exact match first
        $player = $playerModel::with('team')
            ->where('full_name', 'like', "%{$name}%")
            ->first();

        if ($player) {
            return $player;
        }

        // Try last name match
        $nameParts = explode(' ', $name);
        $lastName = end($nameParts);

        return $playerModel::with('team')
            ->where('last_name', 'like', "%{$lastName}%")
            ->first();
    }

    /**
     * Map prop market to stat field
     */
    protected function getStatFieldForMarket(string $market): ?string
    {
        return match ($market) {
            'player_points' => 'points',
            'player_rebounds' => 'rebounds_total',
            'player_assists' => 'assists',
            'player_threes' => 'three_point_made',
            'player_blocks' => 'blocks',
            'player_steals' => 'steals',
            default => null,
        };
    }

    /**
     * Format market name for display
     */
    protected function formatMarketName(string $market): string
    {
        return match ($market) {
            'player_points' => 'Points',
            'player_rebounds' => 'Rebounds',
            'player_assists' => 'Assists',
            'player_threes' => '3-Pointers Made',
            'player_blocks' => 'Blocks',
            'player_steals' => 'Steals',
            'player_points_rebounds_assists' => 'Points + Rebounds + Assists',
            default => str_replace('_', ' ', ucwords($market, '_')),
        };
    }

    /**
     * Get sport configuration for models and keys
     */
    protected function getSportConfig(string $sport): array
    {
        return match ($sport) {
            'NBA' => [
                'odds_api_key' => 'basketball_nba',
                'game_model' => 'App\\Models\\NBA\\Game',
                'player_model' => 'App\\Models\\NBA\\Player',
                'player_stat_model' => 'App\\Models\\NBA\\PlayerStat',
                'team_model' => 'App\\Models\\NBA\\Team',
            ],
            'MLB' => [
                'odds_api_key' => 'baseball_mlb',
                'game_model' => 'App\\Models\\MLB\\Game',
                'player_model' => 'App\\Models\\MLB\\Player',
                'player_stat_model' => 'App\\Models\\MLB\\PlayerStat',
                'team_model' => 'App\\Models\\MLB\\Team',
            ],
            'NFL' => [
                'odds_api_key' => 'americanfootball_nfl',
                'game_model' => 'App\\Models\\NFL\\Game',
                'player_model' => 'App\\Models\\NFL\\Player',
                'player_stat_model' => 'App\\Models\\NFL\\PlayerStat',
                'team_model' => 'App\\Models\\NFL\\Team',
            ],
            'CBB' => [
                'odds_api_key' => 'basketball_ncaab',
                'game_model' => 'App\\Models\\CBB\\Game',
                'player_model' => 'App\\Models\\CBB\\Player',
                'player_stat_model' => 'App\\Models\\CBB\\PlayerStat',
                'team_model' => 'App\\Models\\CBB\\Team',
            ],
            default => throw new \InvalidArgumentException("Unsupported sport: {$sport}"),
        };
    }

    /**
     * Get available game dates for sport (for filter dropdown)
     */
    public function getAvailableDatesForSport(string $sport): Collection
    {
        $sportConfig = $this->getSportConfig($sport);
        $gameModel = $sportConfig['game_model'];

        return $gameModel::query()
            ->where('status', 'STATUS_SCHEDULED')
            ->whereDate('game_date', '>=', now())
            ->whereHas('playerProps')
            ->selectRaw('DATE(game_date) as date')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($game) {
                $date = is_string($game->date) ? $game->date : $game->date->format('Y-m-d');

                return [
                    'value' => $date,
                    'label' => \Carbon\Carbon::parse($date)->format('l, F j, Y'),
                ];
            });
    }

    /**
     * Get available games/matchups for sport (for filter dropdown)
     */
    public function getAvailableGamesForSport(string $sport, ?string $date = null): Collection
    {
        $sportConfig = $this->getSportConfig($sport);
        $gameModel = $sportConfig['game_model'];

        $query = $gameModel::query()
            ->where('status', 'STATUS_SCHEDULED')
            ->whereDate('game_date', '>=', now())
            ->whereHas('playerProps')
            ->with(['homeTeam', 'awayTeam']);

        if ($date) {
            $query->whereDate('game_date', $date);
        }

        return $query->orderBy('game_date')
            ->orderBy('game_time')
            ->get()
            ->map(function ($game) {
                // Ensure we get just the date part (Y-m-d)
                $gameDate = \Carbon\Carbon::parse($game->game_date)->toDateString();

                // Parse the time separately
                $gameTime = \Carbon\Carbon::parse($game->game_time);

                return [
                    'id' => $game->id,
                    'label' => sprintf(
                        '%s @ %s - %s',
                        $game->awayTeam->abbreviation ?? $game->awayTeam->name,
                        $game->homeTeam->abbreviation ?? $game->homeTeam->name,
                        $gameTime->format('g:i A')
                    ),
                    'date' => $gameDate,
                    'time' => $game->game_time,
                ];
            });
    }
}
