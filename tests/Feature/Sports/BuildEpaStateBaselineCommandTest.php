<?php

use App\Models\EpaStateBaseline;
use App\Models\NFL\Game;
use App\Models\NFL\Play;
use App\Models\NFL\Team;
use Illuminate\Support\Facades\DB;

it('loads source plays in chunks instead of issuing one query per game', function () {
    $home = Team::factory()->create();
    $away = Team::factory()->create();

    foreach (range(1, 3) as $day) {
        $game = Game::factory()->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => 2025,
            'status' => 'STATUS_FINAL',
            'game_date' => "2025-09-0{$day}",
        ]);

        Play::factory()->create([
            'game_id' => $game->id,
            'possession_team_id' => $home->id,
            'sequence_number' => 1,
            'down' => 1,
            'distance' => 10,
            'yards_to_endzone' => 50,
            'is_epa_eligible' => true,
            'expected_points_before' => 2.5,
        ]);
    }

    $playSelects = 0;
    DB::listen(function ($query) use (&$playSelects): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'nfl_plays')) {
            $playSelects++;
        }
    });

    $this->artisan('sports:build-epa-state-baseline', [
        'sport' => 'nfl',
        '--season' => 2026,
        '--from-season' => 2025,
        '--min-samples' => 1,
    ])->assertSuccessful();

    expect($playSelects)->toBe(1)
        ->and(EpaStateBaseline::query()
            ->where('sport', 'nfl')
            ->where('season', 2026)
            ->sum('sample_size'))->toBe(3);
});
