<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\CFB\TeamTotals\HistoricalTeamTotalProjector;

beforeEach(function () {
    $this->teams = Team::factory()->count(6)->create(['division' => 'FBS']);
    for ($round = 0; $round < 8; $round++) {
        for ($i = 0; $i < 6; $i++) {
            Game::factory()->create(['home_team_id' => $this->teams[$i]->id, 'away_team_id' => $this->teams[($i + 1) % 6]->id,
                'season' => 2025, 'game_date' => '2025-'.sprintf('%02d', 9 + intdiv($round, 4)).'-'.sprintf('%02d', 1 + ($round % 4) * 7),
                'game_time' => '18:00:00', 'status' => 'STATUS_FINAL', 'home_score' => 28 + $i, 'away_score' => 21 + $i, 'neutral_site' => false]);
        }
    }
    $this->game = Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[3]->id,
        'season' => 2026, 'game_date' => '2026-09-12', 'game_time' => '23:30:00', 'status' => 'STATUS_SCHEDULED']);
});

test('uses prior seasons and reports sample sizes without saved predictions', function () {
    $before = $this->game->fresh()->getAttributes();
    $p = app(HistoricalTeamTotalProjector::class)->project($this->game);
    expect($p['status'])->toBe('experimental')->and($p['history_games'])->toEqual(48)
        ->and($p['teams']['home']['by_season'][2025])->toBe(16)
        ->and($p['home_points'])->toBeGreaterThan(0)->and($p['away_points'])->toBeGreaterThan(0)
        ->and($this->game->fresh()->getAttributes())->toBe($before);
});

test('target outcomes same day finals and future scores never affect a historical projection', function () {
    $before = (new HistoricalTeamTotalProjector)->project($this->game);
    $this->game->update(['status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 0]);
    foreach (['2026-09-12', '2026-09-19'] as $date) {
        Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[3]->id,
            'season' => 2026, 'game_date' => $date, 'status' => 'STATUS_FINAL', 'home_score' => 100, 'away_score' => 0]);
    }
    expect((new HistoricalTeamTotalProjector)->project($this->game->fresh()))->toBe($before);
});

test('one early blowout is shrunk against multiple prior-season games', function () {
    $before = (new HistoricalTeamTotalProjector)->project($this->game);
    Game::factory()->create(['home_team_id' => $this->teams[0]->id, 'away_team_id' => $this->teams[3]->id,
        'season' => 2026, 'game_date' => '2026-09-05', 'status' => 'STATUS_FINAL', 'home_score' => 70, 'away_score' => 0]);
    $after = (new HistoricalTeamTotalProjector)->project($this->game);
    expect($after['home_points'])->toBeGreaterThan($before['home_points'])
        ->and($after['home_points'] - $before['home_points'])->toBeLessThan(8)
        ->and($after['teams']['home']['by_season'][2026])->toBe(1)
        ->and($after['teams']['home']['by_season'][2025])->toBe(16);
});

test('withholds unsupported subdivision and insufficient history', function () {
    $new = Team::factory()->create(['division' => 'FBS']);
    $this->game->update(['away_team_id' => $new->id]);
    $p = (new HistoricalTeamTotalProjector)->project($this->game);
    expect($p['status'])->toBe('insufficient_history')->and($p['home_points'])->toBeNull();
    $new->update(['division' => 'FCS']);
    $p = (new HistoricalTeamTotalProjector)->project($this->game);
    expect($p['status'])->toBe('unsupported_subdivision')->and($p['away_points'])->toBeNull();
});

test('neutral site removes the fitted venue advantage', function () {
    $model = new HistoricalTeamTotalProjector;
    $home = $model->project($this->game);
    $this->game->neutral_site = true;
    $neutral = $model->project($this->game);
    expect($home['home_points'])->toBeGreaterThan($neutral['home_points'])
        ->and($home['away_points'])->toBeLessThan($neutral['away_points']);
});

test('historical projection command validates dates', function () {
    $this->artisan('cfb:team-totals --date=2026-02-31')->assertFailed();
    $this->artisan('cfb:team-totals --backtest-season=oops')->assertFailed();
    $this->artisan('cfb:team-totals --date=2026-09-12')->assertSuccessful();
});
