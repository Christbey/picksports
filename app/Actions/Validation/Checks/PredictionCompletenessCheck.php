<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Validation\CfbValidationCoverage;
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
        $canonicalMode = $sport === 'cfb' && $coverage->usesCanonical();
        $query = $gameModel::query()
            ->when(! $canonicalMode, fn ($query) => $query->with('prediction'))
            ->whereIn('id', $stageContext->activeGameIds)
            ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_PRE_GAME', 'STATUS_DELAYED', 'scheduled']);

        $games = $query->get(['id', 'sport_event_id', 'season', 'home_team_id', 'away_team_id']);

        if ($sport === 'cfb') {
            $games = $games->filter(fn ($game) => $coverage->requiredTeamIds($game) !== [])->values();
        }
        $canonical = $sport === 'cfb' && $coverage->usesCanonical() ? $coverage->publishedForGames($games) : null;
        $totalGames = $games->count();
        $missingGames = $games->filter(fn (Model $game) => $canonical !== null ? ! $canonical->has($game->sport_event_id) : $game->prediction === null)->values();
        $staleGames = $canonical !== null ? $games->filter(function ($game) use ($canonical) {
            $prediction = $canonical->get($game->sport_event_id);

            return $prediction && (! $prediction->generated_at || $prediction->generated_at->lt(now()->subHours(24)));
        }) : collect();
        $missingCount = $missingGames->count();
        $missingPct = $totalGames > 0 ? $missingCount / $totalGames : 0.0;

        $warnPct = (float) config('validation.thresholds.prediction_completeness.missing_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.prediction_completeness.missing_fail_pct', 0.20);

        $status = 'passing';
        $message = "Predictions are present for {$totalGames}/{$totalGames} active games in the next {$windowDays} days.";

        if ($missingCount > 0) {
            $status = $missingPct >= $failPct ? 'failing' : 'warning';
            $message = "{$missingCount}/{$totalGames} active games are missing prediction rows.";
        }

        if ($staleGames->isNotEmpty()) {
            $status = 'failing';
            $message .= ' '.$staleGames->count().' published pregame forecast(s) are older than 24 hours.';
        }

        return [
            'check_type' => 'validation_prediction_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $canonical !== null ? 'cfb:run-pregame-pipeline --days-forward=8 --resume' : "{$sport}:generate-predictions",
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'active_games' => $totalGames,
                'games_missing_predictions' => $missingCount,
                'prediction_source' => $canonical !== null ? 'canonical' : 'legacy',
                'games_with_stale_predictions' => $staleGames->count(),
                'sample_stale_game_ids' => $staleGames->take(5)->pluck('id')->all(),
                'sample_game_ids' => $missingGames->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
        ];
    }
}
