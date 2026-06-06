<?php

use App\Actions\NBA\GeneratePlayoffForecast;
use App\Models\NBA\Game;
use App\Models\NBA\PlayoffForecast;
use App\Models\NBA\Team;
use App\Models\NBA\TeamMetric;

function createNbaForecastTeam(string $abbr, string $conference, float $netRating, int $elo = 1500): Team
{
    $team = Team::factory()->create([
        'abbreviation' => $abbr,
        'conference' => $conference,
        'division' => $conference === 'Eastern' ? 'Atlantic' : 'Southwest',
        'elo_rating' => $elo,
    ]);

    TeamMetric::create([
        'team_id' => $team->id,
        'season' => 2026,
        'wins' => 60,
        'losses' => 22,
        'offensive_efficiency' => 116.0 + $netRating,
        'defensive_efficiency' => 110.0,
        'net_rating' => $netRating,
        'tempo' => 100.0,
        'strength_of_schedule' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    return $team;
}

function createNbaFinalsGameForForecast(Team $home, Team $away, array $overrides = []): Game
{
    return Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => (string) config('nba.season.types.postseason', 3),
        'week' => 4,
        'game_time' => '20:00:00',
        'venue_name' => 'Finals Arena',
        'status' => 'STATUS_FINAL',
        'home_score' => 112,
        'away_score' => 104,
        ...$overrides,
    ]);
}

it('collapses NBA playoff forecasts to the active finals matchup once the finals are underway', function () {
    $this->travelTo('2026-06-06 12:00:00');

    $knicks = createNbaForecastTeam('NY', 'Eastern', 8.6, 1620);
    $spurs = createNbaForecastTeam('SA', 'Western', 8.4, 1615);
    $thunder = createNbaForecastTeam('OKC', 'Western', 12.0, 1700);

    PlayoffForecast::create([
        'team_id' => $thunder->id,
        'season' => 2026,
        'conference' => 'Western',
        'champion_probability' => 0.40,
    ]);

    createNbaFinalsGameForForecast($knicks, $spurs, [
        'game_date' => '2026-06-04',
        'home_score' => 111,
        'away_score' => 106,
    ]);
    createNbaFinalsGameForForecast($knicks, $spurs, [
        'game_date' => '2026-06-06',
        'home_score' => 99,
        'away_score' => 104,
    ]);
    createNbaFinalsGameForForecast($spurs, $knicks, [
        'game_date' => '2026-06-08',
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);

    $forecasts = (new GeneratePlayoffForecast)->execute(2026);

    expect($forecasts->pluck('team_id')->sort()->values()->all())
        ->toBe(collect([$knicks->id, $spurs->id])->sort()->values()->all());

    expect(PlayoffForecast::query()->where('season', 2026)->pluck('team_id')->sort()->values()->all())
        ->toBe(collect([$knicks->id, $spurs->id])->sort()->values()->all());

    expect((float) PlayoffForecast::query()->where('team_id', $knicks->id)->value('nba_finals_probability'))->toBe(1.0)
        ->and((float) PlayoffForecast::query()->where('team_id', $spurs->id)->value('nba_finals_probability'))->toBe(1.0)
        ->and((float) PlayoffForecast::query()->where('season', 2026)->sum('champion_probability'))->toBeGreaterThan(0.99)
        ->and((float) PlayoffForecast::query()->where('season', 2026)->sum('champion_probability'))->toBeLessThan(1.01);
});
