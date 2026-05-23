<?php

namespace App\Console\Commands\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\BetFilterResult;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Services\MLB\MlbBettingSignalService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SnapshotBetFilterCommand extends Command
{
    protected $signature = 'mlb:snapshot-bet-filter
        {--season= : Season to process}
        {--date= : Slate date to snapshot, defaults to next non-final game date}
        {--as-of-date= : Snapshot date, defaults to today}
        {--snapshot : Snapshot upcoming slate decisions}
        {--grade : Grade completed bet-filter decisions}
        {--report : Show stored bet-filter performance summary}
        {--include-disabled-markets : Snapshot run-line and total decisions even when they are not promoted as bets}';

    protected $description = 'Snapshot and grade MLB bet-filter decisions for calibration';

    public function handle(MlbBettingSignalService $betFilter): int
    {
        $season = (int) ($this->option('season') ?: config('mlb.season.default'));
        $asOfDate = Carbon::parse((string) ($this->option('as-of-date') ?: now()->toDateString()));
        $shouldSnapshot = (bool) $this->option('snapshot');
        $shouldGrade = (bool) $this->option('grade');
        $shouldReport = (bool) $this->option('report');

        if (! $shouldSnapshot && ! $shouldGrade && ! $shouldReport) {
            $shouldSnapshot = true;
            $shouldGrade = true;
        }

        if ($shouldSnapshot) {
            $this->snapshot($betFilter, $season, $asOfDate);
        }

        if ($shouldGrade) {
            $this->grade($season);
        }

        if ($shouldReport) {
            $this->report($season);
        }

        return self::SUCCESS;
    }

    private function snapshot(MlbBettingSignalService $betFilter, int $season, Carbon $asOfDate): void
    {
        $slateDate = $this->slateDate($season, $asOfDate);
        if (! $slateDate) {
            $this->warn('No upcoming MLB slate found for snapshot.');

            return;
        }

        $predictions = Prediction::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->where('season', $season)
            ->whereHas('game', function ($query) use ($slateDate): void {
                $query->whereDate('game_date', $slateDate->toDateString())
                    ->where('status', '!=', 'STATUS_FINAL');
            })
            ->get();

        $created = 0;
        $updated = 0;
        $enabledOnly = ! (bool) $this->option('include-disabled-markets');

        foreach ($predictions as $prediction) {
            foreach ($betFilter->betCandidatesForPrediction($prediction, enabledOnly: $enabledOnly, includePasses: true) as $candidate) {
                $payload = $this->payloadForCandidate($prediction, $candidate, $asOfDate);
                $result = BetFilterResult::query()->updateOrCreate(
                    [
                        'prediction_id' => $prediction->id,
                        'market' => $payload['market'],
                        'pick_side' => $payload['pick_side'],
                        'as_of_date' => $payload['as_of_date'],
                    ],
                    $payload
                );

                $result->wasRecentlyCreated ? $created++ : $updated++;
            }
        }

        $this->info(sprintf(
            'Snapshotted MLB bet filter for %s: %d created, %d updated.',
            $slateDate->toDateString(),
            $created,
            $updated
        ));
    }

    private function grade(int $season): void
    {
        $rows = BetFilterResult::query()
            ->with(['game', 'prediction'])
            ->where('season', $season)
            ->whereNull('graded_at')
            ->whereHas('game', function ($query): void {
                $query->where('status', 'STATUS_FINAL')
                    ->whereNotNull('home_score')
                    ->whereNotNull('away_score');
            })
            ->get();

        $graded = 0;
        foreach ($rows as $row) {
            $game = $row->game;
            $prediction = $row->prediction;
            if (! $game || ! $prediction) {
                continue;
            }

            $actualMargin = (float) $game->home_score - (float) $game->away_score;
            $actualTotal = (float) $game->home_score + (float) $game->away_score;

            $row->fill([
                'result_hit' => $this->resultHit($row, $prediction, $actualMargin, $actualTotal),
                'actual_margin' => $actualMargin,
                'actual_total' => $actualTotal,
                'closing_line' => $this->closingLine($row, $game),
                'closing_price' => $this->closingPrice($row, $game),
                'graded_at' => now(),
            ]);
            $row->clv = $this->clv($row);
            $row->save();
            $graded++;
        }

        $this->info("Graded {$graded} MLB bet-filter decision(s).");
    }

