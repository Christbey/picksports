<?php

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Services\Epa\StateBaselineService;
use App\Services\NFL\TrueEpaCalculator;
use Illuminate\Support\Collection;

beforeEach(fn () => config(['nfl.predictions.true_epa.custom_epa_quarantined' => false]));

it('only loads games with unprocessed plays unless rebuild is requested', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $completedGame = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-09-13',
    ]);
    $completedPlay = Play::factory()->create([
        'game_id' => $completedGame->id,
        'is_epa_eligible' => false,
        'true_epa' => null,
        'epa_calculated_at' => now()->subMinute(),
    ]);

    $pendingGame = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'game_date' => '2026-09-12',
    ]);
    $pendingPlay = Play::factory()->create([
        'game_id' => $pendingGame->id,
        'is_epa_eligible' => false,
        'true_epa' => null,
        'epa_calculated_at' => null,
    ]);

    $calculator = Mockery::mock(TrueEpaCalculator::class);
    $calculator->shouldReceive('calculateForGame')
        ->once()
        ->withArgs(fn (Collection $plays): bool => $plays->pluck('id')->all() === [$pendingPlay->id])
        ->andReturn([
            $pendingPlay->id => [
                'eligible' => false,
                'ep_before' => null,
                'ep_after' => null,
                'epa' => null,
            ],
        ]);
    app()->instance(TrueEpaCalculator::class, $calculator);

    $this->artisan('nfl:calculate-play-epa', [
        '--season' => 2026,
        '--limit' => 1,
    ])->assertSuccessful();

    expect($completedPlay->fresh()->is_epa_eligible)->toBeFalse()
        ->and($pendingPlay->fresh()->is_epa_eligible)->toBeFalse()
        ->and($pendingPlay->fresh()->epa_calculated_at)->not->toBeNull();
});

it('loads scoring flags and periods when calculating EPA and leaves rows unchanged in dry runs', function () {
    config(['epa.state_baseline.enabled' => false]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026]);
    $attributes = ['game_id' => $game->id, 'period' => 2, 'down' => 1, 'distance' => 10,
        'yards_to_endzone' => 70, 'possession_team_id' => $home->id, 'home_score' => 7,
        'away_score' => 0, 'epa_calculated_at' => null, 'true_epa' => null, 'play_text' => ''];
    Play::factory()->create(array_merge($attributes, ['sequence_number' => 1, 'play_type' => 'Official Timeout', 'is_scoring_play' => false]));
    $touchdown = Play::factory()->create(array_merge($attributes, ['sequence_number' => 2, 'play_type' => 'Passing Touchdown', 'is_scoring_play' => true]));
    Play::factory()->create(array_merge($attributes, ['sequence_number' => 3, 'period' => 3, 'yards_to_endzone' => 40, 'play_type' => 'Rush', 'is_scoring_play' => false]));

    $this->artisan('nfl:calculate-play-epa', ['--game_id' => $game->id, '--dry-run' => true])->assertSuccessful();
    expect($touchdown->fresh()->epa_calculated_at)->toBeNull();

    $this->artisan('nfl:calculate-play-epa', ['--game_id' => $game->id])->assertSuccessful();
    $touchdown->refresh();
    expect((float) $touchdown->expected_points_before)->toBe(7.0)
        ->and((float) $touchdown->expected_points_after)->toBe(0.0)
        ->and((float) $touchdown->true_epa)->toBe(0.0);
});

it('retains the configured historical baseline while correcting scoring and halftime', function () {
    config(['epa.state_baseline.enabled' => true]);
    $baseline = Mockery::mock(StateBaselineService::class);
    $baseline->shouldReceive('getMap')->once()->with('nfl', 2026)->andReturn(['1|7-10|61-70' => 3.0]);
    app()->instance(StateBaselineService::class, $baseline);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $game = Game::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026]);
    $attributes = ['game_id' => $game->id, 'period' => 2, 'down' => 1, 'distance' => 10,
        'yards_to_endzone' => 70, 'possession_team_id' => $home->id, 'home_score' => 7,
        'away_score' => 0, 'epa_calculated_at' => null, 'true_epa' => null, 'play_text' => ''];
    Play::factory()->create(array_merge($attributes, ['sequence_number' => 1, 'play_type' => 'Official Timeout', 'is_scoring_play' => false]));
    $touchdown = Play::factory()->create(array_merge($attributes, ['sequence_number' => 2, 'play_type' => 'Passing Touchdown', 'is_scoring_play' => true]));
    Play::factory()->create(array_merge($attributes, ['sequence_number' => 3, 'period' => 3, 'play_type' => 'Rush', 'is_scoring_play' => false]));

    $this->artisan('nfl:calculate-play-epa', ['--game_id' => $game->id])->assertSuccessful();
    $touchdown->refresh();
    expect((float) $touchdown->expected_points_before)->toBe(3.0)
        ->and((float) $touchdown->expected_points_after)->toBe(0.0)
        ->and((float) $touchdown->true_epa)->toBe(4.0);
});
