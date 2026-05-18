<?php

use App\Actions\ESPN\MLB\SyncGamesFromScoreboard;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\MLB\TeamMetric;
use Illuminate\Support\Facades\Artisan;

uses()->group('mlb', 'commands');

it('reports no changes when probable pitchers have not moved', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => '2',
        'status' => 'STATUS_SCHEDULED',
        'game_date' => now()->addDay()->toDateString(),
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'probable_home_pitcher_espn_id' => '999001',
        'probable_away_pitcher_espn_id' => '999002',
    ]);

    // Replace the scoreboard sync with a no-op subclass so it doesn't reach ESPN.
    $this->app->bind(SyncGamesFromScoreboard::class, fn () => new class extends SyncGamesFromScoreboard
    {
        public function __construct() {}

        public function execute(string $date): int
        {
            return 0;
        }
    });

    Artisan::call('mlb:refresh-probable-pitchers', ['--days-ahead' => 1]);

    $output = Artisan::output();
    expect($output)->toContain('no probable-pitcher changes detected');

    // Prediction count must not have grown.
    expect(Prediction::where('game_id', $game->id)->count())->toBe(0);
});

it('regenerates predictions only for games whose probable pitcher changed', function () {
    $home = Team::factory()->create(['elo_rating' => 1500]);
    $away = Team::factory()->create(['elo_rating' => 1500]);

    TeamMetric::query()->create([
        'team_id' => $home->id, 'season' => 2026, 'season_type' => '2',
        'wins' => 10, 'losses' => 5, 'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);
    TeamMetric::query()->create([
        'team_id' => $away->id, 'season' => 2026, 'season_type' => '2',
        'wins' => 10, 'losses' => 5, 'recent_form_rating' => 0.0,
        'injury_adjusted_team_rating' => 1500.0,
        'calculation_date' => now()->toDateString(),
    ]);

    $tomorrow = now()->addDay()->toDateString();

    $changedGame = Game::factory()->create([
        'season' => 2026, 'season_type' => '2',
        'status' => 'STATUS_SCHEDULED', 'game_date' => $tomorrow,
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'probable_home_pitcher_espn_id' => null,
        'probable_away_pitcher_espn_id' => null,
    ]);
    $unchangedGame = Game::factory()->create([
        'season' => 2026, 'season_type' => '2',
        'status' => 'STATUS_SCHEDULED', 'game_date' => $tomorrow,
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'probable_home_pitcher_espn_id' => '8001',
        'probable_away_pitcher_espn_id' => '8002',
    ]);

    // Stub the sync so it "discovers" that one game's probable pitcher just got set.
    $this->app->bind(SyncGamesFromScoreboard::class, fn () => new class($changedGame->id) extends SyncGamesFromScoreboard
    {
        public function __construct(private int $changedGameId)
        {
            // Skip parent constructor (avoid wiring HTTP-bound services in tests).
        }

        public function execute(string $date): int
        {
            Game::query()->where('id', $this->changedGameId)->update([
                'probable_home_pitcher_espn_id' => '7001',
                'probable_away_pitcher_espn_id' => '7002',
            ]);

            return 1;
        }
    });

    Artisan::call('mlb:refresh-probable-pitchers', ['--days-ahead' => 1]);
    $output = Artisan::output();

    expect($output)->toContain('1 game(s) had probable-pitcher changes');
    expect($output)->toContain('Regenerated predictions for 1 game(s)');

    // Exactly one prediction created — for the changed game, not the unchanged one.
    expect(Prediction::where('game_id', $changedGame->id)->count())->toBe(1);
    expect(Prediction::where('game_id', $unchangedGame->id)->count())->toBe(0);
});

it('honors --dry-run by syncing scoreboards but not regenerating predictions', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $tomorrow = now()->addDay()->toDateString();

    $game = Game::factory()->create([
        'season' => 2026, 'season_type' => '2',
        'status' => 'STATUS_SCHEDULED', 'game_date' => $tomorrow,
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'probable_home_pitcher_espn_id' => null,
        'probable_away_pitcher_espn_id' => null,
    ]);

    $this->app->bind(SyncGamesFromScoreboard::class, fn () => new class($game->id) extends SyncGamesFromScoreboard
    {
        public function __construct(private int $gameId) {}

        public function execute(string $date): int
        {
            Game::query()->where('id', $this->gameId)->update([
                'probable_home_pitcher_espn_id' => '7001',
                'probable_away_pitcher_espn_id' => '7002',
            ]);

            return 1;
        }
    });

    Artisan::call('mlb:refresh-probable-pitchers', ['--days-ahead' => 1, '--dry-run' => true]);
    $output = Artisan::output();

    expect($output)->toContain('[dry-run]');
    expect(Prediction::where('game_id', $game->id)->count())->toBe(0);
});
