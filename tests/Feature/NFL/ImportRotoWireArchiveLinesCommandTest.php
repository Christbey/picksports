<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\Http;

it('matches rotowire archive lines to local nfl games without relying on playoff week numbers', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'NE',
        'location' => 'New England',
        'name' => 'Patriots',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'SEA',
        'location' => 'Seattle',
        'name' => 'Seahawks',
    ]);

    $game = Game::query()->create([
        'espn_event_id' => 'rotowire-archive-game',
        'espn_uid' => 'rotowire-archive-game-uid',
        'season' => 2025,
        'week' => 5,
        'season_type' => 'postseason',
        'game_date' => '2026-02-08',
        'game_time' => '17:30:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 13,
        'away_score' => 29,
        'status' => 'STATUS_FINAL',
    ]);

    Http::fake([
        'https://www.rotowire.com/betting/nfl/tables/games-archive.php' => Http::response([[
            'season' => '2025',
            'week' => '22',
            'game_date' => '2026-02-08 00:00:00',
            'game_time' => '18',
            'home_team_stats_id' => 'NE',
            'home_team_abbrev' => 'NE',
            'visit_team_stats_id' => 'SEA',
            'visit_team_abbrev' => 'SEA',
            'home_team_score' => 13,
            'visit_team_score' => 29,
            'game_over_under' => '45.0',
            'line' => 4.5,
            'surface' => 'Grass',
            'weather_icon' => 'Partly Cloudy Day',
            'temperature' => 67,
            'wind_speed' => 9.9,
            'favorite' => 'SEA',
            'score' => '29-13',
            'total' => 42,
            'spread' => 4.5,
        ]]),
    ]);

    $this->artisan('nfl:import-rotowire-archive-lines', [
        '--season' => 2025,
    ])
        ->expectsOutputToContain('1 matched, 1 written')
        ->assertSuccessful();

    $snapshot = GameOddsSnapshot::query()->sole();

    expect($snapshot->game_id)->toBe($game->id)
        ->and($snapshot->source)->toBe('rotowire_archive')
        ->and($snapshot->bookmaker_key)->toBe('rotowire_archive')
        ->and(data_get($snapshot->odds_data, 'home_team'))->toBe('NE')
        ->and(data_get($snapshot->odds_data, 'away_team'))->toBe('SEA')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.key'))->toBe('spreads')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.outcomes.0.point'))->toBe(4.5)
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.1.key'))->toBe('totals')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.1.outcomes.0.point'))->toEqual(45.0)
        ->and(data_get($snapshot->market_context, 'rotowire_week'))->toBe(22);
});

it('can dry-run rotowire archive line matching without writing snapshots', function () {
    $home = Team::factory()->create(['abbreviation' => 'LAR']);
    $away = Team::factory()->create(['abbreviation' => 'SF']);

    Game::query()->create([
        'espn_event_id' => 'rotowire-dry-run-game',
        'espn_uid' => 'rotowire-dry-run-game-uid',
        'season' => 2025,
        'week' => 18,
        'season_type' => 'regular',
        'game_date' => '2026-01-04',
        'game_time' => '15:25:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 21,
        'away_score' => 20,
        'status' => 'STATUS_FINAL',
    ]);

    Http::fake([
        'https://www.rotowire.com/betting/nfl/tables/games-archive.php' => Http::response([[
            'season' => '2025',
            'week' => '18',
            'game_date' => '2026-01-04 00:00:00',
            'home_team_stats_id' => 'LAR',
            'visit_team_stats_id' => 'SF',
            'home_team_score' => 21,
            'visit_team_score' => 20,
            'game_over_under' => '43.5',
            'line' => -2.5,
        ]]),
    ]);

    $this->artisan('nfl:import-rotowire-archive-lines', [
        '--season' => 2025,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('1 matched, 0 written')
        ->assertSuccessful();

    expect(GameOddsSnapshot::query()->count())->toBe(0);
});
