<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
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
        $activeStatuses = ['STATUS_SCHEDULED', 'STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'];

        $games = $gameModel::query()
            ->with(['homeTeam', 'awayTeam'])
            ->whereDate('game_date', '>=', now()->startOfDay()->toDateString())
            ->whereDate('game_date', '<=', now()->copy()->addDays($windowDays)->toDateString())
            ->whereIn('status', $activeStatuses)
            ->get();

        $totalGames = $games->count();
        $missingGames = $games->filter(fn (Model $game) => $game->prediction === null)->values();
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

        return [
            'check_type' => 'validation_prediction_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "{$sport}:generate-predictions",
            'metadata' => [
                'window_days' => $windowDays,
                'active_games' => $totalGames,
                'games_missing_predictions' => $missingCount,
                'sample_game_ids' => $missingGames->take(5)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
        ];
    }
}
