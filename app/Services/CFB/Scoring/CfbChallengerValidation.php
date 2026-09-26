<?php

namespace App\Services\CFB\Scoring;

use App\Models\CanonicalPrediction;
use App\Models\ModelArtifact;
use App\Models\SportEventResult;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

class CfbChallengerValidation
{
    public function evaluate(ModelArtifact $artifact): array
    {
        $loaded = app(CfbScoringArtifact::class)->load($artifact->id, now(), allowResearchShadow: true);
        $offline = $loaded['payload'];
        $games = [];
        $weeks = [];
        $paired = [];
        $errors = ['challenger_margin' => 0.0, 'baseline_margin' => 0.0, 'challenger_total' => 0.0, 'baseline_total' => 0.0, 'challenger_brier' => 0.0, 'baseline_brier' => 0.0];
        foreach (CanonicalPrediction::where('sport', 'cfb')->where('phase', 'pregame')->whereIn('publication_state', ['published', 'superseded'])
            ->where('output_metadata->scoring_challenger->artifact_id', $artifact->id)->with(['markets', 'sportEvent', 'calculationRun.inputSnapshot'])->orderBy('generated_at')->lazyById(100) as $prediction) {
            $frozen = data_get($prediction->calculationRun?->inputSnapshot?->inputs, 'scoring_challenger');
            $kickoff = $prediction->sportEvent?->starts_at;
            $captured = $prediction->calculationRun?->inputSnapshot?->captured_at;
            if (! $kickoff || ! $captured || $captured->gte($kickoff) || $prediction->published_at?->gte($kickoff)
                || ($frozen['state'] ?? '') !== 'ready' || ($frozen['promoted'] ?? false) || isset($games[$prediction->sport_event_id])) {
                continue;
            }
            $result = SportEventResult::where('sport_event_id', $prediction->sport_event_id)->latest('id')->first();
            if (! $result || $result->status !== 'official' || ! $result->finalized_at || $result->home_score === null || $result->away_score === null || $result->home_score === $result->away_score) {
                continue;
            }
            $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
            $total = $prediction->markets->first(fn ($m) => $m->market_type === 'total');
            $winner = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home');
            if (! $spread || ! $total || ! $winner) {
                continue;
            }
            $s = $frozen['summary'];
            $margin = $result->home_score - $result->away_score;
            $points = $result->home_score + $result->away_score;
            $errors['challenger_margin'] += abs($s['home_margin'] - $margin);
            $errors['baseline_margin'] += abs(-$spread->projected_line - $margin);
            $errors['challenger_total'] += abs($s['total'] - $points);
            $errors['baseline_total'] += abs($total->projected_line - $points);
            $errors['challenger_brier'] += ($s['home_win_probability'] - (int) ($margin > 0)) ** 2;
            $errors['baseline_brier'] += ($winner->probability - (int) ($margin > 0)) ** 2;
            $games[$prediction->sport_event_id] = ['prediction_id' => $prediction->public_id, 'captured_at' => $captured->toIso8601String()];
            $week = $kickoff->copy()->startOfWeek()->toDateString();
            $weeks[$week] = true;
            if (! empty($frozen['benchmark_scores'])) {
                $candidate = new CfbScoreDistribution($frozen['scores'], $frozen['summary']['draws']);
                $reference = new CfbScoreDistribution($frozen['benchmark_scores'], $frozen['summary']['draws']);
                $paired[$week][] = $reference->energyScore($result->home_score, $result->away_score) - $candidate->energyScore($result->home_score, $result->away_score);
            }
        }
        $n = count($games);
        $means = array_map(fn ($v) => $n ? $v / $n : null, $errors);
        $interval = data_get($offline, 'promotion_report.energy_improvement_interval');
        $prospectiveInterval = $this->interval($paired);
        $pairedCount = array_sum(array_map('count', $paired));
        $gates = ['paired_holdout_energy_improves' => ($offline['evidence_mode'] === 'recorded_time' && is_array($interval) && $interval[0] > 0)
                || ($pairedCount >= 200 && $prospectiveInterval !== null && $prospectiveInterval[0] > 0),
            'separate_calibration' => data_get($offline, 'calibration.status') === 'fitted_on_separate_calibration_weeks',
            'prospective_games' => $n >= 200, 'prospective_weeks' => count($weeks) >= 4,
            'margin_tolerance' => $n > 0 && $means['challenger_margin'] <= $means['baseline_margin'] + .25,
            'total_tolerance' => $n > 0 && $means['challenger_total'] <= $means['baseline_total'] + .25,
            'probability_brier' => $n > 0 && $means['challenger_brier'] <= $means['baseline_brier']];

        return ['artifact_id' => $artifact->id, 'artifact_hash' => $artifact->artifact_hash, 'evaluated_at' => now()->toIso8601String(),
            'games' => $n, 'weeks' => count($weeks), 'historical_evaluation_mode' => $offline['evidence_mode'], 'prospective_energy_interval' => $prospectiveInterval, 'paired_prospective_games' => $pairedCount, 'mean_errors' => $means, 'gates' => $gates, 'forecast_allowed' => ! in_array(false, $gates, true),
            'betting_allowed' => false, 'betting_reason' => 'market_specific_priced_holdout_required', 'evidence' => $games];
    }

    private function interval(array $groups): ?array
    {
        if (count($groups) < 4) {
            return null;
        }
        $rng = new Randomizer(new Xoshiro256StarStar(618));
        $groups = array_values($groups);
        $values = [];
        for ($i = 0; $i < 2000; $i++) {
            $sum = 0.0;
            $n = 0;
            foreach ($groups as $_) {
                $sample = $groups[$rng->getInt(0, count($groups) - 1)];
                $sum += array_sum($sample);
                $n += count($sample);
            }
            $values[] = $sum / $n;
        }
        sort($values);

        return [$values[50], $values[1950]];
    }
}
