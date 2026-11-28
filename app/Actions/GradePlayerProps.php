<?php

namespace App\Actions;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\NBA\Game;
use App\Models\NBA\PlayerProp;
use App\Models\NBA\PlayerStat;
use App\Models\PlayerPropGradeRevision;
use App\Services\BettingRecommendations\CfbPropEligibility;
use App\Services\PlayerProps\PlayerPropGradeRecorder;
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
        'basketball_wnba' => [
            'game_model' => \App\Models\WNBA\Game::class,
            'player_stat_model' => \App\Models\WNBA\PlayerStat::class,
            'player_prop_model' => \App\Models\WNBA\PlayerProp::class,
        ],
        'americanfootball_ncaaf' => [
            'game_model' => \App\Models\CFB\Game::class,
            'player_stat_model' => \App\Models\CFB\PlayerStat::class,
            'player_prop_model' => \App\Models\CFB\PlayerProp::class,
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

    private ?Collection $lockedGradingStats = null;

    public function execute(string $sport, ?int $season = null): array
    {
        $props = $this->getUngradedProps($sport, $season);

        return $this->gradePropsCollection($props, $sport, $season);
    }

    public function executeForGame(string $sport, int $gameId): array
    {
        $query = $this->getUngradedPropsQuery($sport, true);
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
                'decided_props' => 0,
                'pushes' => 0,
                'skipped' => 0,
                'total_props' => 0,
                'hit_rate' => 0,
                'avg_error' => 0,
                'brier_score' => null,
                'calibration_sample' => 0,
            ];
        }

        $graded = 0;
        $hitCount = 0;
        $decided = 0;
        $errors = [];

        $pendingCorrections = 0;
        $conflicts = 0;
        foreach ($props as $prop) {
            $result = $this->reconcileProp($prop, (string) $sport);
            if ($result['status'] === 'entry_changed') {
                $conflicts++;
            }
            if ($result['changed'] && ! $result['graded']) {
                $pendingCorrections++;
            }
            if (! $result['changed'] || ! $result['graded']) {
                continue;
            }
            $hitOver = $result['hit_over'];
            if ($hitOver !== null) {
                $decided++;
            }
            if ($hitOver) {
                $hitCount++;
            }
            $errors[] = $result['error'];
            $graded++;
        }

        $brierStats = $sport !== null
            ? $this->calculateBrierScore($sport, $season)
            : ['brier_score' => null, 'sample_size' => 0];

        return [
            'pending_corrections' => $pendingCorrections,
            'entry_conflicts' => $conflicts,
            'graded' => $graded,
            'decided_props' => $decided,
            'pushes' => $graded - $decided,
            'skipped' => $props->count() - $graded,
            'total_props' => $graded,
            'hit_rate' => $decided > 0 ? round(($hitCount / $decided) * 100, 1) : 0,
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

    protected function getUngradedPropsQuery(string $sport, bool $includeGraded = false)
    {
        $sportConfig = $this->sportConfig($sport);
        if ($sportConfig === null) {
            return null;
        }

        $playerPropModel = $sportConfig['player_prop_model'];

        return $playerPropModel::query()
            ->when(! $includeGraded, fn ($query) => $query->whereNull('graded_at'))
            ->whereHas('game', function ($query) {
                $query->whereIn('status', GameData::finalStatuses())
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');
            });
    }

    public function validateRepairScope(string $sport, array $ids): void
    {
        $config = $this->sportConfig($sport);
        if ($config === null || $ids === [] || count($ids) > 100) {
            throw new \InvalidArgumentException('A supported sport and 1–100 explicit prop IDs are required.');
        }
        $models = [...array_values($config), PlayerPropGradeRevision::class];
        $connections = array_unique(array_map(fn (string $model): string => (new $model)->getConnection()->getName(), $models));
        if (count($connections) !== 1) {
            throw new \InvalidArgumentException('Prop repair requires all source and receipt models on one connection.');
        }
        if (count(array_unique($ids)) !== $config['player_prop_model']::query()->whereKey($ids)->count()) {
            throw new \InvalidArgumentException('All explicit prop IDs must exist in the selected sport before repair.');
        }
    }

    public function reconcileIds(string $sport, array $ids, bool $dryRun = true): array
    {
        $this->validateRepairScope($sport, $ids);
        $config = $this->sportConfig($sport);
        if ($config === null) {
            throw new \InvalidArgumentException('Unsupported prop sport.');
        }
        $results = [];
        foreach ($ids as $id) {
            $prop = $config['player_prop_model']::query()->find($id);
            $results[$id] = $prop === null ? ['status' => 'not_found', 'changed' => false, 'graded' => false]
                : $this->reconcileProp($prop, $sport, $dryRun);
        }

        return $results;
    }

    private function reconcileProp(Model $prop, string $sport, bool $dryRun = false): array
    {
        $config = $this->sportConfig($sport);
        if ($config === null) {
            return ['status' => 'unsupported_sport', 'changed' => false, 'graded' => false];
        }

        return app(PlayerPropGradeRecorder::class)->reconcile($prop, $config, $sport, function (Model $locked, Collection $stats): ?float {
            $this->lockedGradingStats = $stats;
            try {
                return $this->getActualValue($locked);
            } finally {
                $this->lockedGradingStats = null;
            }
        }, $dryRun);
    }

    protected function getActualValue(Model $prop): ?float
    {
        if ($this->sportFromProp($prop) === 'americanfootball_ncaaf') {
            if (! in_array($prop->market, CfbPropEligibility::MARKETS, true)) {
                return null;
            }

            return $this->getNflActualValue($prop);
        }
        if ($this->sportFromProp($prop) === 'americanfootball_nfl') {
            return $this->getNflActualValue($prop);
        }
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

        return is_numeric($playerStat->{$statColumn}) ? (float) $playerStat->{$statColumn} : null;
    }

    protected function getNflActualValue(Model $prop): ?float
    {
        $stat = $this->findPlayerStat($prop);
        if (! $stat) {
            return null;
        }
        $field = match ($prop->market) {
            'player_pass_yds' => 'passing_yards',
            'player_pass_attempts' => 'passing_attempts',
            'player_pass_completions' => 'passing_completions',
            'player_pass_tds' => 'passing_touchdowns',
            'player_pass_interceptions' => 'interceptions_thrown',
            'player_rush_yds' => 'rushing_yards',
            'player_rush_attempts' => 'rushing_attempts',
            'player_receptions' => 'receptions',
            'player_reception_yds' => 'receiving_yards',
            default => null,
        };
        if ($field !== null) {
            // An absent category is not proof of zero or participation.
            return is_numeric($stat->{$field}) ? (float) $stat->{$field} : null;
        }
        if ($prop->market !== 'player_anytime_td') {
            return null;
        }

        $categories = ['rushing_attempts' => 'rushing_touchdowns', 'receptions' => 'receiving_touchdowns',
            'kickoff_returns' => 'kickoff_return_touchdowns', 'punt_returns' => 'punt_return_touchdowns'];
        $participated = (int) $stat->passing_attempts > 0 || (int) $stat->receiving_targets > 0;
        $touchdowns = 0;
        foreach ($categories as $usage => $tds) {
            $participated = $participated || (int) $stat->{$usage} > 0;
            if ((int) $stat->{$usage} > 0 && ! is_numeric($stat->{$tds})) {
                return null;
            }
            $touchdowns += (int) $stat->{$tds};
        }

        // Passing TDs are not scored by the passer. Nonparticipants remain pending.
        return $participated ? (float) $touchdowns : null;
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

        $fields = match ($prop->market) {
            'player_blocks_steals' => ['blocks', 'steals'],
            'player_points_rebounds_assists' => ['points', 'rebounds_total', 'assists'],
            'player_points_rebounds' => ['points', 'rebounds_total'],
            'player_points_assists' => ['points', 'assists'],
            'player_rebounds_assists' => ['rebounds_total', 'assists'],
            default => null,
        };
        if ($fields === null) {
            return null;
        }
        $total = 0.0;
        foreach ($fields as $field) {
            if (! is_numeric($playerStat->{$field})) {
                return null;
            }
            $total += (float) $playerStat->{$field};
        }

        return $total;
    }

    protected function findPlayerStat(Model $prop)
    {
        if ($this->lockedGradingStats !== null) {
            if ($prop->player_id) {
                $matches = $this->lockedGradingStats->where('player_id', $prop->player_id);

                return $matches->count() === 1 ? $matches->first() : null;
            }

            return $this->exactNameMatch($this->lockedGradingStats, $prop->player_name);
        }
        $gameId = $prop->game_id;

        $sportConfig = $this->sportConfig($this->sportFromProp($prop));
        if ($sportConfig === null) {
            return null;
        }
        $playerStatModel = $sportConfig['player_stat_model'];

        // Try exact player_id match first
        if ($prop->player_id) {
            return $playerStatModel::where('game_id', $gameId)
                ->where('player_id', $prop->player_id)
                ->first();
        }

        // Missing links require a unique normalized exact name; never guess another player.
        return $this->fuzzyMatchPlayerStat($playerStatModel, $gameId, $prop->player_name);
    }

    protected function fuzzyMatchPlayerStat(string $playerStatModel, int $gameId, string $playerName)
    {
        // Get all player stats for this game with player relationship
        $stats = $playerStatModel::where('game_id', $gameId)
            ->with('player')
            ->get();

        return $this->exactNameMatch($stats, $playerName);
    }

    private function exactNameMatch(Collection $stats, string $playerName): ?Model
    {
        $normalize = fn (string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower($name));
        $name = $normalize($playerName);
        if ($name === '') {
            return null;
        }
        $matches = $stats->filter(function ($stat) use ($normalize, $name): bool {
            if (! $stat->player) {
                return false;
            }
            $fullName = $stat->player->full_name
                ?? trim(($stat->player->first_name ?? '').' '.($stat->player->last_name ?? ''));

            return $normalize((string) $fullName) === $name;
        });

        return $matches->count() === 1 ? $matches->first() : null;
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
            $decided = $props->whereNotNull('hit_over')->count();
            $hitOver = $props->where('hit_over', true)->count();
            $avgError = $props->avg('error');

            return [
                'market' => $market,
                'total_props' => $total,
                'decided_props' => $decided,
                'pushes' => $total - $decided,
                'hit_over_count' => $hitOver,
                'hit_over_rate' => $decided > 0 ? round(($hitOver / $decided) * 100, 1) : 0,
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
            \App\Models\WNBA\PlayerProp::class => 'basketball_wnba',
            \App\Models\NFL\PlayerProp::class => 'americanfootball_nfl',
            \App\Models\CFB\PlayerProp::class => 'americanfootball_ncaaf',
            \App\Models\MLB\PlayerProp::class => 'baseball_mlb',
            default => '',
        };
    }
}
