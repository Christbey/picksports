<?php

use App\Services\Trends\TrendSignalScorer;

it('scores and summarizes trend signals with quality and reason codes', function () {
    $signals = app(TrendSignalScorer::class)->score('nba', [
        'advanced' => ['The NY are 8-0 (100%) against the model spread'],
        'clutch_performance' => ['The CLE struggle in close games, winning only 22.2% of them'],
        'totals' => ['The NY have finished UNDER the model total in 5 of their last 7 games'],
    ], 8);

    expect($signals)->toHaveCount(3);

    $advanced = collect($signals)->firstWhere('category', 'advanced');
    $clutch = collect($signals)->firstWhere('category', 'clutch_performance');
    $totals = collect($signals)->firstWhere('category', 'totals');
    $summary = app(TrendSignalScorer::class)->summarize($signals);

    expect($advanced['quality'])->toBe('actionable')
        ->and($advanced['direction'])->toBe('support')
        ->and($advanced['reason_codes'])->toContain('strong_trend_signal')
        ->and($clutch['quality'])->toBe('volatile')
        ->and($clutch['direction'])->toBe('risk')
        ->and($clutch['reason_codes'])->toContain('late_game_execution_context')
        ->and($totals['direction'])->toBe('total_under')
        ->and($totals['reason_codes'])->toContain('pace_total_context')
        ->and($summary['counts']['actionable'])->toBe(2)
        ->and($summary['counts']['volatile'])->toBe(1)
        ->and($summary['counts']['risk'])->toBe(1)
        ->and($summary['counts']['total'])->toBe(1)
        ->and($summary['primary_signal'])->not->toBeNull();
});
