<?php

namespace App\Services\Api\V2;

use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MlbDailyPickPageData
{
    /**
     * @param  Collection<int,PickCandidate>  $candidates
     * @param  Collection<int,PickCandidate>  $topPicks
     * @param  array{slate_games:int,priced_games:int,first_inning_priced_games:int,first_3_priced_games:int,first_5_priced_games:int,game_ids:list<int>}  $slate
     * @return array<string,mixed>
     */
    public function summary(Collection $candidates, Collection $topPicks, array $slate): array
    {
        $assessments = $candidates->map(fn (PickCandidate $candidate): ?bool => $this->storedSafetyAssessment($candidate));
        $safeRows = $assessments->filter(fn ($safe): bool => $safe === true);
        $assessmentComplete = $candidates->isNotEmpty() && ! $assessments->containsStrict(null);
        $marketAgreementRows = $candidates->filter(fn ($candidate): bool => in_array('model_market_agrees', (array) ($candidate->reason_codes ?? []), true));

        return [
            'slate_games' => $slate['slate_games'],
            'priced_games' => $slate['priced_games'],
            'first_inning_priced_games' => $slate['first_inning_priced_games'],
            'first_3_priced_games' => $slate['first_3_priced_games'],
            'first_5_priced_games' => $slate['first_5_priced_games'],
            'candidate_count' => $candidates->count(),
            'top_candidate_count' => $topPicks->count(),
            'tracking_count' => $candidates->where('is_tracking_only', true)->count(),
            'public_promoted_count' => $candidates->where('is_public', true)->count(),
            'avg_top_score' => $topPicks->isNotEmpty() ? round((float) $topPicks->avg('score'), 1) : null,
            'top_candidate_score' => $topPicks->max('score'),
            'pregame_safe_rate' => $assessmentComplete ? round($safeRows->count() / $candidates->count(), 3) : null,
            'market_agreement_rate' => $candidates->isNotEmpty() ? round($marketAgreementRows->count() / $candidates->count(), 3) : null,
        ];
    }

    /** A stored assessment is historical evidence, not a current observation receipt. */
    private function storedSafetyAssessment(PickCandidate $candidate): ?bool
    {
        if (array_intersect((array) ($candidate->risk_flags ?? []), [
            'point_in_time_unsafe', 'live_only_or_postgame_unsafe', 'pitcher_changed',
            'starter_changed_after_prediction', 'odds_after_first_pitch',
            'postponed_suspended_cancelled', 'missing_game_start_time', 'pregame_signal_safety_block',
        ]) !== []) {
            return false;
        }
        $layer = data_get($candidate->feature_snapshot, 'signal_layer');
        if (! is_array($layer) || ($layer['version'] ?? null) !== 'mlb_signal_driver_v1'
            || ! is_bool($layer['pregame_safe'] ?? null)) {
            return null;
        }

        return $layer['pregame_safe'];
    }

    /**
     * @return array{slate_games:int,priced_games:int,first_inning_priced_games:int,first_3_priced_games:int,first_5_priced_games:int,game_ids:list<int>}
     */
    public function slateSummary(CarbonInterface|string $date, ?int $season, SportsDateWindowService $dates): array
    {
        $window = $dates->forDate($date);
        $season ??= (int) config('mlb.season.default');

        $games = Game::query()
            ->where('season', $season)
            ->where('status', '!=', config('mlb.statuses.final', 'STATUS_FINAL'))
            ->tap(fn ($query) => $dates->applyGameDateWindow($query, $window))
            ->get(['id', 'odds_data']);

        return [
            'slate_games' => $games->count(),
            'priced_games' => $games->filter(fn (Game $game): bool => ! empty(data_get($game->odds_data, 'bookmakers')))->count(),
            'first_inning_priced_games' => $games->filter(fn (Game $game): bool => $this->hasMarket($game, [
                'totals_1st_1_innings',
                'totals_1st_1',
            ]))->count(),
            'first_3_priced_games' => $games->filter(fn (Game $game): bool => $this->hasMarket($game, [
                'h2h_1st_3_innings',
                'h2h_1st_3',
                'totals_1st_3_innings',
                'totals_1st_3',
            ]))->count(),
            'first_5_priced_games' => $games->filter(fn (Game $game): bool => $this->hasMarket($game, [
                'h2h_1st_5_innings',
                'h2h_1st_5',
                'spreads_1st_5_innings',
                'spreads_1st_5',
                'totals_1st_5_innings',
                'totals_1st_5',
            ]))->count(),
            'game_ids' => $games->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        ];
    }

    /**
     * @param  list<string>  $marketKeys
     */
    public function hasMarket(Game $game, array $marketKeys): bool
    {
        foreach ((array) data_get($game->odds_data, 'bookmakers', []) as $bookmaker) {
            foreach ((array) data_get($bookmaker, 'markets', []) as $market) {
                if (in_array((string) data_get($market, 'key'), $marketKeys, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  Collection<int,PickCandidate>  $candidates
     * @return array<string,int>
     */
    public function marketCounts(Collection $candidates): array
    {
        $counts = $candidates->countBy('market_type')->all();

        return [
            'all' => $candidates->count(),
            'moneyline' => (int) ($counts['moneyline'] ?? 0),
            'run_line' => (int) ($counts['run_line'] ?? 0),
            'total' => (int) ($counts['total'] ?? 0),
            'first_inning' => (int) ($counts['first_inning_total'] ?? 0),
            'first_5' => (int) (($counts['first_5_moneyline'] ?? 0) + ($counts['first_5_run_line'] ?? 0) + ($counts['first_5_total'] ?? 0)),
            'first_3' => (int) (($counts['first_3_moneyline'] ?? 0) + ($counts['first_3_total'] ?? 0)),
            'player_prop' => (int) ($counts['player_prop'] ?? 0),
            'tracking' => $candidates->where('is_tracking_only', true)->count(),
            'validated' => $candidates->where('is_public', true)->count(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function performanceSummary(?int $season): array
    {
        $query = PickCandidate::query()->whereNotNull('graded_at');
        if ($season !== null) {
            $query->where('season', $season);
        }

        $last30 = (clone $query)
            ->where('generated_at', '>=', now()->subDays(30))
            ->get([
                'id',
                'market_type',
                'score',
                'generated_at',
                'result_status',
                'result_profit_units',
            ]);
        $last7 = $last30->filter(fn ($candidate): bool => $candidate->generated_at?->gte(now()->subDays(7)) ?? false);

        return [
            'last_7_days' => $this->record($last7),
            'last_30_days' => $this->record($last30),
            'by_market' => $last30->groupBy('market_type')->map(fn (Collection $rows): array => $this->record($rows))->all(),
            'sample_warning' => $last30->count() < (int) config('mlb.picks.validation.min_rows', 100)
                ? 'Sample too small for public betting validation.'
                : null,
            'mode_note' => 'Research tracking, not public betting validation.',
        ];
    }

    /**
     * @param  Collection<int,PickCandidate>  $rows
     * @return array<string,mixed>|null
     */
    public function record(Collection $rows): ?array
    {
        if ($rows->isEmpty()) {
            return null;
        }

        $wins = $rows->where('result_status', 'win')->count();
        $losses = $rows->where('result_status', 'loss')->count();
        $pushes = $rows->where('result_status', 'push')->count();
        $decisions = $wins + $losses;

        return [
            'rows' => $rows->count(),
            'wins' => $wins,
            'losses' => $losses,
            'pushes' => $pushes,
            'hit_rate' => $decisions > 0 ? round($wins / $decisions, 3) : null,
            'units' => round((float) $rows->sum('result_profit_units'), 3),
            'avg_score' => round((float) $rows->avg('score'), 1),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    public function boardHealth(array $summary): array
    {
        $slateGames = (int) ($summary['slate_games'] ?? 0);
        $pricedGames = (int) ($summary['priced_games'] ?? 0);
        $candidateCount = (int) ($summary['candidate_count'] ?? 0);
        $topCandidateCount = (int) ($summary['top_candidate_count'] ?? 0);
        $slateCoverage = $slateGames > 0 ? round($pricedGames / $slateGames, 3) : null;

        $status = match (true) {
            $slateGames === 0 => 'no_slate',
            $pricedGames === 0 => 'needs_odds',
            $candidateCount === 0 => 'pending_scan',
            $topCandidateCount === 0 => 'no_force_picks',
            default => 'tracking_ready',
        };

        $score = $summary['avg_top_score'] ?? null;
        if ($score === null && $slateCoverage !== null && $candidateCount > 0) {
            $score = round(($slateCoverage * 40) + min($candidateCount, 20), 1);
        }

        return [
            'status' => $status,
            'score' => $score,
            'slate_coverage' => $slateCoverage,
            'pregame_safe_rate' => $summary['pregame_safe_rate'] ?? null,
            'market_agreement_rate' => $summary['market_agreement_rate'] ?? null,
            'message' => match ($status) {
                'no_slate' => 'No MLB slate found for this date.',
                'needs_odds' => 'Market pricing is not available for this slate yet.',
                'pending_scan' => 'Run the daily pick scan to generate tracking candidates.',
                'no_force_picks' => 'No candidates cleared today\'s quality threshold.',
                default => 'Board is populated for tracking review.',
            },
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  Collection<int,PickCandidate>  $topPicks
     * @return list<array<string,string>>
     */
    public function achievements(array $summary, Collection $topPicks): array
    {
        $achievements = [];

        if (($summary['pregame_safe_rate'] ?? 0) >= 0.9 && $topPicks->isNotEmpty()) {
            $achievements[] = [
                'key' => 'clean_slate',
                'label' => 'Stored Safety Checks',
                'description' => 'At least 90% of stored candidate assessments passed when generated; current safety has not been revalidated.',
            ];
        }

        if (($summary['market_agreement_rate'] ?? 0) >= 0.6) {
            $achievements[] = [
                'key' => 'consensus_board',
                'label' => 'Consensus Board',
                'description' => 'Most candidates agree with market-aware context.',
            ];
        }

        if ($topPicks->count() >= (int) config('mlb.picks.daily.target_count', 3)) {
            $achievements[] = [
                'key' => 'high_signal_day',
                'label' => 'High Signal Day',
                'description' => 'The board found enough candidates above the tracking threshold.',
            ];
        } else {
            $achievements[] = [
                'key' => 'no_force_picks',
                'label' => 'No Force Picks',
                'description' => 'The board did not force weak candidates into the top list.',
            ];
        }

        return $achievements;
    }
}
