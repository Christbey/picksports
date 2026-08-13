<?php

use App\Models\BetDecision;
use App\Models\BetSettlement;
use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use App\Models\NBA\Game as NbaGame;
use App\Models\NBA\Prediction as NbaPrediction;
use App\Models\NBA\Team as NbaTeam;
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
        ->and($roi['total_pushes'])->toBe(0)
        ->and($roi['total_staked_units'])->toBe(1)
        ->and($roi['total_wagered'])->toBe(100)
        ->and($roi['total_profit'])->toBe(150.0)
        ->and($roi['total_profit_units'])->toBe(1.5)
        ->and($roi['roi_percentage'])->toBe(150.0)
        ->and($roi['win_percentage'])->toBe(100.0)
        ->and($roi['verified'])->toBeTrue()
        ->and($roi['methodology'])->toBe('settled_pregame_bet_decisions');
});

it('excludes pushes from ROI win rate while retaining their settled stake', function () {
    foreach ([
        ['status' => 'win', 'profit' => 0.9091],
        ['status' => 'loss', 'profit' => -1.0],
        ['status' => 'push', 'profit' => 0.0],
    ] as $index => $result) {
        $decision = BetDecision::query()->create([
            'decision_run_id' => (string) Str::uuid(),
            'sport' => 'mlb',
            'game_table' => 'mlb_games',
            'game_id' => 100 + $index,
            'market_type' => 'moneyline',
            'market_key' => 'h2h',
            'side' => 'home',
            'price' => -110,
            'status' => 'bet',
            'is_bet' => true,
            'pregame_safe' => true,
            'decided_at' => '2026-07-20 12:00:00',
            'decision_hash' => hash('sha256', "verified-decision-{$index}"),
        ]);
        BetSettlement::query()->create([
            'bet_decision_id' => $decision->id,
            'result_status' => $result['status'],
            'profit_units' => $result['profit'],
            'graded_at' => now(),
            'settled_at' => now(),
        ]);
    }

    $roi = app(PerformanceStatistics::class)->calculateROI('2026-07-01', '2026-07-31');

    expect($roi['total_bets'])->toBe(3)
        ->and($roi['total_wins'])->toBe(1)
        ->and($roi['total_losses'])->toBe(1)
        ->and($roi['total_pushes'])->toBe(1)
        ->and($roi['total_staked_units'])->toBe(3)
        ->and($roi['total_wagered'])->toBe(300)
        ->and($roi['win_percentage'])->toBe(50.0)
        ->and($roi['roi_percentage'])->toBe(-3.03);
});

it('returns pending ROI percentages when no qualifying settlements exist', function () {
    $roi = app(PerformanceStatistics::class)->calculateROI();

    expect($roi['total_bets'])->toBe(0)
        ->and($roi['roi_percentage'])->toBeNull()
        ->and($roi['win_percentage'])->toBeNull();
});

it('keeps perfect errors and ending-date games in performance averages', function () {
    $homeTeam = MlbTeam::factory()->create();
    $awayTeam = MlbTeam::factory()->create();
    $perfectGame = MlbGame::factory()->create([
        'season' => 2026,
        'game_date' => '2026-07-31 19:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);
    $missedGame = MlbGame::factory()->create([
        'season' => 2026,
        'game_date' => '2026-07-30 19:10:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $awayTeam->id,
        'away_team_id' => $homeTeam->id,
    ]);

    MlbPrediction::query()->create([
        'game_id' => $perfectGame->id,
        'winner_correct' => true,
        'spread_error' => 0,
        'total_error' => 0,
        'graded_at' => '2026-07-31 23:00:00',
    ]);
    MlbPrediction::query()->create([
        'game_id' => $missedGame->id,
        'winner_correct' => false,
        'spread_error' => 2,
        'total_error' => 4,
        'graded_at' => '2026-07-30 23:00:00',
    ]);

    $stats = app(PerformanceStatistics::class)->getStatsBySport('2026-07-01', '2026-07-31')['mlb'];

    expect($stats['total_graded'])->toBe(2)
        ->and($stats['winner_accuracy'])->toBe(50.0)
        ->and($stats['avg_spread_error'])->toBe(1.0)
        ->and($stats['spread_sample_size'])->toBe(2)
        ->and($stats['avg_total_error'])->toBe(2.0)
        ->and($stats['total_sample_size'])->toBe(2);
});

it('uses the latest graded sport season instead of a stale configured season', function () {
    config(['nba.season.default' => 2025]);
    $homeTeam = NbaTeam::factory()->create();
    $awayTeam = NbaTeam::factory()->create();
    $game = NbaGame::factory()->create([
        'season' => 2026,
        'game_date' => '2026-06-15 20:00:00',
        'status' => 'STATUS_FINAL',
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
    ]);
    NbaPrediction::query()->create([
        'game_id' => $game->id,
        'winner_correct' => true,
        'spread_error' => 1.5,
        'total_error' => 3.5,
        'graded_at' => '2026-06-15 23:00:00',
    ]);

    $season = app(PerformanceStatistics::class)->getSeasonToDate();

    expect($season)->toHaveKey('nba')
        ->and($season['nba']['season'])->toBe(2026)
        ->and($season['nba']['total_graded'])->toBe(1)
        ->and($season['nba']['winner_accuracy'])->toBe(100.0);
});
