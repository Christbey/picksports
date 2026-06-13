<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
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
        $softAvailabilityHours = (int) config('validation.thresholds.player_prop_freshness.soft_availability_hours', 24);
        $expectedAvailabilityHours = (int) config('validation.thresholds.player_prop_freshness.expected_availability_hours', 6);
        $warnPct = (float) config('validation.thresholds.player_prop_freshness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.player_prop_freshness.problem_fail_pct', 0.20);
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);
        $dates = app(SportsDateWindowService::class);

        $games = DB::table($gamesTable)
            ->whereIn('id', $stageContext->marketReadyGameIds)
            ->get(['id', 'espn_event_id', 'short_name', 'name', 'game_date', 'game_time', 'odds_api_event_id', 'odds_data', 'odds_updated_at'])
            ->filter(fn (object $game): bool => $this->hasOddsAnchor($game))
            ->values();

        $marketReadyGames = $games->count();
        $missingProps = 0;
        $providerUnavailableFar = 0;
        $providerUnavailableSoft = 0;
        $providerUnavailableExpected = 0;
        $staleProps = 0;
        $unscoredProps = 0;
        $freshnessFlaggedGameIds = [];
        $missingGameIds = [];
        $expectedMissingGameIds = [];
        $staleGameIds = [];
        $unscoredGameIds = [];
        $sampleGames = [];

        foreach ($games as $game) {
            $reasons = [];
            $latestFetchedAt = DB::table($propsTable)->where('game_id', $game->id)->max('fetched_at');
            $hoursUntilStart = $this->hoursUntilStart($dates, $game);

            if (! $latestFetchedAt) {
                $missingProps++;
                $missingGameIds[] = (int) $game->id;
                $availabilityBucket = $this->availabilityBucket($hoursUntilStart, $softAvailabilityHours, $expectedAvailabilityHours);
                $reasons[] = match ($availabilityBucket) {
                    'expected' => 'provider_missing_player_props_near_start',
                    'soft' => 'provider_missing_player_props_soft_window',
                    default => 'provider_missing_player_props_early_window',
                };

                if ($availabilityBucket === 'expected') {
                    $providerUnavailableExpected++;
                    $freshnessFlaggedGameIds[] = (int) $game->id;
                    $expectedMissingGameIds[] = (int) $game->id;
                } elseif ($availabilityBucket === 'soft') {
                    $providerUnavailableSoft++;
                } else {
                    $providerUnavailableFar++;
                }

                $sampleGames[] = $this->sampleGame($game, $reasons, null, $hoursUntilStart, $availabilityBucket);

                continue;
            }

            if (now()->parse($latestFetchedAt)->lt(now()->subHours($staleHours))) {
                $staleProps++;
                $freshnessFlaggedGameIds[] = (int) $game->id;
                $staleGameIds[] = (int) $game->id;
                $reasons[] = 'stale_player_props';
            }

            if (! $this->hasRecommendationReadyProps($propsTable, (int) $game->id)) {
                $unscoredProps++;
                $unscoredGameIds[] = (int) $game->id;
                $reasons[] = 'unscored_player_props';
            }

            if ($reasons !== []) {
                $sampleGames[] = $this->sampleGame($game, $reasons, $latestFetchedAt, $hoursUntilStart, 'available');
            }
        }

        $freshnessProblemGames = count(array_unique($freshnessFlaggedGameIds));
        $staleProblemPct = $marketReadyGames > 0 ? count(array_unique($staleGameIds)) / $marketReadyGames : 0.0;
        $expectedMissingPct = $marketReadyGames > 0 ? count(array_unique($expectedMissingGameIds)) / $marketReadyGames : 0.0;
        $status = 'passing';
        $message = "Player props look fresh for {$marketReadyGames} market-ready active games.";

        if ($staleProps > 0) {
            $status = $staleProblemPct >= $failPct ? 'failing' : ($staleProblemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$staleProps}/{$marketReadyGames} market-ready active games have stale player props.";
        } elseif ($providerUnavailableExpected > 0) {
            $status = $expectedMissingPct >= $warnPct ? 'warning' : 'passing';
            $message = "{$providerUnavailableExpected}/{$marketReadyGames} market-ready active games are inside the expected prop window but provider props are unavailable.";
        } elseif ($freshnessProblemGames > 0) {
            $status = 'warning';
            $message = "{$freshnessProblemGames}/{$marketReadyGames} market-ready active games do not currently have player props from the provider.";
        } elseif ($unscoredProps > 0) {
            $status = 'warning';
            $message = "Player props are fresh for {$marketReadyGames} market-ready active games; {$unscoredProps} game(s) have props without recommendation-ready outputs.";
        } elseif ($missingProps > 0) {
            $message = "Player props are recommendation-ready where available; {$missingProps}/{$marketReadyGames} market-ready active games are outside the expected prop window or provider has not posted props.";
        }

        return [
            'check_type' => 'validation_player_prop_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $this->recommendedAction($sport, $stageContext->season, $unscoredProps),
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'active_games' => count($stageContext->activeGameIds),
                'market_ready_games' => count($stageContext->marketReadyGameIds),
                'player_prop_expected_games' => $marketReadyGames,
                'games_missing_player_props' => $missingProps,
                'provider_unavailable_far_games' => $providerUnavailableFar,
                'provider_unavailable_soft_window_games' => $providerUnavailableSoft,
                'provider_unavailable_expected_window_games' => $providerUnavailableExpected,
                'games_with_stale_player_props' => $staleProps,
                'games_with_unscored_player_props' => $unscoredProps,
                'sample_game_ids' => array_slice(array_values(array_unique($freshnessFlaggedGameIds)), 0, 5),
                'sample_missing_game_ids' => array_slice(array_values(array_unique($missingGameIds)), 0, 5),
                'sample_expected_missing_game_ids' => array_slice(array_values(array_unique($expectedMissingGameIds)), 0, 5),
                'sample_stale_game_ids' => array_slice(array_values(array_unique($staleGameIds)), 0, 5),
                'sample_unscored_game_ids' => array_slice(array_values(array_unique($unscoredGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'stale_after_hours' => $staleHours,
                'soft_availability_hours' => $softAvailabilityHours,
                'expected_availability_hours' => $expectedAvailabilityHours,
            ],
        ];
    }

    private function recommendedAction(string $sport, ?int $season, int $unscoredProps): string
    {
        $command = "sports:analyze-player-props --sport={$sport}";

        if ($season !== null) {
            $command .= " --season={$season}";
        }

        if ($unscoredProps > 0) {
            $command .= ' --only-missing';
        }

        return $command;
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

    /**
     * @param  array<int, string>  $reasons
     * @return array<string, mixed>
     */
    private function sampleGame(object $game, array $reasons, mixed $latestFetchedAt, ?float $hoursUntilStart, string $availabilityBucket): array
    {
        return [
            'game_id' => (int) $game->id,
            'espn_event_id' => $game->espn_event_id,
            'odds_api_event_id' => $game->odds_api_event_id,
            'matchup' => $game->short_name ?: $game->name,
            'game_date' => $game->game_date ? now()->parse($game->game_date)->toDateString() : null,
            'hours_until_start' => $hoursUntilStart !== null ? round($hoursUntilStart, 2) : null,
            'prop_availability_bucket' => $availabilityBucket,
            'latest_player_props_fetched_at' => $latestFetchedAt ? now()->parse($latestFetchedAt)->toIso8601String() : null,
            'reasons' => $reasons,
        ];
    }

    private function hoursUntilStart(SportsDateWindowService $dates, object $game): ?float
    {
        $start = $dates->gameDateTimeUtc($game->game_date ?? null, $game->game_time ?? null);

        return $start?->diffInRealHours(now()->utc(), false) * -1;
    }

    private function availabilityBucket(?float $hoursUntilStart, int $softAvailabilityHours, int $expectedAvailabilityHours): string
    {
        if ($hoursUntilStart === null || $hoursUntilStart > $softAvailabilityHours) {
            return 'early';
        }

        if ($hoursUntilStart > $expectedAvailabilityHours) {
            return 'soft';
        }

        return 'expected';
    }
}
