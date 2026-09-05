<?php

namespace App\Services\CFB\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CfbMoneylineCalibrationDataset
{
    public const FEATURE_VERSION = 'cfb-prior-season-core-v1';

    public function __construct(
        private readonly CfbCalculator $calculator,
        private readonly CfbCalculationReleaseDefinition $releaseDefinition,
    ) {}

    /**
     * Reconstruct current canonical CFB win probabilities using only values that
     * were available before each game began.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(int $fromSeason, int $toSeason, int $minWeek = 0, int $maxWeek = 4): array
    {
        $rows = DB::table('cfb_games as games')
            ->join('cfb_elo_ratings as home_elo', function ($join): void {
                $join->on('home_elo.game_id', '=', 'games.id')
                    ->on('home_elo.team_id', '=', 'games.home_team_id');
            })
            ->join('cfb_elo_ratings as away_elo', function ($join): void {
                $join->on('away_elo.game_id', '=', 'games.id')
                    ->on('away_elo.team_id', '=', 'games.away_team_id');
            })
            ->join('cfb_team_metrics as home_metrics', function ($join): void {
                $join->on('home_metrics.team_id', '=', 'games.home_team_id')
                    ->whereRaw('home_metrics.season = games.season - 1');
            })
            ->join('cfb_team_metrics as away_metrics', function ($join): void {
                $join->on('away_metrics.team_id', '=', 'games.away_team_id')
                    ->whereRaw('away_metrics.season = games.season - 1');
            })
            ->whereBetween('games.season', [$fromSeason, $toSeason])
            ->whereBetween('games.week', [$minWeek, $maxWeek])
            ->where('games.status', config('cfb.statuses.final', 'STATUS_FINAL'))
            ->whereNotNull('games.home_score')
            ->whereNotNull('games.away_score')
            ->whereColumn('games.home_score', '!=', 'games.away_score')
            ->whereNotNull('home_elo.elo_rating')
            ->whereNotNull('home_elo.elo_change')
            ->whereNotNull('away_elo.elo_rating')
            ->whereNotNull('away_elo.elo_change')
            ->whereRaw('(COALESCE(home_metrics.wins, 0) + COALESCE(home_metrics.losses, 0)) > 0')
            ->whereRaw('(COALESCE(away_metrics.wins, 0) + COALESCE(away_metrics.losses, 0)) > 0')
            ->orderBy('games.season')
            ->orderBy('games.game_date')
            ->orderBy('games.game_time')
            ->orderBy('games.id')
            ->select([
                'games.id as game_id',
                'games.season',
                'games.week',
                'games.game_date',
                'games.game_time',
                'games.home_team_id',
                'games.away_team_id',
                'games.home_score',
                'games.away_score',
                'games.neutral_site',
                'home_elo.elo_rating as home_postgame_elo',
                'home_elo.elo_change as home_elo_change',
                'away_elo.elo_rating as away_postgame_elo',
                'away_elo.elo_change as away_elo_change',
                'home_metrics.season as home_metric_season',
                'away_metrics.season as away_metric_season',
                ...$this->metricSelects('home_metrics', 'home'),
                ...$this->metricSelects('away_metrics', 'away'),
            ])
            ->get();

        return $rows
            ->map(fn (object $row): array => $this->reconstruct((array) $row))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function reconstruct(array $row): array
    {
        $configuration = $this->releaseDefinition->configuration();
        $configurationHash = hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR));
        $capturedAt = CarbonImmutable::parse(
            trim((string) $row['game_date'].' '.(string) ($row['game_time'] ?? '00:00:00')),
            config('app.timezone'),
        );
        $homePregameElo = (float) $row['home_postgame_elo'] - (float) $row['home_elo_change'];
        $awayPregameElo = (float) $row['away_postgame_elo'] - (float) $row['away_elo_change'];

        $snapshot = new EventInputSnapshotData(
            schemaVersion: CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            inputs: [
                'event' => [
                    'neutral_site' => (bool) $row['neutral_site'],
                ],
                'home' => [
                    'elo' => $homePregameElo,
                    'metrics' => $this->metrics($row, 'home'),
                    'injuries' => [],
                ],
                'away' => [
                    'elo' => $awayPregameElo,
                    'metrics' => $this->metrics($row, 'away'),
                    'injuries' => [],
                ],
            ],
            capturedAt: $capturedAt,
            cutoffAt: $capturedAt,
            latestSourceAvailableAt: $capturedAt,
            pregameSafetyStatus: 'verified_reconstruction',
            metadata: [
                'reconstruction_profile' => self::FEATURE_VERSION,
                'injuries_available' => false,
            ],
        );
        $release = new CalculationReleaseData(
            publicId: 'offline-cfb-calibration-reconstruction',
            sport: 'cfb',
            phase: 'pregame',
            calculatorName: CfbCalculationReleaseDefinition::CALCULATOR_NAME,
            releaseType: 'rules',
            semanticVersion: CfbCalculationReleaseDefinition::SEMANTIC_VERSION,
            codeRevision: 'offline-reconstruction',
            configurationHash: $configurationHash,
            inputSchemaVersion: CfbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION,
            configuration: $configuration,
            metadata: ['purpose' => 'moneyline_calibration'],
        );
        $output = $this->calculator->calculate($snapshot, $release);
        $homeMoneyline = collect($output->markets)->first(
            fn ($market): bool => $market->marketType === 'moneyline' && $market->selection === 'home',
        );

        if ($homeMoneyline === null || $homeMoneyline->probability === null) {
            throw new \RuntimeException('CFB calculator did not return a home moneyline probability.');
        }

        return [
            'game_id' => (int) $row['game_id'],
            'season' => (int) $row['season'],
            'week' => (int) $row['week'],
            'game_date' => $capturedAt->toDateString(),
            'home_team_id' => (int) $row['home_team_id'],
            'away_team_id' => (int) $row['away_team_id'],
            'reconstruction_profile' => self::FEATURE_VERSION,
            'pregame_safe' => true,
            'availability_status' => 'verified_reconstruction',
            'home_metric_season' => (int) $row['home_metric_season'],
            'away_metric_season' => (int) $row['away_metric_season'],
            'home_pregame_elo' => round($homePregameElo, 4),
            'away_pregame_elo' => round($awayPregameElo, 4),
            'feature_model_predicted_home_margin' => (float) data_get($output->metadata, 'home_margin'),
            'feature_model_win_probability' => $homeMoneyline->probability,
            'target_home_win' => (int) $row['home_score'] > (int) $row['away_score'] ? 1 : 0,
            'target_home_margin' => (int) $row['home_score'] - (int) $row['away_score'],
        ];
    }

    /** @return list<string> */
    private function metricSelects(string $table, string $prefix): array
    {
        return array_map(
            fn (string $column): string => "{$table}.{$column} as {$prefix}_{$column}",
            $this->metricColumns(),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function metrics(array $row, string $prefix): array
    {
        return collect($this->metricColumns())
            ->mapWithKeys(fn (string $column): array => [$column => $row["{$prefix}_{$column}"] ?? null])
            ->all();
    }

    /** @return list<string> */
    private function metricColumns(): array
    {
        return [
            'wins',
            'losses',
            'points_per_game',
            'points_allowed_per_game',
            'turnover_differential',
            'recent_form_rating',
            'injury_adjusted_team_rating',
            'rest_travel_fatigue',
            'power_rating',
            'fpi',
        ];
    }
}
