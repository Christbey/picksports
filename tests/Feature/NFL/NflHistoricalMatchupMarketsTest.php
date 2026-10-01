<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflHistoricalMatchupMarkets;

it('accepts only explicitly normalized closing archives matching the exact historical kickoff', function () {
    $game = Game::factory()->create(['home_team_id' => Team::factory()->create()->id,
        'away_team_id' => Team::factory()->create()->id, 'game_date' => '2026-09-13', 'game_time' => '17:00:00']);
    $snapshot = GameOddsSnapshot::create(['sport' => 'nfl', 'game_table' => 'nfl_games', 'game_id' => $game->id,
        'source' => 'nflverse', 'bookmaker_key' => 'nflverse_closing', 'captured_at' => '2026-09-13T16:55:00Z',
        'commence_time' => Carbon\Carbon::parse('2026-09-13T17:00:00Z')->setTimezone(config('app.timezone')), 'payload_hash' => hash('sha256', 'test'), 'odds_data' => [],
        'market_context' => ['source' => 'nflverse_schedules', 'line_type' => 'closing',
            'normalization_version' => 'nflverse_schedule_v2', 'source_spread_convention' => 'home_margin_positive_home_favored',
            'capture_time_is_synthetic' => true, 'source_gameday' => '2026-09-13', 'spread_line' => 3.5, 'total_line' => 44.5]]);
    $service = app(NflHistoricalMatchupMarkets::class);
    $market = $service->forGames(collect([$game]))[$game->id];
    expect($market['home_handicap'])->toBe(-3.5)->and($market['mode'])->toBe('retrospective_closing_line_record');
    $snapshot->update(['market_context' => [...$snapshot->market_context, 'normalization_version' => 'unknown']]);
    expect($service->forGames(collect([$game])))->toBe([]);
    $snapshot->update(['market_context' => [...$snapshot->market_context, 'normalization_version' => 'nflverse_schedule_v2'], 'commence_time' => '2026-09-14T17:00:00Z']);
    expect($service->forGames(collect([$game])))->toBe([]);
});
