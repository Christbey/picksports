<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerInjury;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\Team;
use App\Support\InjuryImpactScorer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

it('batches the exact per-player lookback without changing injury scores', function (int $lookback) {
    config(['nba.prediction.injury_epa_lookback_games' => $lookback]);
    $team = Team::factory()->create();
    $away = Team::factory()->create();
    $players = Player::factory()->count(4)->create(['team_id' => $team->id]);
    $games = collect(range(1, 35))->map(fn () => Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $away->id]));
    foreach ($players->take(3) as $index => $player) {
        foreach ($games as $gameIndex => $game) {
            PlayerStat::factory()->create([
                'player_id' => $player->id, 'team_id' => $team->id, 'game_id' => $game->id,
                'points' => ($gameIndex + $index) % 30, 'assists' => $index + 2,
                'rebounds_total' => $index + 3, 'turnovers' => 2,
            ]);
        }
    }
    $individual = new InjuryImpactScorer;
    $expected = [];
    foreach ($players as $player) {
        foreach (['Out', 'Questionable', 'Available'] as $status) {
            $expected[$player->id][$status] = $individual->describe('nba', $player->id, $status);
        }
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $batch = new InjuryImpactScorer;
    $batch->preload('nba', [...$players->modelKeys(), $players->first()->id, 0]);
    foreach ($players as $player) {
        foreach (['Out', 'Questionable', 'Available'] as $status) {
            expect($batch->describe('nba', $player->id, $status))->toBe($expected[$player->id][$status]);
        }
    }
    $statReads = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "nba_player_stats"'));
    expect($statReads)->toHaveCount(1);
    $queriesBeforeRepeat = count($queries);
    $batch->preload('nba', $players->modelKeys());
    expect(count($queries))->toBe($queriesBeforeRepeat);
})->with([1, 10, 99]);

it('does not load stats when weighting is disabled or the sport has no weighted impact', function () {
    config(['nba.prediction.injury_epa_weighting_enabled' => false]);
    $this->expectsDatabaseQueryCount(0);
    $scorer = new InjuryImpactScorer;
    $scorer->preload('nba', [1, 2]);
    $scorer->preload('nfl', [1, 2]);
    expect($scorer->describe('nba', 1, 'Out')['multiplier'])->toBe(1.0);
});

it('keeps public page stat reads bounded as active injury coverage grows', function () {
    $team = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $team->id, 'away_team_id' => $away->id]);
    $players = Player::factory()->count(12)->create(['team_id' => $team->id]);
    foreach ($players as $index => $player) {
        PlayerStat::factory()->create(['player_id' => $player->id, 'team_id' => $team->id, 'game_id' => $game->id]);
        PlayerInjury::create([
            'player_id' => $player->id, 'team_id' => $team->id, 'injury_key' => "fixture-{$index}",
            'status' => 'Out', 'is_active' => true, 'source_updated_at' => now(),
        ]);
    }
    $statReads = [];
    DB::listen(function ($query) use (&$statReads): void {
        if (str_contains($query->sql, 'from "nba_player_stats"') || str_contains($query->sql, 'from `nba_player_stats`')) {
            $statReads[] = $query->sql;
        }
    });
    $this->withoutVite()->get('/nba')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PublicSport')->has('injuries.top', 5)->has('injuries.recent', 5));
    expect(count($statReads))->toBeLessThanOrEqual(3);
});
