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
            ->whereIn('id', $stageContext->marketReadyGameIds)
            ->get(['id', 'odds_api_event_id', 'odds_data', 'odds_updated_at'])
            ->filter(fn (object $game): bool => $this->hasOddsAnchor($game))
            ->values();

        $marketReadyGames = $games->count();
        $missingProps = 0;
        $staleProps = 0;
        $unscoredProps = 0;
        $freshnessFlaggedGameIds = [];
        $missingGameIds = [];
        $staleGameIds = [];
        $unscoredGameIds = [];

        foreach ($games as $game) {
            $latestFetchedAt = DB::table($propsTable)->where('game_id', $game->id)->max('fetched_at');

            if (! $latestFetchedAt) {
                $missingProps++;
                $freshnessFlaggedGameIds[] = (int) $game->id;
                $missingGameIds[] = (int) $game->id;

                continue;
            }

            if (now()->parse($latestFetchedAt)->lt(now()->subHours($staleHours))) {
                $staleProps++;
                $freshnessFlaggedGameIds[] = (int) $game->id;
                $staleGameIds[] = (int) $game->id;
            }

            if (! $this->hasRecommendationReadyProps($propsTable, (int) $game->id)) {
                $unscoredProps++;
                $unscoredGameIds[] = (int) $game->id;
            }
        }

        $freshnessProblemGames = count(array_unique($freshnessFlaggedGameIds));
        $freshnessProblemPct = $marketReadyGames > 0 ? $freshnessProblemGames / $marketReadyGames : 0.0;
        $status = 'passing';
        $message = "Player props look fresh for {$marketReadyGames} market-ready active games.";

        if ($freshnessProblemGames > 0) {
            $status = $freshnessProblemPct >= $failPct ? 'failing' : ($freshnessProblemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$freshnessProblemGames}/{$marketReadyGames} market-ready active games have missing or stale player props.";
        } elseif ($unscoredProps > 0) {
            $status = 'warning';
            $message = "Player props are fresh for {$marketReadyGames} market-ready active games; {$unscoredProps} game(s) have props without recommendation-ready outputs.";
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
                'active_games' => count($stageContext->activeGameIds),
                'market_ready_games' => count($stageContext->marketReadyGameIds),
                'player_prop_expected_games' => $marketReadyGames,
                'games_missing_player_props' => $missingProps,
                'games_with_stale_player_props' => $staleProps,
                'games_with_unscored_player_props' => $unscoredProps,
                'sample_game_ids' => array_slice(array_values(array_unique($freshnessFlaggedGameIds)), 0, 5),
                'sample_missing_game_ids' => array_slice(array_values(array_unique($missingGameIds)), 0, 5),
                'sample_stale_game_ids' => array_slice(array_values(array_unique($staleGameIds)), 0, 5),
                'sample_unscored_game_ids' => array_slice(array_values(array_unique($unscoredGameIds)), 0, 5),
                'stale_after_hours' => $staleHours,
            ],
        ];
    }

    private function hasOddsAnchor(object $game): bool
    {
        if (filled($game->odds_api_event_id ?? null) || filled($game->odds_updated_at ?? null)) {
            return true;
        }

        $oddsData = $game->odds_data ?? null;
        if (is_string($oddsData)) {
            $decoded = json_decode($oddsData, true);
            $oddsData = is_array($decoded) ? $decoded : null;
        }

        return is_array($oddsData) && ! empty($oddsData['bookmakers']);
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
