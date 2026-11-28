<?php

namespace App\Services\MLB\Picks;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\PlayerProp;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\MLB\MlbLineScores;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
                ->whereIn('status', GameData::finalStatuses())
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

                $receipt = $this->gradeCandidate($candidate->id);
                if ($receipt !== null) {
                    $graded++;
                }
            }
        });

        ksort($exclusionReasons);

        return [
            'graded' => $graded,
            'excluded' => $excluded,
            'exclusion_reasons' => $exclusionReasons,
        ];
    }

    /** Explicit targeted recovery/correction; ordinary season grading still selects only ungraded rows. */
    public function gradeCandidate(int $candidateId): ?BetSettlement
    {
        return DB::transaction(function () use ($candidateId): ?BetSettlement {
            // This lookup supplies only an immutable FK; all outcome inputs use current locking reads.
            $gameId = PickCandidate::query()->whereKey($candidateId)->value('game_id');
            $game = $gameId === null ? null : Game::query()->lockForUpdate()->find($gameId);
            $candidate = PickCandidate::query()->lockForUpdate()->find($candidateId);
            if ($game === null || $candidate === null || (int) $candidate->game_id !== (int) $game->id
                || ! in_array($game->status, GameData::finalStatuses(), true)
                || $game->home_score === null || $game->away_score === null) {
                return null;
            }
            $candidate->setRelation('game', $game);
            if ($candidate->performanceExclusionReasons() !== []) {
                return null;
            }
            $decisions = BetDecision::query()->where('source_table', $candidate->getTable())
                ->where('source_id', $candidate->id)->where('sport', 'mlb')->orderBy('id')->limit(2)->lockForUpdate()->get();
            if ($decisions->count() !== 1) {
                return null;
            }
            $decision = $decisions->first();
            if ($decision === null || (int) $decision->game_id !== (int) $game->id
                || $decision->game_table !== $game->getTable()
                || $decision->market_type !== $candidate->market_type || $decision->market_key !== $candidate->market_key
                || $decision->side !== $candidate->side || $decision->price != $candidate->price
                || ($decision->line === null) !== ($candidate->line === null)
                || ($decision->line !== null && (float) $decision->line !== (float) $candidate->line)) {
                return null;
            }
            if (! in_array($candidate->side, in_array($candidate->market_type,
                ['total', 'first_inning_total', 'first_3_total', 'first_5_total', 'player_prop'], true)
                ? ['over', 'under'] : ['home', 'away'], true)) {
                return null;
            }
            if (! in_array($candidate->market_type, ['moneyline', 'first_3_moneyline', 'first_5_moneyline'], true)
                && ! is_numeric($candidate->line)) {
                return null;
            }
            $prop = null;
            if ($candidate->market_type === 'player_prop') {
                $propId = data_get($candidate->feature_snapshot, 'prop_id');
                $prop = $propId ? PlayerProp::query()->lockForUpdate()->find($propId) : null;
                if ($prop === null || $prop->market !== $candidate->market_key || (int) $prop->game_id !== (int) $game->id
                    || ($candidate->player_id !== null && (int) $prop->player_id !== (int) $candidate->player_id)) {
                    return null;
                }
                $candidate->setRelation('gradingProp', $prop);
            }
            $result = $this->result($candidate);
            if ($result === null) {
                return null;
            }
            $evidence = ['home_score' => (int) $game->home_score, 'away_score' => (int) $game->away_score,
                'home_linescores' => MlbLineScores::normalize($game->home_linescores),
                'away_linescores' => MlbLineScores::normalize($game->away_linescores),
                'prop_id' => $prop?->id, 'prop_actual_value' => $prop?->actual_value === null ? null : (float) $prop->actual_value];
            $fingerprint = app(CanonicalPayloadHasher::class)->hash(['version' => 'mlb-candidate-result-v1',
                'decision_id' => $decision->id, 'evidence' => $evidence, 'result' => $result]);
            $receipt = BetSettlement::query()->where('bet_decision_id', $decision->id)->lockForUpdate()->first();
            $shadowReturn = data_get($receipt?->metadata, 'shadow_profit_units');
            $sameReceipt = $receipt !== null
                && data_get($receipt->metadata, 'mlb_candidate_result_fingerprint') === $fingerprint
                && $receipt->result_status === $result['status']
                && is_numeric($receipt->result_value) && round((float) $receipt->result_value, 4) === round($result['value'], 4)
                && is_numeric($receipt->profit_units)
                && round((float) $receipt->profit_units, 4) === round($decision->is_bet ? $result['profit'] : 0.0, 4)
                && is_numeric($shadowReturn) && round((float) $shadowReturn, 4) === round($result['profit'], 4);
            if ($sameReceipt && $candidate->graded_at !== null && $candidate->result_status === $result['status']
                && $candidate->status === 'graded_'.$result['status']
                && (float) $candidate->result_value === round($result['value'], 3)
                && (float) $candidate->result_profit_units === round($result['profit'], 3)) {
                return $receipt;
            }
            $candidate->fill(['result_status' => $result['status'], 'status' => 'graded_'.$result['status'],
                'result_value' => $result['value'], 'result_profit_units' => $result['profit'], 'graded_at' => now()])->save();

            return $sameReceipt ? $receipt : $this->decisionRecorder->writeSettlement($candidate, $decision, $receipt, $result, $evidence, $fingerprint);
        });
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
            'moneyline' => [...$this->lineResult($candidate->side === 'home' ? $actualMargin : -$actualMargin, $candidate), 'value' => $actualMargin],
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
        $prop = $candidate->getRelationValue('gradingProp');
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