    private function report(int $season): void
    {
        $rows = BetFilterResult::query()
            ->where('season', $season)
            ->get()
            ->groupBy(fn (BetFilterResult $row): string => $row->market.'|'.$row->classification);

        if ($rows->isEmpty()) {
            $this->warn('No MLB bet-filter rows found for this season.');

            return;
        }

        $this->table(
            ['Market', 'Class', 'Rows', 'Graded', 'Hit %', 'Avg Score', 'Avg CLV'],
            $rows->map(function ($group, string $key): array {
                [$market, $classification] = explode('|', $key);
                $graded = $group->whereNotNull('graded_at');

                return [
                    $market,
                    $classification,
                    (string) $group->count(),
                    (string) $graded->count(),
                    $graded->isNotEmpty()
                        ? number_format($graded->where('result_hit', true)->count() / $graded->count() * 100, 1).'%'
                        : 'n/a',
                    number_format((float) $group->avg('score'), 1),
                    $graded->whereNotNull('clv')->isNotEmpty()
                        ? $this->signed((float) $graded->whereNotNull('clv')->avg('clv'), 3)
                        : 'n/a',
                ];
            })->values()->all()
        );
    }

    private function slateDate(int $season, Carbon $asOfDate): ?Carbon
    {
        if ($this->option('date')) {
            return Carbon::parse((string) $this->option('date'));
        }

        $date = Game::query()
            ->where('season', $season)
            ->where('status', '!=', 'STATUS_FINAL')
            ->whereDate('game_date', '>=', $asOfDate->toDateString())
            ->orderBy('game_date')
            ->value('game_date');

        return $date ? Carbon::parse($date) : null;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    private function payloadForCandidate(Prediction $prediction, array $candidate, Carbon $asOfDate): array
    {
        $game = $prediction->game;

        return [
            'game_id' => (int) $prediction->game_id,
            'prediction_id' => (int) $prediction->id,
            'season' => (int) $prediction->season,
            'season_type' => (string) $prediction->season_type,
            'game_date' => $game?->game_date?->toDateString(),
            'as_of_date' => $asOfDate->toDateString(),
            'filter_version' => MlbBettingSignalService::FILTER_VERSION,
            'market' => (string) $candidate['type'],
            'pick_side' => (string) $candidate['pick_side'],
            'team_id' => isset($candidate['team_id']) ? (int) $candidate['team_id'] : null,
            'team_name' => $candidate['team_name'] ?? null,
            'score' => (int) $candidate['score'],
            'classification' => (string) $candidate['classification'],
            'model_probability' => $candidate['model_probability'] ?? null,
            'market_price' => $candidate['market_price'] ?? null,
            'market_implied_probability' => $candidate['market_implied_probability'] ?? null,
            'probability_edge' => $candidate['probability_edge'] ?? null,
            'edge_runs' => $candidate['edge_runs'] ?? null,
            'model_line' => $candidate['model_line'] ?? null,
            'market_line' => $candidate['market_line'] ?? null,
            'reason_codes' => $candidate['reason_codes'] ?? [],
            'risk_flags' => $candidate['risk_flags'] ?? [],
            'metadata' => [
                'matchup' => $candidate['matchup'] ?? null,
                'no_bet_reason' => $candidate['no_bet_reason'] ?? null,
                'confidence_score' => $candidate['confidence_score'] ?? null,
            ],
        ];
    }

    private function resultHit(BetFilterResult $row, Prediction $prediction, float $actualMargin, float $actualTotal): ?bool
    {
        return match ($row->market) {
            'moneyline' => ($row->pick_side === 'home') === ($actualMargin > 0),
            'run_line' => $prediction->vegas_spread !== null
                ? (($row->pick_side === 'home') === ($actualMargin > (-1 * (float) $prediction->vegas_spread)))
                : null,
            'total' => $row->market_line !== null
                ? (($row->pick_side === 'over') === ($actualTotal > (float) $row->market_line))
                : null,
            default => null,
        };
    }

    private function closingLine(BetFilterResult $row, Game $game): ?float
    {
        $oddsData = $this->closingOddsData($game);

        return match ($row->market) {
            'run_line' => $this->homeSpreadLine($oddsData, $game) !== null ? -1 * $this->homeSpreadLine($oddsData, $game) : null,
            'total' => $this->totalLine($oddsData),
            default => null,
        };
    }

    private function closingPrice(BetFilterResult $row, Game $game): ?int
    {
        $oddsData = $this->closingOddsData($game);

        return match ($row->market) {
            'moneyline' => $this->moneylinePrice($oddsData, $game, (string) $row->pick_side),
            default => null,
        };
    }

    private function clv(BetFilterResult $row): ?float
    {
        if ($row->market === 'moneyline' && $row->market_price !== null && $row->closing_price !== null) {
            $open = $this->americanToImplied((int) $row->market_price);
            $close = $this->americanToImplied((int) $row->closing_price);

            return $open !== null && $close !== null ? round($close - $open, 4) : null;
        }

        if (in_array($row->market, ['run_line', 'total'], true) && $row->market_line !== null && $row->closing_line !== null) {
            return match ($row->pick_side) {
                'home', 'over' => round((float) $row->closing_line - (float) $row->market_line, 3),
                'away', 'under' => round((float) $row->market_line - (float) $row->closing_line, 3),
                default => null,
            };
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function closingOddsData(Game $game): ?array
    {
        $snapshot = GameOddsSnapshot::query()
            ->where('sport', 'mlb')
            ->where('game_table', 'mlb_games')
            ->where('game_id', $game->id)
            ->where('captured_at', '<=', $game->game_date?->copy()->endOfDay() ?? now())
            ->orderByDesc('captured_at')
            ->first();

        return $snapshot?->odds_data ?? $game->odds_data;
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     */
    private function homeSpreadLine(?array $oddsData, Game $game): ?float
    {
        $homeNames = array_filter([
            (string) ($game->homeTeam?->display_name ?? ''),
            trim(((string) ($game->homeTeam?->location ?? '')).' '.((string) ($game->homeTeam?->name ?? ''))),
            (string) ($game->homeTeam?->name ?? ''),
            (string) ($game->homeTeam?->abbreviation ?? ''),
        ]);

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'spreads') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (! is_numeric($outcome['point'] ?? null)) {
                        continue;
                    }

                    $outcomeName = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($outcome['name'] ?? '')) ?? '');
                    foreach ($homeNames as $homeName) {
                        if ($outcomeName === strtolower(preg_replace('/[^a-z0-9]+/i', '', $homeName) ?? '')) {
                            return (float) $outcome['point'];
                        }
                    }

                    if ($homeNames === []) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     */
    private function totalLine(?array $oddsData): ?float
    {
        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'totals') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    if (($outcome['name'] ?? null) === 'Over' && is_numeric($outcome['point'] ?? null)) {
                        return (float) $outcome['point'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $oddsData
     */
    private function moneylinePrice(?array $oddsData, Game $game, string $pickSide): ?int
    {
        $team = $pickSide === 'home' ? $game->homeTeam : $game->awayTeam;
        $teamNames = array_filter([
            (string) ($team?->display_name ?? ''),
            trim(((string) ($team?->location ?? '')).' '.((string) ($team?->name ?? ''))),
            (string) ($team?->name ?? ''),
            (string) ($team?->abbreviation ?? ''),
        ]);

        foreach (($oddsData['bookmakers'] ?? []) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (($market['key'] ?? null) !== 'h2h') {
                    continue;
                }

                foreach (($market['outcomes'] ?? []) as $outcome) {
                    $outcomeName = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($outcome['name'] ?? '')) ?? '');
                    foreach ($teamNames as $teamName) {
                        if ($outcomeName === strtolower(preg_replace('/[^a-z0-9]+/i', '', $teamName) ?? '') && is_numeric($outcome['price'] ?? null)) {
                            return (int) $outcome['price'];
                        }
                    }
                }
            }
        }

        return null;
    }

    private function americanToImplied(int $price): ?float
    {
        if ($price === 0) {
            return null;
        }

        return $price > 0
            ? 100 / ($price + 100)
            : abs($price) / (abs($price) + 100);
    }

    private function signed(float $value, int $precision): string
    {
        $formatted = number_format($value, $precision);

        return $value > 0 ? '+'.$formatted : $formatted;
    }
}
