<?php

use App\Models\CBB\Game;
use App\Models\CBB\Team;
use App\Models\Sports\FuturesOdd;
use App\Models\User;
use App\Services\Sports\FuturesOddsLookupService;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Cache::flush();
    $user = User::factory()->create();
    config(['subscriptions.tier_bypass_user_ids' => [$user->id]]);
    Sanctum::actingAs($user);
    $this->travelTo(now()->setDate(2026, 11, 26)->setTime(12, 0));
});

function evidenceForecast(string $sport): object
{
    $namespace = strtoupper($sport);
    $teamClass = "App\\Models\\{$namespace}\\Team";
    $team = $teamClass::factory()->create();
    if (in_array($sport, ['cbb', 'wcbb'], true)) {
        $forecast = "App\\Models\\{$namespace}\\TournamentForecast";
        $forecast::factory()->create(['team_id' => $team->id, 'season' => 2026, 'champion_probability' => 0.5]);
    } else {
        $forecast = "App\\Models\\{$namespace}\\PlayoffForecast";
        $forecast::create(['team_id' => $team->id, 'season' => 2026, 'selection_score' => 1, 'playoff_make_probability' => .8, 'champion_probability' => .5, 'simulation_runs' => 100]);
    }

    return $team;
}

function evidenceQuote(string $sport, object $team, array $overrides = []): FuturesOdd
{
    return FuturesOdd::create([...['row_key' => fake()->uuid(), 'sport' => $sport, 'season' => 2026, 'odds_api_sport_key' => $sport.'_champion', $sport.'_team_id' => $team->id, 'bookmaker' => 'synthetic', 'market_key' => 'championship_winner', 'outcome_name' => 'Synthetic team', 'price' => 400, 'implied_probability' => .2, 'fetched_at' => now()->subHour(), 'market_last_update' => now()->subHour()], ...$overrides]);
}

it('keeps old future or unobserved quotes out of current forecast differences', function (string $sport, string $case) {
    $team = evidenceForecast($sport);
    $overrides = match ($case) {
        'old' => ['fetched_at' => now()->subHours(37)],
        'future' => ['fetched_at' => now()->addHour()],
        'missing' => [],
        'old source' => ['market_last_update' => now()->subHours(37)],
    };
    evidenceQuote($sport, $team, $overrides);
    if ($case === 'missing') {
        $this->partialMock(FuturesOddsLookupService::class, function ($mock) use ($team) {
            $mock->shouldReceive('byTeamForSeason')->andReturn([$team->id => ['bookmaker' => 'synthetic', 'market_key' => 'championship_winner', 'price' => 400, 'implied_probability' => .2, 'fetched_at' => null, 'odds_api_sport_key' => 'synthetic']]);
        });
    }
    $this->getJson("/api/v2/sports/{$sport}/forecasts?season=2026")->assertOk()
        ->assertJsonPath('data.0.market_odds.price', 400)
        ->assertJsonPath('data.0.market_odds.stale', true)
        ->assertJsonPath('data.0.market_edge.edge_probability', null)
        ->assertJsonPath('data.0.market_edge.edge_percent_points', null)
        ->assertJsonPath('data.0.market_edge.has_edge', false)
        ->assertJsonPath('data.0.market_edge.actionable', false);
})->with(['nba', 'mlb', 'cbb', 'wcbb'])->with(['old', 'future', 'missing', 'old source']);

it('retains fresh model differences but never labels them actionable', function () {
    $team = evidenceForecast('nba');
    evidenceQuote('nba', $team);
    $this->getJson('/api/v2/sports/nba/forecasts?season=2026')->assertOk()
        ->assertJsonPath('data.0.market_odds.stale', false)
        ->assertJsonPath('data.0.market_edge.edge_probability', .3)
        ->assertJsonPath('data.0.market_edge.actionable', false);
});

it('rechecks quote freshness after returning cached forecast rows', function () {
    $team = evidenceForecast('nba');
    evidenceQuote('nba', $team, ['fetched_at' => now()->subHours(36)->addMinute(), 'market_last_update' => now()->subHours(36)->addMinute()]);
    $this->getJson('/api/v2/sports/nba/forecasts?season=2026')->assertOk()->assertJsonPath('data.0.market_edge.edge_probability', .3);
    $this->travel(61)->seconds();
    $this->getJson('/api/v2/sports/nba/forecasts?season=2026')->assertOk()->assertJsonPath('data.0.market_edge.edge_probability', null);
});

it('does not invent zero model probabilities for confirmed field teams without forecasts', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => config('cbb.season.types.postseason'), 'is_ncaa_tournament' => true, 'tournament_round' => 'round_of_64', 'status' => 'STATUS_SCHEDULED']);
    $response = $this->getJson('/api/v2/sports/cbb/forecasts?season=2026')->assertOk()->assertJsonCount(2, 'data');
    foreach ($response->json('data') as $row) {
        expect($row['is_actual_field'])->toBeTrue()->and($row['champion_probability'])->toBeNull()
            ->and($row['final_four_probability'])->toBeNull()->and($row['tournament_make_probability'])->toBeNull();
    }
});

it('binds team futures to the path identity despite a conflicting query filter', function () {
    $home = App\Models\NBA\Team::factory()->create();
    $other = App\Models\NBA\Team::factory()->create();
    $quote = evidenceQuote('nba', $home);
    evidenceQuote('nba', $other);
    $this->getJson("/api/v2/sports/nba/teams/{$home->id}/futures?team_id={$other->id}")->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $quote->id)->assertJsonPath('meta.filters.team_id', $home->id);
});
