<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class OddsCompletenessCheck implements ValidationCheck
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
        $staleHours = (int) config('validation.thresholds.odds_completeness.stale_after_hours', 8);
        $softAvailabilityHours = (int) config('validation.thresholds.odds_completeness.soft_availability_hours', 24);
        $expectedAvailabilityHours = (int) config('validation.thresholds.odds_completeness.expected_availability_hours', 6);
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);
        $dates = app(SportsDateWindowService::class);

        $query = $gameModel::query()
            ->whereIn('id', $stageContext->marketReadyGameIds);

        $games = $query->get();

        $totalGames = $games->count();
        $missingOddsCount = 0;
        $missingRequiredMarketsCount = 0;
        $staleOddsCount = 0;
        $flaggedGameIds = [];
        $missingOddsGameIds = [];
        $missingRequiredMarketsGameIds = [];
        $staleOddsGameIds = [];
        $expectedMissingOddsGameIds = [];
        $providerUnavailableFarGames = 0;
        $providerUnavailableSoftWindowGames = 0;
        $providerUnavailableExpectedWindowGames = 0;
        $sampleGames = [];

        foreach ($games as $game) {
            $flagged = false;
            $oddsData = $game->odds_data;
            $hoursUntilStart = $this->hoursUntilStart($dates, $game);
            $availabilityBucket = $this->availabilityBucket($hoursUntilStart, $softAvailabilityHours, $expectedAvailabilityHours);

            if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
                $missingOddsCount++;
                $missingOddsGameIds[] = (int) $game->getKey();

                if ($availabilityBucket === 'expected') {
                    $providerUnavailableExpectedWindowGames++;
                    $expectedMissingOddsGameIds[] = (int) $game->getKey();
                    $flagged = true;
                } elseif ($availabilityBucket === 'soft') {
                    $providerUnavailableSoftWindowGames++;
                } else {
                    $providerUnavailableFarGames++;
                }
            } else {
                $marketKeys = collect($oddsData['bookmakers'])
                    ->flatMap(fn ($bookmaker) => is_array($bookmaker) ? ($bookmaker['markets'] ?? []) : [])
                    ->map(fn ($market) => is_array($market) ? ($market['key'] ?? null) : null)
                    ->filter()
                    ->unique();

                foreach (['spreads', 'totals', 'h2h'] as $requiredMarket) {
                    if (! $marketKeys->contains($requiredMarket)) {
                        $missingRequiredMarketsCount++;
                        $flagged = true;
                        $missingRequiredMarketsGameIds[] = (int) $game->getKey();
                        break;
                    }
                }
            }

            if ($game->odds_updated_at !== null && $game->odds_updated_at->lt(now()->subHours($staleHours))) {
                $staleOddsCount++;
                $flagged = true;
                $staleOddsGameIds[] = (int) $game->getKey();
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->getKey();
            }

            if ($flagged || ! is_array($oddsData) || empty($oddsData['bookmakers'])) {
                $sampleGames[] = $this->sampleGame($game, $hoursUntilStart, $availabilityBucket, $flagged);
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $blockingGameIds = array_unique(array_merge($expectedMissingOddsGameIds, $staleOddsGameIds));
        $blockingProblemGames = count($blockingGameIds);
        $blockingProblemPct = $totalGames > 0 ? $blockingProblemGames / $totalGames : 0.0;
        $warnPct = (float) config('validation.thresholds.odds_completeness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.odds_completeness.problem_fail_pct', 0.20);
        $missingOrStaleFailPct = (float) config('validation.thresholds.odds_completeness.missing_or_stale_fail_pct', $failPct);

        $status = 'passing';
        $message = "Odds coverage looks healthy for {$totalGames} market-ready active games.";

        if ($blockingProblemGames > 0) {
            $status = $blockingProblemPct >= $missingOrStaleFailPct ? 'failing' : ($blockingProblemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$blockingProblemGames}/{$totalGames} market-ready active games have missing or stale odds data.";
        } elseif ($problemGames > 0) {
            $status = 'warning';
            $message = "{$problemGames}/{$totalGames} market-ready active games have odds but are missing one or more secondary markets.";
        } elseif ($missingOddsCount > 0) {
            $message = "Odds coverage is recommendation-ready where available; {$missingOddsCount}/{$totalGames} market-ready active games are outside the expected odds window or provider has not posted odds.";
        }

        return [
            'check_type' => 'validation_odds_completeness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "{$sport}:sync-odds",
            'metadata' => [
                'window_days' => $windowDays,
                'season_stage' => $stageContext->toArray(),
                'active_games' => count($stageContext->activeGameIds),
                'market_ready_games' => $totalGames,
                'blocking_odds_problem_games' => $blockingProblemGames,
                'missing_or_stale_fail_pct' => $missingOrStaleFailPct,
                'games_missing_odds' => $missingOddsCount,
                'games_with_missing_required_markets' => $missingRequiredMarketsCount,
                'games_with_stale_odds' => $staleOddsCount,
                'provider_unavailable_far_odds' => $providerUnavailableFarGames,
                'provider_unavailable_soft_window_odds' => $providerUnavailableSoftWindowGames,
                'provider_unavailable_expected_window_odds' => $providerUnavailableExpectedWindowGames,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_missing_odds_game_ids' => array_slice(array_values(array_unique($missingOddsGameIds)), 0, 5),
                'sample_expected_missing_odds_game_ids' => array_slice(array_values(array_unique($expectedMissingOddsGameIds)), 0, 5),
                'sample_missing_required_market_game_ids' => array_slice(array_values(array_unique($missingRequiredMarketsGameIds)), 0, 5),
                'sample_stale_odds_game_ids' => array_slice(array_values(array_unique($staleOddsGameIds)), 0, 5),
                'sample_games' => array_slice($sampleGames, 0, 5),
                'soft_availability_hours' => $softAvailabilityHours,
                'expected_availability_hours' => $expectedAvailabilityHours,
            ],
        ];
    }

    private function hoursUntilStart(SportsDateWindowService $dates, Model $game): ?float
    {
        $start = $dates->gameDateTimeUtc(
            $game->getAttribute('game_date'),
            $game->getAttribute('game_time'),
        );

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

    /**
     * @return array<string, mixed>
     */
    private function sampleGame(Model $game, ?float $hoursUntilStart, string $availabilityBucket, bool $flagged): array
    {
        $gameDate = $game->getAttribute('game_date');
        $oddsUpdatedAt = $game->getAttribute('odds_updated_at');

        return [
            'game_id' => (int) $game->getKey(),
            'espn_event_id' => $game->getAttribute('espn_event_id'),
            'matchup' => $game->getAttribute('short_name') ?: $game->getAttribute('name'),
            'game_date' => $gameDate ? CarbonImmutable::parse($gameDate)->toDateString() : null,
            'hours_until_start' => $hoursUntilStart !== null ? round($hoursUntilStart, 2) : null,
            'odds_availability_bucket' => $availabilityBucket,
            'odds_api_event_id' => $game->getAttribute('odds_api_event_id'),
            'odds_updated_at' => $oddsUpdatedAt ? CarbonImmutable::parse($oddsUpdatedAt)->toIso8601String() : null,
            'flagged' => $flagged,
        ];
    }
}
