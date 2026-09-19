<?php

use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\CFB\Live\Overtime\OvertimeProjector;
use App\Services\CFB\Live\Overtime\OvertimeState;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->home = Team::factory()->create(['espn_id' => 'ot-home']);
    $this->away = Team::factory()->create(['espn_id' => 'ot-away']);
    $this->game = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'period' => 5, 'home_score' => 24, 'away_score' => 21, 'status' => 'STATUS_IN_PROGRESS']);
    $this->drive = fn ($side, $result = 'FG') => ['id' => $side, 'team' => ['id' => 'ot-'.$side],
        'start' => ['period' => ['number' => 5]], 'end' => ['period' => ['number' => 5]], 'result' => $result];
});

test('overtime score lead never establishes completed possessions', function () {
    $state = app(OvertimeState::class)->fromSummary($this->game, ['situation' => ['possession' => 'ot-away']]);
    expect($state['completed_possessions'])->toBe([])->and($state['possession'])->toBe('away');
    $this->game->overtime_state = $state;
    expect(app(OvertimeProjector::class)->project($this->game)['projection'])->toBeNull();
});

test('confirmed completed possessions determine winner without a heuristic probability', function () {
    $state = app(OvertimeState::class)->fromSummary($this->game, ['drives' => ['previous' => [($this->drive)('home'), ($this->drive)('away', 'DOWNS')]]]);
    $this->game->overtime_state = $state;
    $result = app(OvertimeProjector::class)->project($this->game);
    expect($result['status'])->toBe('live')->and($result['projection']['total'])->toBe(45.0)
        ->and($result['projection']['home_win_probability'])->toBe(1.0)
        ->and($result['projection']['probability_status'])->toBe('rules_determined')
        ->and($result['projection']['seconds_remaining'])->toBeNull();
});

test('pending touchdown try does not count as a completed possession', function () {
    $state = app(OvertimeState::class)->fromSummary($this->game, ['drives' => ['previous' => [($this->drive)('home', 'TD')]]]);
    expect($state['completed_possessions'])->toBe([]);
});

test('stale or score mismatched possession evidence cannot determine winner', function ($change) {
    $state = app(OvertimeState::class)->fromSummary($this->game, ['drives' => ['previous' => [($this->drive)('home'), ($this->drive)('away')]]]);
    $this->game->overtime_state = array_replace($state, $change);
    expect(app(OvertimeProjector::class)->project($this->game)['status'])->toBe('overtime_missing_possession');
})->with([[['observed_at' => '2000-01-01T00:00:00Z']], [['home_score' => 99]], [['period' => 6]]]);

test('third overtime records the try-only format and conflicting feeds are rejected', function () {
    $this->game->period = 7;
    $state = app(OvertimeState::class)->fromSummary($this->game, ['situation' => ['possession' => 'ot-home'],
        'drives' => ['current' => ['team' => ['id' => 'ot-away'], 'start' => ['period' => ['number' => 7]]]]]);
    expect($state['format'])->toBe('two_point_try')->and($state['round'])->toBe(3)->and($state['status'])->toBe('conflicting_possession');
});

test('learns remaining overtime scores from distinct prior games with matching possession state', function () {
    $this->game->game_date = now();
    $state = app(OvertimeState::class)->fromSummary($this->game, ['situation' => ['possession' => 'ot-away', 'down' => 1, 'distance' => 10, 'yardsToEndzone' => 25],
        'drives' => ['previous' => [($this->drive)('home')]]]);
    $this->game->overtime_state = $state;
    for ($i = 0; $i < 30; $i++) {
        $historical = Game::factory()->create(['season' => 2025, 'status' => 'STATUS_FINAL', 'period' => 5,
            'game_date' => now()->subDays($i + 1), 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
            'home_score' => 24, 'away_score' => 28]);
        foreach ([[4, 21, 21, $this->home->id], [5, 24, 21, $this->home->id], [5, 24, 28, $this->away->id]] as $sequence => [$period, $home, $away, $team]) {
            Play::factory()->create(['game_id' => $historical->id, 'sequence_number' => $sequence,
                'period' => $period, 'home_score' => $home, 'away_score' => $away, 'possession_team_id' => $team,
                'down' => 1, 'distance' => 10, 'yards_to_endzone' => 25]);
        }
    }
    Cache::flush();
    $result = app(OvertimeProjector::class)->project($this->game);
    expect($result['status'])->toBe('live')->and($result['projection']['home_points'])->toBe(24.0)
        ->and($result['projection']['away_points'])->toBe(28.0)
        ->and($result['projection']['overtime']['sample_games'])->toBe(30)
        ->and($result['projection']['probability_status'])->toBe('uncalibrated_empirical_overtime');
    $this->game->overtime_state = array_replace($state, ['distance' => 25]);
    expect(app(OvertimeProjector::class)->project($this->game)['status'])->toBe('overtime_insufficient_history');
});

test('recognizes completed extra point embedded in ESPN touchdown metadata', function () {
    $drive = ($this->drive)('home', 'TD');
    $drive['plays'] = [['type' => ['text' => 'Passing Touchdown'], 'pointAfterAttempt' => ['value' => 1]]];
    $state = app(OvertimeState::class)->fromSummary($this->game, ['drives' => ['previous' => [$drive]]]);
    expect($state['completed_possessions'])->toBe(['home']);
});

test('null historical score states are never coerced into tied overtime evidence', function () {
    $this->game->game_date = now();
    $this->game->home_score = 21;
    $this->game->overtime_state = ['status' => 'observed', 'observed_at' => now()->toIso8601String(), 'period' => 5,
        'home_score' => 21, 'away_score' => 21, 'possession' => 'home', 'completed_possessions' => [],
        'down' => 1, 'distance' => 10, 'yards_to_endzone' => 25];
    $history = [];
    for ($i = 0; $i < 30; $i++) {
        $history[] = ['id' => 1000 + $i, 'date' => '2025-01-01', 'home_id' => 1, 'home_final' => 7, 'away_final' => 0,
            'plays' => [['period' => 4, 'home_score' => null, 'away_score' => null],
                ['period' => 5, 'possession_team_id' => 1, 'down' => 1, 'distance' => 10, 'yards_to_endzone' => 25, 'home_score' => 7, 'away_score' => 0]]];
    }
    Cache::put('cfb-ot-history-v1:'.now()->format('Y-m-d'), $history, 3600);
    $result = app(OvertimeProjector::class)->project($this->game);
    expect($result['status'])->toBe('overtime_insufficient_history')->and($result['sample_games'])->toBe(0);
});
