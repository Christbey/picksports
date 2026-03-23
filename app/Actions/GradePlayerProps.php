<?php

namespace App\Actions;

use App\Models\NBA\Game;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class GradePlayerProps
{
    /**
     * Sport-specific game/stat mapping used for grading and seasonal filtering.
     *
     * @var array<string, array{game_model:class-string<Model>, player_stat_model:class-string<Model>, player_prop_model:class-string<Model>}>
     */
    private const SPORT_CONFIG = [
        'basketball_nba' => [
            'game_model' => Game::class,
            'player_stat_model' => PlayerStat::class,
            'player_prop_model' => PlayerProp::class,
        ],
        'basketball_ncaab' => [
            'game_model' => \App\Models\CBB\Game::class,
            'player_stat_model' => \App\Models\CBB\PlayerStat::class,
            'player_prop_model' => \App\Models\CBB\PlayerProp::class,
        ],
        'americanfootball_nfl' => [
            'game_model' => \App\Models\NFL\Game::class,
            'player_stat_model' => \App\Models\NFL\PlayerStat::class,
            'player_prop_model' => \App\Models\NFL\PlayerProp::class,
        ],
        'baseball_mlb' => [
            'game_model' => \App\Models\MLB\Game::class,
            'player_stat_model' => \App\Models\MLB\PlayerStat::class,
            'player_prop_model' => \App\Models\MLB\PlayerProp::class,
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
        $props = $this->getUngradedProps($sport, $season);

        return $this->gradePropsCollection($props, $sport, $season);
    }

    public function executeForGame(string $sport, int $gameId): array
    {
        $query = $this->getUngradedPropsQuery($sport);
        if ($query === null) {
            return $this->gradePropsCollection(collect(), $sport);
        }

        $props = $query->where('game_id', $gameId)->get();

        return $this->gradePropsCollection($props, $sport);
    }

    protected function gradePropsCollection(Collection $props, ?string $sport = null, ?int $season = null): array
    {
        // Find ungraded props for completed games with player stats

        if ($props->isEmpty()) {
            return [
                'graded' => 0,
                'total_props' => 0,
                'hit_rate' => 0,
                'avg_error' => 0,
                'brier_score' => null,
                'calibration_sample' => 0,
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

        $brierStats = $sport !== null
            ? $this->calculateBrierScore($sport, $season)
            : ['brier_score' => null, 'sample_size' => 0];

        return [
            'graded' => $graded,
            'total_props' => $graded,
            'hit_rate' => $graded > 0 ? round(($hitCount / $graded) * 100, 1) : 0,
            'avg_error' => $graded > 0 ? round(array_sum($errors) / count($errors), 2) : 0,
            'brier_score' => $brierStats['brier_score'],
            'calibration_sample' => $brierStats['sample_size'],
        ];
    }

    protected function getUngradedProps(string $sport, ?int $season = null): Collection
    {
        $query = $this->getUngradedPropsQuery($sport);
        if ($query === null) {
            return collect();
        }

        if ($season !== null) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', $season));
        }

        return $query->get();
    }

    protected function getUngradedPropsQuery(string $sport)
    {
        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig === null) {
            return null;
        }

        $playerPropModel = $sportConfig['player_prop_model'];

        return $playerPropModel::query()
            ->whereNull('graded_at')
            ->whereHas('game', function ($query) {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');
            });
    }

    protected function getActualValue(Model $prop): ?float
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

    protected function getCalculatedValue(Model $prop): ?float
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

    protected function findPlayerStat(Model $prop)
    {
        $gameId = $prop->game_id;

        $sportConfig = $this->sportConfig($this->sportFromProp($prop));
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
        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig === null) {
            return collect();
        }

        $playerPropModel = $sportConfig['player_prop_model'];

        $query = $playerPropModel::query()
            ->whereNotNull('graded_at');

        if ($season !== null) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', $season));
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
     * @return array{brier_score: float|null, sample_size: int}
     */
    public function calculateBrierScore(string $sport, ?int $season = null): array
    {
        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig === null) {
            return ['brier_score' => null, 'sample_size' => 0];
        }

        $playerPropModel = $sportConfig['player_prop_model'];

        $query = $playerPropModel::query()
            ->whereNotNull('graded_at')
            ->whereNotNull('hit_over')
            ->whereNotNull('predicted_over_probability');

        if ($season !== null) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', $season));
        }

        $props = $query->get();
        if ($props->isEmpty()) {
            return ['brier_score' => null, 'sample_size' => 0];
        }

        $total = 0.0;
        foreach ($props as $prop) {
            $pred = ((float) $prop->predicted_over_probability) / 100;
            $actual = $prop->hit_over ? 1.0 : 0.0;
            $total += (($pred - $actual) ** 2);
        }

        return [
            'brier_score' => round($total / max(1, $props->count()), 4),
            'sample_size' => $props->count(),
        ];
    }

    public function getCalibrationBuckets(string $sport, ?int $season = null): Collection
    {
        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig === null) {
            return collect();
        }

        $playerPropModel = $sportConfig['player_prop_model'];

        $query = $playerPropModel::query()
            ->whereNotNull('graded_at')
            ->whereNotNull('hit_over')
            ->whereNotNull('predicted_over_probability');

        if ($season !== null) {
            $query->whereHas('game', fn ($gameQuery) => $gameQuery->where('season', $season));
        }

        return $query->get()
            ->groupBy(function ($prop) {
                $prob = (float) $prop->predicted_over_probability;
                $bucketStart = (int) floor($prob / 10) * 10;
                $bucketStart = max(0, min(90, $bucketStart));

                return sprintf('%02d-%02d', $bucketStart, $bucketStart + 9);
            })
            ->map(function (Collection $props, string $bucket) {
                $avgPred = (float) $props->avg('predicted_over_probability');
                $actualOverRate = ((float) $props->where('hit_over', true)->count() / max(1, $props->count())) * 100;

                return [
                    'bucket' => $bucket,
                    'sample_size' => $props->count(),
                    'avg_predicted_over' => round($avgPred, 1),
                    'actual_over_rate' => round($actualOverRate, 1),
                    'calibration_gap' => round($actualOverRate - $avgPred, 1),
                ];
            })
            ->sortBy('bucket')
            ->values();
    }

    /**
     * @return array{game_model:class-string<Model>, player_stat_model:class-string<Model>, player_prop_model:class-string<Model>}|null
     */
    protected function sportConfig(string $sport): ?array
    {
        return self::SPORT_CONFIG[$sport] ?? null;
    }

    protected function sportFromProp(Model $prop): string
    {
        return match ($prop::class) {
            PlayerProp::class => 'basketball_nba',
            \App\Models\CBB\PlayerProp::class => 'basketball_ncaab',
            \App\Models\NFL\PlayerProp::class => 'americanfootball_nfl',
            \App\Models\MLB\PlayerProp::class => 'baseball_mlb',
            default => '',
        };
    }
}
