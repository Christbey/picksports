<?php

namespace App\Actions;

use App\Models\PlayerProp;
use Illuminate\Support\Collection;

class GradePlayerProps
{
    /**
     * Market to stat column mapping
     */
    protected array $marketToStatMap = [
        'player_points' => 'points',
        'player_rebounds' => 'rebounds_total',
        'player_assists' => 'assists',
        'player_threes' => 'three_point_made',
        'player_blocks' => 'blocks',
        'player_steals' => 'steals',
        'player_turnovers' => 'turnovers',
        'player_blocks_steals' => null, // Calculated: blocks + steals
        'player_points_rebounds_assists' => null, // Calculated: points + rebounds + assists
        'player_points_rebounds' => null, // Calculated: points + rebounds
        'player_points_assists' => null, // Calculated: points + assists
        'player_rebounds_assists' => null, // Calculated: rebounds + assists
    ];

    public function execute(string $sport, ?int $season = null): array
    {
        // Find ungraded props for completed games with player stats
        $props = $this->getUngradedProps($sport, $season);

        if ($props->isEmpty()) {
            return [
                'graded' => 0,
                'total_props' => 0,
                'hit_rate' => 0,
                'avg_error' => 0,
            ];
        }

        $graded = 0;
        $hitCount = 0;
        $errors = [];

        foreach ($props as $prop) {
            $actualValue = $this->getActualValue($prop);

            if ($actualValue === null) {
                continue; // Skip if we can't find the actual stat
            }

            $hitOver = $actualValue > $prop->line;
            $error = abs($actualValue - $prop->line);

            // Update prop with grading results
            $prop->update([
                'actual_value' => $actualValue,
                'hit_over' => $hitOver,
                'error' => $error,
                'graded_at' => now(),
            ]);

            if ($hitOver) {
                $hitCount++;
            }

            $errors[] = $error;
            $graded++;
        }

        return [
            'graded' => $graded,
            'total_props' => $graded,
            'hit_rate' => $graded > 0 ? round(($hitCount / $graded) * 100, 1) : 0,
            'avg_error' => $graded > 0 ? round(array_sum($errors) / count($errors), 2) : 0,
        ];
    }

    protected function getUngradedProps(string $sport, ?int $season = null): Collection
    {
        $query = PlayerProp::query()
            ->whereNull('graded_at')
            ->where('sport', $sport);

        // Join with the game table based on sport
        if ($sport === 'basketball_nba') {
            $query->join('nba_games', function ($join) {
                $join->on('player_props.gameable_id', '=', 'nba_games.id')
                    ->where('player_props.gameable_type', '=', 'App\\Models\\NBA\\Game');
            })
                ->where('nba_games.status', 'STATUS_FINAL')
                ->whereNotNull('nba_games.home_score')
                ->whereNotNull('nba_games.away_score');

            if ($season) {
                $query->where('nba_games.season', $season);
            }
        } elseif ($sport === 'basketball_ncaab') {
            $query->join('cbb_games', function ($join) {
                $join->on('player_props.gameable_id', '=', 'cbb_games.id')
                    ->where('player_props.gameable_type', '=', 'App\\Models\\CBB\\Game');
            })
                ->where('cbb_games.status', 'STATUS_FINAL')
                ->whereNotNull('cbb_games.home_score')
                ->whereNotNull('cbb_games.away_score');

            if ($season) {
                $query->where('cbb_games.season', $season);
            }
        } elseif ($sport === 'americanfootball_nfl') {
            $query->join('nfl_games', function ($join) {
                $join->on('player_props.gameable_id', '=', 'nfl_games.id')
                    ->where('player_props.gameable_type', '=', 'App\\Models\\NFL\\Game');
            })
                ->where('nfl_games.status', 'STATUS_FINAL')
                ->whereNotNull('nfl_games.home_score')
                ->whereNotNull('nfl_games.away_score');

            if ($season) {
                $query->where('nfl_games.season', $season);
            }
        } elseif ($sport === 'baseball_mlb') {
            $query->join('mlb_games', function ($join) {
                $join->on('player_props.gameable_id', '=', 'mlb_games.id')
                    ->where('player_props.gameable_type', '=', 'App\\Models\\MLB\\Game');
            })
                ->where('mlb_games.status', 'STATUS_FINAL')
                ->whereNotNull('mlb_games.home_score')
                ->whereNotNull('mlb_games.away_score');

            if ($season) {
                $query->where('mlb_games.season', $season);
            }
        }

        return $query->select('player_props.*')->get();
    }

