<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Services\PerformanceStatistics;
use Illuminate\Support\Str;

it('calculates ROI only from settled pregame bet decisions using recorded profit', function () {
    $decision = BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => 10,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'home',
        'price' => 150,
        'status' => 'bet',
        'is_bet' => true,
        'pregame_safe' => true,
        'decided_at' => '2026-07-20 12:00:00',
        'decision_hash' => hash('sha256', 'verified-decision'),
    ]);
    BetSettlement::query()->create([
        'bet_decision_id' => $decision->id,
        'result_status' => 'win',
        'profit_units' => 1.5,
        'graded_at' => now(),
        'settled_at' => now(),
    ]);

    BetDecision::query()->create([
        'decision_run_id' => (string) Str::uuid(),
        'sport' => 'mlb',
        'game_table' => 'mlb_games',
        'game_id' => 11,
        'market_type' => 'moneyline',
        'market_key' => 'h2h',
        'side' => 'away',
        'status' => 'tracking_only',
        'is_bet' => false,
        'pregame_safe' => true,
        'decided_at' => '2026-07-20 12:00:00',
        'decision_hash' => hash('sha256', 'tracking-decision'),
    ]);

    $roi = app(PerformanceStatistics::class)->calculateROI('2026-07-01', '2026-07-31');

    expect($roi['total_bets'])->toBe(1)
        ->and($roi['total_wins'])->toBe(1)
        ->and($roi['total_losses'])->toBe(0)
        ->and($roi['total_wagered'])->toBe(100)
        ->and($roi['total_profit'])->toBe(150.0)
        ->and($roi['roi_percentage'])->toBe(150.0)
        ->and($roi['verified'])->toBeTrue()
        ->and($roi['methodology'])->toBe('settled_pregame_bet_decisions');
});
