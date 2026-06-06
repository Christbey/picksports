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
            ->whereIn('id', $stageContext->activeGameIds);

        $games = $query->get();

        $totalGames = $games->count();
        $missingOddsCount = 0;
        $missingRequiredMarketsCount = 0;
        $staleOddsCount = 0;
        $flaggedGameIds = [];

        foreach ($games as $game) {
            $flagged = false;
            $oddsData = $game->odds_data;

            if (! is_array($oddsData) || empty($oddsData['bookmakers'])) {
                $missingOddsCount++;
                $flagged = true;
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
                        break;
                    }
                }
            }

            if ($game->odds_updated_at === null || $game->odds_updated_at->lt(now()->subHours($staleHours))) {
                $staleOddsCount++;
                $flagged = true;
            }

            if ($flagged) {
                $flaggedGameIds[] = (int) $game->getKey();
            }
        }

        $problemGames = count(array_unique($flaggedGameIds));
        $problemPct = $totalGames > 0 ? $problemGames / $totalGames : 0.0;
        $warnPct = (float) config('validation.thresholds.odds_completeness.problem_warn_pct', 0.05);
        $failPct = (float) config('validation.thresholds.odds_completeness.problem_fail_pct', 0.20);

        $status = 'passing';
        $message = "Odds coverage looks healthy for {$totalGames} active games.";

        if ($problemGames > 0) {
            $status = $problemPct >= $failPct ? 'failing' : ($problemPct >= $warnPct ? 'warning' : 'passing');
            $message = "{$problemGames}/{$totalGames} active games have missing, incomplete, or stale odds data.";
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
                'active_games' => $totalGames,
                'games_missing_odds' => $missingOddsCount,
                'games_with_missing_required_markets' => $missingRequiredMarketsCount,
                'games_with_stale_odds' => $staleOddsCount,
                'sample_game_ids' => array_slice(array_values(array_unique($flaggedGameIds)), 0, 5),
            ],
        ];
    }
}
