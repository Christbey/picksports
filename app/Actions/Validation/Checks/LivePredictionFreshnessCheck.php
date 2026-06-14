<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LivePredictionFreshnessCheck implements ValidationCheck
{
    private const LIVE_STATUSES = [
        'STATUS_IN_PROGRESS',
        'STATUS_DELAYED',
        'STATUS_HALFTIME',
        'STATUS_END_PERIOD',
        'in_progress',
    ];

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $gamesTable = $tables['games'] ?? null;
        $predictionsTable = $tables['predictions'] ?? null;

        if (
            ! $gamesTable || ! $predictionsTable
            || ! Schema::hasTable($gamesTable)
            || ! Schema::hasTable($predictionsTable)
            || ! Schema::hasColumn($predictionsTable, 'live_updated_at')
            || ! Schema::hasColumn($predictionsTable, 'live_win_probability')
        ) {
            return null;
        }

        $remainingColumn = $this->remainingColumn($predictionsTable, $profile);
        $dates = app(SportsDateWindowService::class);
        $window = $dates->forRange(
            CarbonImmutable::now($dates->timezone())->subDay(),
            CarbonImmutable::now($dates->timezone())->addDay()
        );
        $now = CarbonImmutable::now($dates->timezone());
        $staleAfterMinutes = (int) config('validation.thresholds.live_prediction_freshness.stale_after_minutes', 6);
        $warnPct = (float) config('validation.thresholds.live_prediction_freshness.problem_warn_pct', 0.01);
        $failPct = (float) config('validation.thresholds.live_prediction_freshness.problem_fail_pct', 0.01);

        $games = DB::table($gamesTable)
            ->leftJoin($predictionsTable, "{$predictionsTable}.game_id", '=', "{$gamesTable}.id")
            ->whereIn("{$gamesTable}.status", self::LIVE_STATUSES)
            ->whereDate("{$gamesTable}.game_date", '>=', $window->localStartDate())
            ->whereDate("{$gamesTable}.game_date", '<=', $window->localEndDate())
            ->get([
                "{$gamesTable}.id",
                "{$gamesTable}.espn_event_id",
                "{$gamesTable}.short_name",
                "{$gamesTable}.name",
                "{$gamesTable}.status",
                "{$gamesTable}.game_date",
                "{$gamesTable}.updated_at as game_updated_at",
                "{$predictionsTable}.id as prediction_id",
                "{$predictionsTable}.live_win_probability",
                "{$predictionsTable}.live_predicted_spread",
                "{$predictionsTable}.live_predicted_total",
                "{$predictionsTable}.live_updated_at",
                DB::raw($remainingColumn ? "{$predictionsTable}.{$remainingColumn} as live_remaining" : 'null as live_remaining'),
            ]);

        $problemGameIds = [];
        $missingPredictionCount = 0;
        $missingLiveModelCount = 0;
        $staleLiveModelCount = 0;
        $sampleGames = [];

        foreach ($games as $game) {
            $reasons = [];
            $liveUpdatedAt = $game->live_updated_at ? CarbonImmutable::parse($game->live_updated_at) : null;

            if (! $game->prediction_id) {
                $missingPredictionCount++;
                $reasons[] = 'missing_prediction';
            }

            if (
                $game->live_win_probability === null
                || $game->live_predicted_spread === null
                || $game->live_predicted_total === null
                || ($remainingColumn && $game->live_remaining === null)
            ) {
                $missingLiveModelCount++;
                $reasons[] = 'missing_live_model_fields';
            }

            if (! $liveUpdatedAt || $liveUpdatedAt->lt($now->subMinutes($staleAfterMinutes))) {
                $staleLiveModelCount++;
                $reasons[] = 'stale_live_model';
            }

            if ($reasons !== []) {
                $problemGameIds[] = (int) $game->id;
                $sampleGames[] = [
                    'game_id' => (int) $game->id,
                    'espn_event_id' => $game->espn_event_id,
                    'matchup' => $game->short_name ?: $game->name,
                    'status' => $game->status,
                    'game_date' => $game->game_date ? CarbonImmutable::parse($game->game_date)->toDateString() : null,
                    'game_updated_at' => $game->game_updated_at ? CarbonImmutable::parse($game->game_updated_at)->toIso8601String() : null,
                    'live_updated_at' => $liveUpdatedAt?->toIso8601String(),
                    'reasons' => array_values(array_unique($reasons)),
                ];
            }
        }

        $liveGames = $games->count();
        $problemGames = count(array_unique($problemGameIds));
        $problemPct = $liveGames > 0 ? $problemGames / $liveGames : 0.0;
        $status = 'passing';
        $message = "Live prediction models look fresh across {$liveGames} live game(s).";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$liveGames} live {$sport} game(s) have missing or stale live prediction data.";
        }

        return [
            'check_type' => 'validation_live_prediction_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "espn:sync-{$sport}-games-scoreboard",
            'metadata' => [
                'date_window' => $window->toArray(),
                'live_games' => $liveGames,
                'problem_games' => $problemGames,
                'missing_predictions' => $missingPredictionCount,
                'missing_live_model_fields' => $missingLiveModelCount,
                'stale_live_models' => $staleLiveModelCount,
                'stale_after_minutes' => $staleAfterMinutes,
                'sample_game_ids' => array_slice(array_values(array_unique($problemGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function remainingColumn(string $predictionsTable, array $profile): ?string
    {
        $configured = $profile['live_prediction_remaining_column'] ?? null;
        if (is_string($configured) && Schema::hasColumn($predictionsTable, $configured)) {
            return $configured;
        }

        foreach (['live_seconds_remaining', 'live_outs_remaining'] as $column) {
            if (Schema::hasColumn($predictionsTable, $column)) {
                return $column;
            }
        }

        return null;
    }
}
