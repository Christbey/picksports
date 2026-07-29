<?php

namespace App\Services\ML;

use App\Models\ModelRun;
use App\Models\NBA\Game;
use App\Models\PredictionFeatureSnapshot;
use App\Support\Odds\MarketSpread;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TrustedSnapshotDataset
{
    /**
     * @var array<string, class-string<Model>>
     */
    private array $gameModels = [
        'nba' => Game::class,
        'nfl' => \App\Models\NFL\Game::class,
    ];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(
        string $sport,
        ?int $season = null,
        bool $strictPregame = true,
        ?string $historicalProfile = null,
        int $limit = 0,
        bool $requireBinaryTarget = true,
        ?int $fromSeason = null,
        ?int $toSeason = null,
        ?string $featureVersion = null,
    ): Collection {
        $gameModel = $this->gameModels[$sport] ?? null;
        if ($gameModel === null) {
            throw new \InvalidArgumentException("Trusted snapshot export is not configured for {$sport}.");
        }

        $query = PredictionFeatureSnapshot::query()
            ->where('sport', $sport)
            ->when($strictPregame, fn ($builder) => $builder
                ->where('pregame_safe', true)
                ->whereNotNull('model_run_id')
                ->whereNotNull('game_start_at')
                ->whereNotNull('features_available_at')
                ->whereColumn('features_available_at', '<=', 'game_start_at')
                ->whereIn('availability_status', ['observed_pregame', 'verified_reconstruction']))
            ->when(
                $historicalProfile,
                fn ($builder) => $builder->where('lineage_metadata->historical_profile', $historicalProfile)
            )
            ->when(
                $featureVersion,
                fn ($builder) => $builder->where('feature_version', $featureVersion)
            )
            ->orderBy('generated_at')
            ->orderBy('id');

        $snapshots = $query->get();
        $gameIds = $snapshots->pluck('game_id')->unique()->values();
        $games = $gameModel::query()
            ->whereIn('id', $gameIds)
            ->when($season !== null, fn ($builder) => $builder->where('season', $season))
            ->when($fromSeason !== null, fn ($builder) => $builder->where('season', '>=', $fromSeason))
            ->when($toSeason !== null, fn ($builder) => $builder->where('season', '<=', $toSeason))
            ->where('status', 'STATUS_FINAL')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->get()
            ->keyBy('id');
        $runs = ModelRun::query()
            ->whereIn('id', $snapshots->pluck('model_run_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $rows = $snapshots
            ->filter(fn (PredictionFeatureSnapshot $snapshot): bool => $games->has($snapshot->game_id))
            ->groupBy('game_id')
            ->map(function (Collection $group) use ($games, $runs): array {
                /** @var PredictionFeatureSnapshot $snapshot */
                $snapshot = $group->sortByDesc('generated_at')->first();
                /** @var Model $game */
                $game = $games->get($snapshot->game_id);

                return $this->transform($snapshot, $game, $runs->get($snapshot->model_run_id));
            })
            ->when(
                $requireBinaryTarget,
                fn (Collection $rows): Collection => $rows->filter(
                    fn (array $row): bool => $row['target_home_win'] !== null
                )
            )
            ->sortBy(fn (array $row): array => [$row['game_start_at'], $row['game_id']])
            ->values();

        return $limit > 0 ? $rows->take($limit)->values() : $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(
        PredictionFeatureSnapshot $snapshot,
        Model $game,
        ?ModelRun $run,
    ): array {
        $features = (array) $snapshot->features;
        $outputs = (array) $snapshot->outputs;
        $market = (array) $snapshot->market_context;
        $homeScore = (int) $game->home_score;
        $awayScore = (int) $game->away_score;
        $homeMargin = $homeScore - $awayScore;
        $totalPoints = $homeScore + $awayScore;
        $bookmakerHomeLine = $this->numeric(
            $outputs['bookmaker_home_spread']
                ?? $market['bookmaker_home_line']
                ?? $market['vegas_spread']
                ?? $features['vegas_spread']
                ?? null
        );
        $marketHomeMargin = $bookmakerHomeLine === null
            ? $this->numeric($market['market_home_margin'] ?? null)
            : MarketSpread::bookmakerHomeLineToHomeMargin($bookmakerHomeLine);
        $predictedSpread = $this->numeric($outputs['predicted_spread'] ?? null);
        $predictedTotal = $this->numeric($outputs['predicted_total'] ?? null);
        $winProbability = $this->numeric($outputs['win_probability'] ?? null);
        $targetHash = hash('sha256', json_encode([
            'game_id' => (int) $snapshot->game_id,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
        ], JSON_THROW_ON_ERROR));

        $row = [
            'snapshot_run_id' => $snapshot->snapshot_run_id,
            'model_run_id' => $snapshot->model_run_id,
            'config_hash' => $run?->config_hash,
            'code_version' => $run?->code_version,
            'game_id' => (int) $snapshot->game_id,
            'season' => (int) $game->season,
            'week' => isset($game->week) ? (int) $game->week : null,
            'game_date' => $game->game_date?->toDateString() ?? (string) $game->game_date,
            'game_start_at' => $snapshot->game_start_at?->toIso8601String(),
            'features_available_at' => $snapshot->features_available_at?->toIso8601String(),
            'pregame_safe' => (bool) $snapshot->pregame_safe,
            'availability_status' => $snapshot->availability_status,
            'historical_profile' => data_get($snapshot->lineage_metadata, 'historical_profile'),
            'model_version' => $snapshot->model_version,
            'feature_version' => $snapshot->feature_version,
            'blend_version' => $snapshot->blend_version,
            'feature_hash' => $snapshot->feature_hash,
            'target_hash' => $targetHash,
            'feature_model_predicted_spread' => $predictedSpread,
            'feature_model_predicted_total' => $predictedTotal,
            'feature_model_win_probability' => $winProbability,
            'feature_confidence_score' => $this->numeric($outputs['confidence_score'] ?? null),
            'feature_market_home_spread' => $marketHomeMargin,
            'feature_bookmaker_home_line' => $bookmakerHomeLine,
            'feature_market_total' => $this->numeric($outputs['market_total'] ?? $market['market_total'] ?? null),
            'target_home_margin' => $homeMargin,
            'target_total_points' => $totalPoints,
            'target_home_win' => $homeMargin === 0 ? null : $homeMargin > 0,
            'target_model_spread_error' => $predictedSpread === null ? null : abs($homeMargin - $predictedSpread),
            'target_model_total_error' => $predictedTotal === null ? null : abs($totalPoints - $predictedTotal),
            'target_market_spread_error' => $marketHomeMargin === null ? null : abs($homeMargin - $marketHomeMargin),
        ];

        foreach ($features as $key => $value) {
            $row["feature_{$key}"] = $value;
        }

        if (isset($row['feature_home_elo'], $row['feature_away_elo'])) {
            $row['feature_elo_diff'] = (float) $row['feature_home_elo'] - (float) $row['feature_away_elo'];
        }

        if (isset($row['feature_home_recent_form'], $row['feature_away_recent_form'])) {
            $row['feature_recent_form_diff'] = (float) $row['feature_home_recent_form'] - (float) $row['feature_away_recent_form'];
        }

        if (isset($row['feature_rest_days_home'], $row['feature_rest_days_away'])) {
            $row['feature_rest_day_diff'] = (float) $row['feature_rest_days_home'] - (float) $row['feature_rest_days_away'];
        }

        return $row;
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
