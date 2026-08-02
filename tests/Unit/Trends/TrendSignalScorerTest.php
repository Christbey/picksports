<?php

use App\Services\Trends\TrendSignalScorer;

it('does not present a neutral losing percentage as a team edge', function () {
    $signal = app(TrendSignalScorer::class)->score('mlb', [
        'advanced' => ['The CLE are 53-58 (47.7%) against the model spread'],
    ], 111)[0];

    expect($signal)
        ->toMatchArray([
            'quality' => 'contextual',
            'direction' => 'context',
            'confidence' => 'low',
            'sample_size' => 111,
            'percentage' => 47.7,
        ])
        ->and($signal['score'])->toBeLessThan(60)
        ->and($signal['reason_codes'])->toContain('unvalidated_trend_evidence')
        ->and($signal['reason_codes'])->not->toContain('strong_trend_signal');
});

it('keeps average run messages descriptive without a directional effect', function () {
    $signals = app(TrendSignalScorer::class)->score('mlb', [
        'totals' => ['Games involving the ARI average 8.9 total runs'],
        'defensive_performance' => ['The CLE defense allows 4.2 runs per game'],
    ], 111);

    expect($signals)->each(function ($signal) {
        $signal->quality->toBe('contextual')
            ->confidence->toBe('low')
            ->percentage->toBeNull()
            ->reason_codes->toContain('descriptive_trend_context')
            ->reason_codes->not->toContain('strong_trend_signal');
    });

    expect(collect($signals)->pluck('direction')->all())
        ->each->toBe('total_context');
});

it('recognizes an extreme ratio without calling unvalidated evidence actionable or strong', function () {
    $signal = app(TrendSignalScorer::class)->score('mlb', [
        'totals' => ['The CLE have finished UNDER the model total in 107 of their last 111 games'],
    ], 111)[0];

    expect($signal)
        ->toMatchArray([
            'quality' => 'contextual',
            'direction' => 'total_under',
            'confidence' => 'medium',
            'sample_size' => 111,
            'percentage' => 96.4,
        ])
        ->and($signal['score'])->toBeLessThan(70)
        ->and($signal['reason_codes'])->not->toContain('strong_trend_signal');
});

it('flags thin samples even when the observed rate is directional', function () {
    $signal = app(TrendSignalScorer::class)->score('mlb', [
        'situational' => ['The ARI have won 4 of their last 5 games'],
    ], 111)[0];

    expect($signal)
        ->toMatchArray([
            'quality' => 'contextual',
            'direction' => 'support',
            'confidence' => 'thin_sample',
            'sample_size' => 5,
            'percentage' => 80.0,
        ])
        ->and($signal['reason_codes'])->toContain('thin_sample_trend');
});

it('does not infer support or risk from prose without a parsed effect', function () {
    $signals = app(TrendSignalScorer::class)->score('nba', [
        'advanced' => ['The NY have strong matchup fundamentals'],
        'clutch_performance' => ['The CLE struggle in close games'],
    ], 82);

    expect($signals)->each(function ($signal) {
        $signal->quality->toBe('contextual')
            ->direction->toBe('context')
            ->confidence->toBe('low')
            ->percentage->toBeNull();
    });

    $summary = app(TrendSignalScorer::class)->summarize($signals);

    expect($summary['counts'])
        ->toMatchArray([
            'actionable' => 0,
            'contextual' => 2,
            'support' => 0,
            'risk' => 0,
        ]);
});

it('keeps clearly poor measured performance as risk without claiming validation', function () {
    $signal = app(TrendSignalScorer::class)->score('nba', [
        'clutch_performance' => ['The CLE struggle in close games, winning only 22.2% of them'],
    ], 82)[0];

    expect($signal)
        ->toMatchArray([
            'quality' => 'contextual',
            'direction' => 'risk',
            'confidence' => 'medium',
            'percentage' => 22.2,
        ])
        ->and($signal['reason_codes'])->toContain('late_game_execution_context');
});
