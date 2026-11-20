<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Validation\CfbValidationCoverage;
use App\Services\Validation\PredictionValidationCoverage;
use Illuminate\Database\Eloquent\Model;

class PredictionCompletenessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $gameModel = $profile['models']['game'] ?? null;

        if (! is_string($gameModel) || ! class_exists($gameModel)) {
            return null;
        }

        /** @var class-string<Model> $gameModel */
        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);

        $coverage = app(CfbValidationCoverage::class);
        $predictionCoverage = app(PredictionValidationCoverage::class);
        $canonicalMode = $predictionCoverage->usesCanonical($sport);
        $query = $gameModel::query()
            ->whereIn('id', $stageContext->activeGameIds)
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED', 'scheduled']);

        $games = $query->get(['id', 'sport_event_id', 'season', 'home_team_id', 'away_team_id']);

        if ($sport === 'cfb') {
            $games = $games->filter(fn ($game) => $coverage->requiredTeamIds($game) !== [])->values();
        }
        $consideredGames = $games->count();
        $expectedHolds = $predictionCoverage->expectedHolds($sport, $games);
        $games = $games->reject(fn (Model $game): bool => $expectedHolds->has($game->id))->values();
        $published = $predictionCoverage->forGames($sport, $games);
        $totalGames = $games->count();
        $missingGames = $games->filter(fn (Model $game) => ! $published->has($predictionCoverage->key($sport, $game)))->values();
        $staleGames = $canonicalMode ? $games->filter(function ($game) use ($published, $predictionCoverage, $sport) {
            $prediction = $published->get($predictionCoverage->key($sport, $game));

            return $prediction && (! $prediction->generated_at || $prediction->generated_at->lt(now()->subHours(24)));
        }) : collect();
        $missingCount = $missingGames->count();
        $missingPct = $totalGames > 0 ? $missingCount / $totalGames : 0.0;

        $warnPct = (float) config('validation.thresholds.prediction_completeness.missing_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.prediction_completeness.missing_fail_pct', 0.20);

        $status = 'passing';
        $message = "Predictions are present for {$totalGames}/{$totalGames} forecast-eligible active games in the next {$windowDays} days.";

        if ($missingCount > 0) {
            $status = $missingPct >= $failPct ? 'failing' : 'warning';
            $message = "{$missingCount}/{$totalGames} active games are missing prediction rows.";
        }

        if ($staleGames->isNotEmpty()) {
            $status = 'failing';
            $message .= ' '.$staleGames->count().' published pregame forecast(s) are older than 24 hours.';
        }

        if ($expectedHolds->isNotEmpty()) {
            $message .= ' '.$expectedHolds->count().' placeholder matchup(s) remain expected forecast holds.';
        }

        return [
            'check_type' => 'validation_prediction_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $canonicalMode
                ? ($sport === 'cfb' ? 'cfb:run-pregame-pipeline --days-forward=8 --resume' : "{$sport}:generate-canonical-predictions")
                : "{$sport}:generate-predictions",
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'active_games' => $totalGames,
                'scheduled_games_considered' => $consideredGames,
                'expected_forecast_holds' => $expectedHolds->count(),
                'forecast_hold_reasons' => $expectedHolds->countBy()->all(),
                'sample_expected_hold_game_ids' => $expectedHolds->keys()->take(5)->all(),
                'games_missing_predictions' => $missingCount,
                'prediction_source' => $canonicalMode ? 'canonical' : 'legacy',
                'games_with_stale_predictions' => $staleGames->count(),
                'sample_stale_game_ids' => $staleGames->take(5)->pluck('id')->all(),
                'sample_game_ids' => $missingGames->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
        ];
    }
}
