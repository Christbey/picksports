<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SeasonStage\SeasonStageService;
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
        $stageContext = app(SeasonStageService::class)->context($sport, null, null, $windowDays);

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

        foreach ($games as $game) {
            $flagged = false;
            $oddsData = $game->odds_data;

            if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
                $missingOddsCount++;
                $flagged = true;
                $missingOddsGameIds[] = (int) $game->getKey();
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

            if ($game->odds_updated_at === null || $game->odds_updated_at->lt(now()->subHours($staleHours))) {
                $staleOddsCount++;
                $flagged = true;
                $staleOddsGameIds[] = (int) $game->getKey();
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->getKey();
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $blockingGameIds = array_unique(array_merge($missingOddsGameIds, $staleOddsGameIds));
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
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
                'sample_missing_odds_game_ids' => array_slice(array_values(array_unique($missingOddsGameIds)), 0, 5),
                'sample_missing_required_market_game_ids' => array_slice(array_values(array_unique($missingRequiredMarketsGameIds)), 0, 5),
                'sample_stale_odds_game_ids' => array_slice(array_values(array_unique($staleOddsGameIds)), 0, 5),
            ],
        ];
    }
}
