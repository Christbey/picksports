<?php

use App\Actions\Validation\Checks\OddsCompletenessCheck;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

it('uses the NFL research expiry for monitoring instead of the shared eight hour cutoff', function () {
    $this->travelTo('2026-09-27 09:00:00');
    config()->set('nfl_research.market_freshness_minutes', 2160);
    config()->set('validation.thresholds.odds_completeness.stale_after_hours', 8);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id,
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-27', 'game_time' => '17:00:00',
        'odds_updated_at' => now()->subHours(24),
        'odds_data' => ['bookmakers' => [['markets' => [['key' => 'spreads'], ['key' => 'totals'], ['key' => 'h2h']]]]],
    ]);
    $check = app(OddsCompletenessCheck::class);
    expect($check->run('nfl', config('validation.sports.nfl'))['status'])->toBe('passing');
    $game->update(['odds_updated_at' => now()->subHours(37)]);
    expect($check->run('nfl', config('validation.sports.nfl'))['status'])->toBe('failing');
    config()->set('nfl_research.market_freshness_minutes', 2880);
    expect($check->run('nfl', config('validation.sports.nfl'))['status'])->toBe('passing');
    $this->travelBack();
});
