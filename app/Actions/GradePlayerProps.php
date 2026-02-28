<?php

namespace App\Actions;

use App\Models\PlayerProp;
use Illuminate\Support\Collection;

class GradePlayerProps
{
    /**
     * Sport-specific game/stat mapping used for grading and seasonal filtering.
     *
     * @var array<string, array{games_table:string, gameable_type:class-string, player_stat_model:class-string}>
     */
    private const SPORT_CONFIG = [
        'basketball_nba' => [
            'games_table' => 'nba_games',
            'gameable_type' => \App\Models\NBA\Game::class,
            'player_stat_model' => \App\Models\NBA\PlayerStat::class,
        ],
        'basketball_ncaab' => [
            'games_table' => 'cbb_games',
            'gameable_type' => \App\Models\CBB\Game::class,
            'player_stat_model' => \App\Models\CBB\PlayerStat::class,
        ],
        'americanfootball_nfl' => [
            'games_table' => 'nfl_games',
            'gameable_type' => \App\Models\NFL\Game::class,
            'player_stat_model' => \App\Models\NFL\PlayerStat::class,
        ],
        'baseball_mlb' => [
            'games_table' => 'mlb_games',
            'gameable_type' => \App\Models\MLB\Game::class,
            'player_stat_model' => \App\Models\MLB\PlayerStat::class,
        ],
    ];

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

        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig !== null) {
            $gamesTable = $sportConfig['games_table'];

            $query->join($gamesTable, function ($join) use ($gamesTable, $sportConfig) {
                $join->on('player_props.gameable_id', '=', $gamesTable.'.id')
                    ->where('player_props.gameable_type', '=', $sportConfig['gameable_type']);
            })
                ->where($gamesTable.'.status', 'STATUS_FINAL')
                ->whereNotNull($gamesTable.'.home_score')
                ->whereNotNull($gamesTable.'.away_score');

            if ($season !== null) {
                $query->where($gamesTable.'.season', $season);
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
        $gameId = $prop->gameable_id;

        $sportConfig = $this->sportConfig($prop->sport);
        if ($sportConfig === null) {
            return null;
        }
        $playerStatModel = $sportConfig['player_stat_model'];

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

        if ($season !== null) {
            $sportConfig = $this->sportConfig($sport);
            if ($sportConfig !== null) {
                $gamesTable = $sportConfig['games_table'];

                $query->join($gamesTable, function ($join) use ($gamesTable, $sportConfig) {
                    $join->on('player_props.gameable_id', '=', $gamesTable.'.id')
                        ->where('player_props.gameable_type', '=', $sportConfig['gameable_type']);
                })
                    ->where($gamesTable.'.season', $season)
                    ->select('player_props.*');
            }
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

    /**
     * @return array{games_table:string, gameable_type:class-string, player_stat_model:class-string}|null
     */
    protected function sportConfig(string $sport): ?array
    {
        return self::SPORT_CONFIG[$sport] ?? null;
    }
}