    protected function getActualValue(PlayerProp $prop): ?float
    {
        // Get the stat column name from market
        $statColumn = $this->getStatColumn($prop->market);

        if ($statColumn === null) {
            // Handle calculated markets
            return $this->getCalculatedValue($prop);
        }

        // Find the player stat for this game
        $playerStat = $this->findPlayerStat($prop);

        if (! $playerStat) {
            return null;
        }

        return (float) $playerStat->{$statColumn};
    }

    protected function getStatColumn(string $market): ?string
    {
        return $this->marketToStatMap[$market] ?? null;
    }

    protected function getCalculatedValue(PlayerProp $prop): ?float
    {
        $playerStat = $this->findPlayerStat($prop);

        if (! $playerStat) {
            return null;
        }

        return match ($prop->market) {
            'player_blocks_steals' => $playerStat->blocks + $playerStat->steals,
            'player_points_rebounds_assists' => $playerStat->points + $playerStat->rebounds_total + $playerStat->assists,
            'player_points_rebounds' => $playerStat->points + $playerStat->rebounds_total,
            'player_points_assists' => $playerStat->points + $playerStat->assists,
            'player_rebounds_assists' => $playerStat->rebounds_total + $playerStat->assists,
            default => null,
        };
    }

    protected function findPlayerStat(PlayerProp $prop)
    {
        $gameableType = $prop->gameable_type;
        $gameId = $prop->gameable_id;

        // Determine the player stats model based on sport
        if ($prop->sport === 'basketball_nba') {
            $playerStatModel = \App\Models\NBA\PlayerStat::class;
        } elseif ($prop->sport === 'basketball_ncaab') {
            $playerStatModel = \App\Models\CBB\PlayerStat::class;
        } elseif ($prop->sport === 'americanfootball_nfl') {
            $playerStatModel = \App\Models\NFL\PlayerStat::class;
        } elseif ($prop->sport === 'baseball_mlb') {
            $playerStatModel = \App\Models\MLB\PlayerStat::class;
        } else {
            return null;
        }

        // Try exact player_id match first
        if ($prop->player_id) {
            $stat = $playerStatModel::where('game_id', $gameId)
                ->where('player_id', $prop->player_id)
                ->first();

            if ($stat) {
                return $stat;
            }
        }

        // Fallback: fuzzy match on player name
        return $this->fuzzyMatchPlayerStat($playerStatModel, $gameId, $prop->player_name);
    }

    protected function fuzzyMatchPlayerStat(string $playerStatModel, int $gameId, string $playerName)
    {
        // Get all player stats for this game with player relationship
        $stats = $playerStatModel::where('game_id', $gameId)
            ->with('player')
            ->get();

        $bestMatch = null;
        $highestSimilarity = 0;

        foreach ($stats as $stat) {
            if (! $stat->player) {
                continue;
            }

            $fullName = trim(($stat->player->first_name ?? '').' '.($stat->player->last_name ?? ''));
            similar_text(strtolower($playerName), strtolower($fullName), $similarity);

            if ($similarity > $highestSimilarity && $similarity >= 70) {
                $highestSimilarity = $similarity;
                $bestMatch = $stat;
            }
        }

        return $bestMatch;
    }

    public function getStatsByMarket(string $sport, ?int $season = null): Collection
    {
        $query = PlayerProp::query()
            ->whereNotNull('graded_at')
            ->where('sport', $sport);

        if ($season) {
            // Join with game table to filter by season
            if ($sport === 'basketball_nba') {
                $query->join('nba_games', function ($join) {
                    $join->on('player_props.gameable_id', '=', 'nba_games.id')
                        ->where('player_props.gameable_type', '=', 'App\\Models\\NBA\\Game');
                })
                    ->where('nba_games.season', $season)
                    ->select('player_props.*');
            }
            // Add other sports as needed
        }

        return $query->get()->groupBy('market')->map(function ($props, $market) {
            $total = $props->count();
            $hitOver = $props->where('hit_over', true)->count();
            $avgError = $props->avg('error');

            return [
                'market' => $market,
                'total_props' => $total,
                'hit_over_count' => $hitOver,
                'hit_over_rate' => $total > 0 ? round(($hitOver / $total) * 100, 1) : 0,
                'avg_error' => round($avgError, 2),
            ];
        })->sortByDesc('total_props')->values();
    }
}
