<?php

namespace App\Services\MLB\Picks;

use App\Models\MLB\PickCandidate;
use App\Models\MLB\PlayerProp;
use App\Support\MLB\MlbLineScores;
use Illuminate\Support\Collection;

class MlbPickGradingService
{
    public function __construct(
        private readonly BetDecisionRecorder $decisionRecorder,
    ) {}

    public function grade(?int $season = null): int
    {
        return $this->gradeWithReport($season)['graded'];
    }

    /**
     * @return array{graded:int, excluded:int, exclusion_reasons:array<string,int>}
     */
    public function gradeWithReport(?int $season = null): array
    {
        $query = PickCandidate::query()
            ->with('game')
            ->whereNull('graded_at')
            ->whereHas('game', fn ($query) => $query
                ->where('status', config('mlb.statuses.final', 'STATUS_FINAL'))
                ->whereNotNull('home_score')
                ->whereNotNull('away_score'));

        if ($season !== null) {
            $query->where('season', $season);
        }

        $graded = 0;
        $excluded = 0;
        $exclusionReasons = [];

        $query->chunkById(250, function (Collection $rows) use (&$graded, &$excluded, &$exclusionReasons): void {
            foreach ($rows as $candidate) {
                if (! $candidate instanceof PickCandidate || ! $candidate->game) {
                    continue;
                }

                $reasons = $candidate->performanceExclusionReasons();
                if ($reasons !== []) {
                    $excluded++;
                    foreach ($reasons as $reason) {
                        $exclusionReasons[$reason] = ($exclusionReasons[$reason] ?? 0) + 1;
                    }

                    continue;
                }

                $result = $this->result($candidate);
                if ($result === null) {
                    continue;
                }

                $candidate->fill([
                    'result_status' => $result['status'],
                    'status' => $result['status'] === 'win' ? 'graded_win' : ($result['status'] === 'loss' ? 'graded_loss' : 'graded_push'),
                    'result_value' => $result['value'],
                    'result_profit_units' => $result['profit'],
                    'graded_at' => now(),
                ])->save();
                $this->decisionRecorder->settle($candidate);
                $graded++;
            }
        });

        ksort($exclusionReasons);

        return [
            'graded' => $graded,
            'excluded' => $excluded,
            'exclusion_reasons' => $exclusionReasons,
        ];
    }

    /**
     * @return array{status:string,value:float,profit:float}|null
     */
    private function result(PickCandidate $candidate): ?array
    {
        $game = $candidate->game;
        $homeScore = (float) $game->home_score;
        $awayScore = (float) $game->away_score;
        $actualMargin = $homeScore - $awayScore;
        $actualTotal = $homeScore + $awayScore;
        $firstInningHome = $this->inningTotal($game->home_linescores, 1);
        $firstInningAway = $this->inningTotal($game->away_linescores, 1);
        $firstThreeHome = $this->inningTotal($game->home_linescores, 3);
        $firstThreeAway = $this->inningTotal($game->away_linescores, 3);
        $firstFiveHome = $this->inningTotal($game->home_linescores, 5);
        $firstFiveAway = $this->inningTotal($game->away_linescores, 5);

        return match ($candidate->market_type) {
            'moneyline' => $this->binary(($candidate->side === 'home' && $actualMargin > 0) || ($candidate->side === 'away' && $actualMargin < 0), $candidate, $actualMargin),
            'run_line' => $this->lineResult($candidate->side === 'home' ? $actualMargin + (float) $candidate->line : (-1 * $actualMargin) + (float) $candidate->line, $candidate),
            'total' => $this->lineResult($candidate->side === 'over' ? $actualTotal - (float) $candidate->line : (float) $candidate->line - $actualTotal, $candidate),
            'first_inning_total' => $this->periodTotalResult($candidate, $firstInningHome, $firstInningAway),
            'first_3_moneyline' => $this->periodMoneylineResult($candidate, $firstThreeHome, $firstThreeAway),
            'first_3_total' => $this->periodTotalResult($candidate, $firstThreeHome, $firstThreeAway),
            'first_5_moneyline' => $this->periodMoneylineResult($candidate, $firstFiveHome, $firstFiveAway),
            'first_5_run_line' => $this->periodRunLineResult($candidate, $firstFiveHome, $firstFiveAway),
            'first_5_total' => $this->periodTotalResult($candidate, $firstFiveHome, $firstFiveAway),
            'player_prop' => $this->propResult($candidate),
            default => null,
        };
    }

    private function periodMoneylineResult(PickCandidate $candidate, ?float $home, ?float $away): ?array
    {
        if ($home === null || $away === null) {
            return null;
        }

        $margin = $home - $away;
        $selectedMargin = $candidate->side === 'home' ? $margin : -1 * $margin;

        return $this->lineResult($selectedMargin, $candidate);
    }

    private function periodRunLineResult(PickCandidate $candidate, ?float $home, ?float $away): ?array
    {
        if ($home === null || $away === null || $candidate->line === null) {
            return null;
        }

        $margin = $home - $away;
        $selectedMargin = $candidate->side === 'home' ? $margin : -1 * $margin;

        return $this->lineResult($selectedMargin + (float) $candidate->line, $candidate);
    }

    private function periodTotalResult(PickCandidate $candidate, ?float $home, ?float $away): ?array
    {
        if ($home === null || $away === null || $candidate->line === null) {
            return null;
        }

        $total = $home + $away;
        $value = $candidate->side === 'over'
            ? $total - (float) $candidate->line
            : (float) $candidate->line - $total;

        return $this->lineResult($value, $candidate);
    }

    private function inningTotal(mixed $lineScores, int $innings): ?float
    {
        $scores = $this->decodeLineScores($lineScores);
        if (count($scores) < $innings) {
            return null;
        }

        $period = array_slice($scores, 0, $innings);
        if (collect($period)->contains(fn (mixed $score): bool => ! is_numeric($score))) {
            return null;
        }

        return (float) array_sum(array_map('floatval', $period));
    }

    /**
     * @return list<mixed>
     */
    private function decodeLineScores(mixed $lineScores): array
    {
        return MlbLineScores::normalize($lineScores);
    }

    /**
     * @return array{status:string,value:float,profit:float}
     */
    private function binary(bool $won, PickCandidate $candidate, float $value): array
    {
        return [
            'status' => $won ? 'win' : 'loss',
            'value' => $value,
            'profit' => $won ? $this->profit((int) $candidate->price) : -1.0,
        ];
    }

    /**
     * @return array{status:string,value:float,profit:float}
     */
    private function lineResult(float $value, PickCandidate $candidate): array
    {
        if (abs($value) < 0.0001) {
            return ['status' => 'push', 'value' => $value, 'profit' => 0.0];
        }

        return $this->binary($value > 0, $candidate, $value);
    }

    /**
     * @return array{status:string,value:float,profit:float}|null
     */
    private function propResult(PickCandidate $candidate): ?array
    {
        $propId = data_get($candidate->feature_snapshot, 'prop_id');
        $prop = $propId ? PlayerProp::query()->find($propId) : null;
        if (! $prop || $prop->actual_value === null) {
            return null;
        }

        $value = $candidate->side === 'over'
            ? (float) $prop->actual_value - (float) $candidate->line
            : (float) $candidate->line - (float) $prop->actual_value;

        return $this->lineResult($value, $candidate);
    }

    private function profit(int $price): float
    {
        if ($price === 0) {
            return 0.0;
        }

        return $price > 0 ? round($price / 100, 3) : round(100 / abs($price), 3);
    }
}
