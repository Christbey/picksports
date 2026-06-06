<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlayerPropFreshnessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $gamesTable = $tables['games'] ?? null;
        $propsTable = $tables['player_props'] ?? null;
        $gameModel = $profile['models']['game'] ?? null;

        if (! is_string($gamesTable) || ! is_string($propsTable) || ! Schema::hasTable($gamesTable) || ! Schema::hasTable($propsTable)) {
            return null;
        }

        $windowDays = (int) ($profile['window_days'] ?? config('validation.window_days', 7));
        $staleHours = (int) config('validation.thresholds.player_prop_freshness.stale_after_hours', 12);
        $warnPct = (float) config('validation.thresholds.player_prop_freshness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.player_prop_freshness.problem_fail_pct', 0.20);
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);

        $games = DB::table($gamesTable)
            ->whereIn('id', $stageContext->activeGameIds)
            ->get(['id']);

        $activeGames = $games->count();
        $missingProps = 0;
        $staleProps = 0;
        $unscoredProps = 0;
        $flaggedGameIds = [];

        foreach ($games as $game) {
            $latestFetchedAt = DB::table($propsTable)->where('game_id', $game->id)->max('fetched_at');

            if (! $latestFetchedAt) {
                $missingProps++;
                $flaggedGameIds[] = (int) $game->id;

                continue;
            }

            if (now()->parse($latestFetchedAt)->lt(now()->subHours($staleHours))) {
                $staleProps++;
                $flaggedGameIds[] = (int) $game->id;
            }

            if (! $this->hasRecommendationReadyProps($propsTable, (int) $game->id)) {
                $unscoredProps++;
                $flaggedGameIds[] = (int) $game->id;
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $activeGames > 0 ? $problemGames / $activeGames : 0.0;
        $status = 'passing';
        $message = "Player props look fresh for {$activeGames} active games.";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$activeGames} active games have missing, stale, or unscored player props.";
        }

        return [
            'check_type' => 'validation_player_prop_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "sports:analyze-player-props --sport={$sport}",
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'active_games' => $activeGames,
                'games_missing_player_props' => $missingProps,
                'games_with_stale_player_props' => $staleProps,
                'games_with_unscored_player_props' => $unscoredProps,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'stale_after_hours' => $staleHours,
            ],
        ];
    }

    private function hasRecommendationReadyProps(string $propsTable, int $gameId): bool
    {
        $requiredColumns = [
            'recommended_side',
            'confidence_score',
            'predicted_over_probability',
            'market_over_probability',
            'edge_probability',
            'data_quality_score',
        ];

        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn($propsTable, $column)) {
                return false;
            }
        }

        return DB::table($propsTable)
            ->where('game_id', $gameId)
            ->whereNotNull('recommended_side')
            ->whereNotNull('confidence_score')
            ->whereNotNull('predicted_over_probability')
            ->whereNotNull('market_over_probability')
            ->whereNotNull('edge_probability')
            ->whereNotNull('data_quality_score')
            ->exists();
    }
}
