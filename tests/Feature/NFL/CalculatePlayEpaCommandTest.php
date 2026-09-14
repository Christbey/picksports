<?php

use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use App\Services\NFL\TrueEpaCalculator;
use Illuminate\Support\Collection;

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
