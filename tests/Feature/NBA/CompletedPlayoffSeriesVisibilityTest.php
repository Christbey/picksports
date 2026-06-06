<?php

use App\Models\NBA\Game;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

function createNbaPlayoffGame(Team $home, Team $away, array $overrides = []): Game
{
    return Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => '3',
        'week' => 1,
        'game_time' => '19:00:00',
        'venue_name' => 'Test Arena',
        'status' => 'STATUS_FINAL',
        'home_score' => 110,
        'away_score' => 100,
        ...$overrides,
    ]);
}

it('hides only scheduled postseason placeholders after a playoff series has ended', function () {
    $home = Team::factory()->create(['abbreviation' => 'HOM']);
    $away = Team::factory()->create(['abbreviation' => 'AWY']);

    for ($game = 1; $game <= 4; $game++) {
        createNbaPlayoffGame($home, $away, [
            'game_date' => now()->subDays(7 - $game)->toDateString(),
            'home_score' => 112,
            'away_score' => 101,
        ]);
    }

    $deadPlaceholder = createNbaPlayoffGame($away, $home, [
        'game_date' => now()->addDay()->toDateString(),
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
    ]);
    $regularSeasonFuture = createNbaPlayoffGame($away, $home, [
        'game_date' => now()->addDays(2)->toDateString(),
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
    ]);

    $visibleIds = Game::query()
        ->withoutCompletedPlayoffSeriesPlaceholders()
        ->pluck('id');

    expect($visibleIds)
        ->not->toContain($deadPlaceholder->id)
        ->toContain($regularSeasonFuture->id);
});

it('keeps scheduled postseason games while a playoff series is still alive', function () {
    $home = Team::factory()->create(['abbreviation' => 'AAA']);
    $away = Team::factory()->create(['abbreviation' => 'BBB']);

    createNbaPlayoffGame($home, $away, [
        'game_date' => now()->subDays(4)->toDateString(),
        'home_score' => 112,
        'away_score' => 101,
    ]);
    createNbaPlayoffGame($away, $home, [
        'game_date' => now()->subDays(3)->toDateString(),
        'home_score' => 108,
        'away_score' => 99,
    ]);
    createNbaPlayoffGame($home, $away, [
        'game_date' => now()->subDays(2)->toDateString(),
        'home_score' => 100,
        'away_score' => 104,
    ]);

    $livePlaceholder = createNbaPlayoffGame($away, $home, [
        'game_date' => now()->addDay()->toDateString(),
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
    ]);

    expect(Game::query()->withoutCompletedPlayoffSeriesPlaceholders()->pluck('id'))
        ->toContain($livePlaceholder->id);
});

it('does not pass ended-series placeholders through the NBA games API index', function () {
    $home = Team::factory()->create(['abbreviation' => 'NY']);
    $away = Team::factory()->create(['abbreviation' => 'CLE']);

    for ($game = 1; $game <= 4; $game++) {
        createNbaPlayoffGame($home, $away, [
            'game_date' => now()->subDays(7 - $game)->toDateString(),
            'home_score' => 118,
            'away_score' => 103,
        ]);
    }

    $deadPlaceholder = createNbaPlayoffGame($away, $home, [
        'game_date' => now()->addDay()->toDateString(),
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
    ]);

    $this->getJson('/api/v1/nba/games?season=2026&per_page=100')
        ->assertOk()
        ->assertJsonMissing(['id' => $deadPlaceholder->id]);
});

it('does not pass ended-series placeholders through the NBA game detail API', function () {
    $home = Team::factory()->create(['abbreviation' => 'OKC']);
    $away = Team::factory()->create(['abbreviation' => 'SA']);

    for ($game = 1; $game <= 4; $game++) {
        createNbaPlayoffGame($home, $away, [
            'game_date' => now()->subDays(7 - $game)->toDateString(),
            'home_score' => 119,
            'away_score' => 108,
        ]);
    }

    $deadPlaceholder = createNbaPlayoffGame($away, $home, [
        'game_date' => now()->addDay()->toDateString(),
        'status' => 'STATUS_SCHEDULED',
        'home_score' => 0,
        'away_score' => 0,
    ]);

    $this->getJson("/api/v1/nba/games/{$deadPlaceholder->id}")
        ->assertNotFound();
});

it('limits NBA season prediction generation to the near-term finals window while finals are active', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $knicks = Team::factory()->create([
        'abbreviation' => 'NY',
        'conference' => 'Eastern',
        'division' => 'Atlantic',
        'elo_rating' => 1620,
    ]);
    $spurs = Team::factory()->create([
        'abbreviation' => 'SA',
        'conference' => 'Western',
        'division' => 'Southwest',
        'elo_rating' => 1615,
    ]);

    foreach ([[$knicks, 8.6], [$spurs, 8.4]] as [$team, $netRating]) {
        TeamMetric::create([
            'team_id' => $team->id,
            'season' => 2026,
            'wins' => 60,
            'losses' => 22,
            'offensive_efficiency' => 116.0,
            'defensive_efficiency' => 108.0,
            'net_rating' => $netRating,
            'tempo' => 100.0,
            'strength_of_schedule' => 1500.0,
            'calculation_date' => now()->toDateString(),
        ]);
    }

    createNbaPlayoffGame($knicks, $spurs, [
        'game_date' => '2026-06-04',
        'home_score' => 111,
        'away_score' => 106,
    ]);
    createNbaPlayoffGame($spurs, $knicks, [
        'game_date' => '2026-06-06',
        'home_score' => 108,
        'away_score' => 101,
    ]);
    createNbaPlayoffGame($spurs, $knicks, [
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);
    createNbaPlayoffGame($knicks, $spurs, [
        'game_date' => '2026-06-10',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);
    createNbaPlayoffGame($spurs, $knicks, [
        'game_date' => '2026-06-15',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);

    $this->artisan('nba:generate-predictions', ['--season' => 2026])
        ->expectsOutput('Generating predictions for 2 games...')
        ->assertExitCode(0);
});
