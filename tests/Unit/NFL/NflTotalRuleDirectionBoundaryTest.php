<?php

use App\Services\NFL\NflTotalRuleSupport;
use App\Support\NflBetRuleEngine;
use Tests\TestCase;

uses(TestCase::class);

function actualTotalRulePrediction(array $codes): object
{
    $evaluation = app(NflBetRuleEngine::class)->evaluate($codes, [], 90.0, null, 5.0);

    return (object) ['model_metadata' => ['analysis_layer' => ['reason_codes' => $codes, 'bet_rule_evaluation' => $evaluation]]];
}

it('does not borrow a global edge direction to relabel opposite configured total rules', function (string $direction, array $codes, string $opposite) {
    $prediction = actualTotalRulePrediction($codes);
    expect(collect($prediction->model_metadata['analysis_layer']['bet_rule_evaluation']['matched_rules'])->pluck('name'))->toContain($opposite);
    expect(app(NflTotalRuleSupport::class)->forPrediction($prediction, $direction))->toBeNull();
})->with([
    'under rule with over edge' => ['over', ['market_total_edge_over', 'cold_weather_under_signal'], 'weather_total_under'],
    'over rule with under edge' => ['under', ['market_total_edge_under', 'dome_scoring_boost', 'fast_pace_over_signal', 'explosive_offense_edge'], 'dome_explosive_over_confluence'],
]);

it('filters opposite rules while preserving same direction rules in a mixed engine result', function () {
    $prediction = actualTotalRulePrediction(['market_total_edge_over', 'cold_weather_under_signal', 'dome_scoring_boost', 'fast_pace_over_signal', 'explosive_offense_edge']);
    $support = app(NflTotalRuleSupport::class)->forPrediction($prediction, 'over');
    expect($support)->not->toBeNull()->and(collect($support['rules'])->pluck('name'))->not->toContain('weather_total_under')->toContain('dome_explosive_over_confluence');
});

it('preserves actual same direction configured support', function (string $direction, array $codes) {
    expect(app(NflTotalRuleSupport::class)->forPrediction(actualTotalRulePrediction($codes), $direction))->not->toBeNull();
})->with([
    'under' => ['under', ['market_total_edge_under', 'cold_weather_under_signal']],
    'over' => ['over', ['market_total_edge_over', 'dome_scoring_boost', 'fast_pace_over_signal', 'explosive_offense_edge']],
]);

it('preserves direction neutral configured total market edge support', function (string $direction) {
    $support = app(NflTotalRuleSupport::class)->forPrediction(actualTotalRulePrediction(['market_total_edge_'.$direction, 'total_market_edge', 'total_key_number_value']), $direction);
    expect($support)->not->toBeNull()->and(collect($support['rules'])->pluck('name'))->toContain('total_market_edge_play');
})->with(['over', 'under']);

it('retains the global edge admission and rejects unsupported directions', function () {
    $prediction = actualTotalRulePrediction(['cold_weather_under_signal']);
    expect(app(NflTotalRuleSupport::class)->forPrediction($prediction, 'under'))->toBeNull()
        ->and(app(NflTotalRuleSupport::class)->forPrediction($prediction, 'sideways'))->toBeNull();
});

it('uses whole local direction tokens without treating unrelated words as directions', function (string $name, string $label, string $direction, bool $supported) {
    $prediction = (object) ['model_metadata' => ['analysis_layer' => [
        'reason_codes' => ['market_total_edge_'.$direction],
        'bet_rule_evaluation' => ['matched_rules' => [['name' => $name, 'label' => $label, 'market' => 'total', 'action' => 'lean']]],
    ]]];
    $support = app(NflTotalRuleSupport::class)->forPrediction($prediction, $direction);
    expect($support !== null)->toBe($supported);
})->with([
    'turnover is not over' => ['turnover_support', '', 'under', true],
    'underlying is not under' => ['underlying_context', '', 'over', true],
    'overlook is not over' => ['overlook_context', '', 'under', true],
    'underscore opposite' => ['weather_under_rule', '', 'over', false],
    'hyphen opposite' => ['weather-under-rule', '', 'over', false],
    'label opposite' => ['weather_context', 'UNDER support', 'over', false],
    'punctuation opposite' => ['context', '(under)', 'over', false],
    'ambiguous both over' => ['over_under', '', 'over', false],
    'ambiguous both under' => ['over_under', '', 'under', false],
    'same direction label' => ['context', 'Over support', 'over', true],
]);
