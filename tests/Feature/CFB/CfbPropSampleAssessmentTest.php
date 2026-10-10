<?php

use App\Models\CFB\PlayerProp;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\CFB\CfbPlayerEvidenceService;

it('distinguishes current season evidence from historical fallback', function ($current, $history, $status) {
    $assessment = app(CfbPlayerEvidenceService::class)->sampleAssessment($current, $history);
    expect($assessment['status'])->toBe($status)
        ->and($assessment['current_season_games'])->toBe($current)
        ->and($assessment['eligible_history_games'])->toBe($history)
        ->and($assessment['is_betting_validation'])->toBeFalse();
})->with([
    [2, 2, 'insufficient_history'],
    [2, 10, 'historical_fallback'],
    [3, 10, 'early_current_season'],
    [5, 5, 'early_current_season'],
    [6, 6, 'usable_current_season'],
    [8, 8, 'usable_current_season'],
    [9, 9, 'established_current_season'],
]);

it('describes limited context separately from source quality only for college props', function () {
    $analyzer = app(PlayerPropAnalyzer::class);
    $method = new ReflectionMethod($analyzer, 'signalQuality');
    $state = new ReflectionProperty($analyzer, 'cfbAnalysisProp');
    $state->setValue($analyzer, new PlayerProp);
    $college = $method->invoke($analyzer, 55, 69, 100, 6, []);
    expect($college['reason_codes'])->toContain('limited_context_coverage')
        ->not->toContain('limited_data_quality', 'thin_season_sample')
        ->and($college['tier'])->toBe('low');
    $state->setValue($analyzer, null);
    $shared = $method->invoke($analyzer, 55, 69, 100, 6, []);
    expect($shared['reason_codes'])->toContain('limited_data_quality')
        ->not->toContain('limited_context_coverage');
});
