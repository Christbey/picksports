<?php

namespace App\Console\Commands\CFB;

use App\Models\BetDecision;
use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbFrozenSpreadEntryComparison;
use App\Services\Validation\CfbValidationCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CompareFrozenSpreadEntriesCommand extends Command
{
    protected $signature = 'cfb:compare-frozen-spread-entries {--season=} {--development-through-week=3}';

    protected $description = 'Compare official and FPI spread signals on exact frozen decision inputs and entry lines';

    public function handle(CfbFrozenSpreadEntryComparison $comparison): int
    {
        $options = ['season' => $this->option('season'), 'development' => $this->option('development-through-week')];
        if (Validator::make($options, ['season' => 'required|integer|between:2000,2100', 'development' => 'required|integer|between:0,20'])->fails()) {
            $this->error('Supply a valid season and development week cutoff.');

            return self::FAILURE;
        }
        $games = Game::with(['sportEvent', 'homeTeam', 'awayTeam'])->where('season', (int) $options['season'])
            ->where('status', 'STATUS_FINAL')->get()->filter(fn ($g) => app(CfbValidationCoverage::class)->requiredTeamIds($g) !== [])->keyBy('id');
        // Order only narrow IDs; never sort the full JSON decision payloads.
        $entries = BetDecision::where('sport', 'cfb')->where('market_type', 'spread')->where('game_table', 'cfb_games')
            ->where('explanation->authority', 'cfb_canonical_prospective_decision')->whereIn('game_id', $games->keys())
            ->orderByDesc('decided_at')->orderByDesc('id')->get(['id', 'game_id'])->unique('game_id');
        $rows = $excluded = [];
        foreach ($entries as $entry) {
            $row = $comparison->compare(BetDecision::findOrFail($entry->id), $games[$entry->game_id]);
            if (isset($row['excluded'])) {
                $excluded[$row['excluded']] = ($excluded[$row['excluded']] ?? 0) + 1;

                continue;
            }
            $row['period'] = $row['week'] <= (int) $options['development'] ? 'development' : 'later_weeks_retrospective';
            $rows[] = $row;
        }
        $summary = [];
        $periods = ['all' => collect($rows), ...collect($rows)->groupBy('period')->all()];
        foreach ($periods as $period => $cohort) {
            $commonPriced = $cohort->filter(fn ($r) => collect($r['models'])->every(fn ($m) => ! $m['selected'] || $m['shadow_profit_units'] !== null));
            foreach (['official', 'fpi_baseline'] as $model) {
                $summary[$period][$model] = $comparison->summarize($cohort->all(), $model);
                $summary[$period]['common_price_supported'][$model] = $comparison->summarize($commonPriced->all(), $model);
            }
        }
        $this->line(json_encode(['season' => (int) $options['season'], 'generated_at' => now()->toIso8601String(),
            'candidate_policy' => 'fixed_fpi_difference_plus_neutral_aware_2.2_home_field_v1',
            'minimum_point_edge' => 3, 'development_through_week' => (int) $options['development'],
            'population' => 'one latest canonical frozen spread decision per final FBS/unknown-membership game; exact linked snapshot and entry handicap',
            'excluded' => $excluded, 'summary' => $summary, 'rows' => $rows,
            'limitations' => ['Later-week results have already been inspected and are retrospective, not untouched holdout validation.',
                'A point-edge signal is not a qualified bet; no calibrated probabilities, promotion or real-money claims.',
                'Opposite-side prices require same snapshot/book/contract and quotes stored by entry time; missing prices stay unknown.',
                'Priced comparisons use a common price-supported cohort; multiple games and weeks may still be correlated.'],
            'candidate_promoted' => false], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
