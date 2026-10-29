<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContextResolver;
use App\Services\Predictions\LivePredictionStore;
use App\Services\Predictions\PredictionDeliverySource;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
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
        $context = app(SportContextResolver::class)->find($sport);
        $gameModel = $context?->models['game'] ?? null;
        if (! $gamesTable || $gameModel === null || ! Schema::hasTable($gamesTable)) {
            return null;
        }
        $remainingColumn = $profile['live_prediction_remaining_column'] ?? ($sport === 'mlb' ? 'live_outs_remaining' : 'live_seconds_remaining');
        $dates = app(SportsDateWindowService::class);
        $window = $dates->forRange(
            CarbonImmutable::now($dates->timezone())->subDay(),
            CarbonImmutable::now($dates->timezone())->addDay()
        );
        $now = CarbonImmutable::now($dates->timezone());
        $staleAfterMinutes = (int) config('validation.thresholds.live_prediction_freshness.stale_after_minutes', 6);
        $warnPct = (float) config('validation.thresholds.live_prediction_freshness.problem_warn_pct', 0.01);
        $failPct = (float) config('validation.thresholds.live_prediction_freshness.problem_fail_pct', 0.01);

        $games = $gameModel::query()
            ->whereIn('status', self::LIVE_STATUSES)
            ->whereDate('game_date', '>=', $window->localStartDate())
            ->whereDate('game_date', '<=', $window->localEndDate())->get();
        $store = app(LivePredictionStore::class);
        $store->preload($games);
        if (app(PredictionDeliverySource::class)->usesCanonical($sport)) {
            $predictionEvents = app(CanonicalSportPredictionQuery::class)->queryForSport($sport)
                ->whereIn("{$sport}_games.id", $games->pluck('id'))->pluck('predictions.sport_event_id')->all();
            $withPrediction = $games->whereIn('sport_event_id', $predictionEvents)->pluck('id')->all();
        } else {
            $predictionClass = $context->models['prediction'];
            $withPrediction = $predictionClass::query()->whereIn('game_id', $games->pluck('id'))->pluck('game_id')->all();
        }

        $problemGameIds = [];
        $missingPredictionCount = 0;
        $missingLiveModelCount = 0;
        $staleLiveModelCount = 0;
        $sampleGames = [];

        foreach ($games as $game) {
            $reasons = [];
            $live = $store->fields($game);
            $liveUpdatedAt = $live['live_updated_at'] ? CarbonImmutable::parse($live['live_updated_at']) : null;

            if (! in_array($game->id, $withPrediction, true)) {
                $missingPredictionCount++;
                $reasons[] = 'missing_prediction';
            }

            if (
                $live['live_win_probability'] === null
                || $live['live_predicted_spread'] === null
                || $live['live_predicted_total'] === null
                || ($remainingColumn && $live[$remainingColumn] === null)
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
                    'game_updated_at' => $game->updated_at ? CarbonImmutable::parse($game->updated_at)->toIso8601String() : null,
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
}
