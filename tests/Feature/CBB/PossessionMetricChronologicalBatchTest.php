<?php

use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamPossessionMetric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('processes each selected final game once in chronological possession windows', function (bool $reverseIds, int $games, int $chunk, ?int $limit) {
    Http::preventStrayRequests();
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $days = range(1, $games);
    if ($reverseIds) {
        $days = array_reverse($days);
    }
    foreach ($days as $day) {
        $game = Game::factory()->create([
            'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL',
            'home_team_id' => $home->id, 'away_team_id' => $away->id,
            'game_date' => CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($day - 1)->toDateString(),
            'game_time' => '18:00:00', 'period' => 2, 'home_score' => $day, 'away_score' => 0,
        ]);
        Play::factory()->create([
            'game_id' => $game->id, 'possession_team_id' => $home->id, 'sequence_number' => 1,
            'period' => 1, 'clock' => '19:00', 'home_score' => $day, 'away_score' => 0,
        ]);
        Play::factory()->create([
            'game_id' => $game->id, 'possession_team_id' => $away->id, 'sequence_number' => 2,
            'period' => 1, 'clock' => '18:00', 'home_score' => $day, 'away_score' => 0,
        ]);
    }
    $options = ['--season' => 2026, '--chunk' => $chunk];
    if ($limit !== null) {
        $options['--limit-games'] = $limit;
    }
    $exit = Artisan::call('cbb:calculate-possession-metrics', $options);
    $metric = TeamPossessionMetric::query()->where('team_id', $home->id)->sole();
    $selected = $limit ?? $games;
    expect($exit)->toBe(0)
        ->and($metric->games_sampled)->toBe($selected)
        ->and($metric->offensive_possessions)->toBe($selected)
        ->and($metric->rolling_games_sampled)->toBe(10)
        ->and((float) $metric->offensive_points_per_possession)->toBe(($selected + 1) / 2)
        ->and((float) $metric->rolling_offensive_points_per_possession)->toBe($selected - 4.5)
        ->and($metric->as_of_date->toDateString())->toBe(CarbonImmutable::parse('2026-01-01', 'UTC')->addDays($selected - 1)->toDateString());
    Http::assertNothingSent();
})->with([
    'chronological IDs across two batches' => [false, 26, 25, null],
    'reverse chronological IDs across two batches' => [true, 26, 25, null],
    'reverse IDs in one batch' => [true, 26, 200, null],
    'reverse IDs with an explicit chronological game limit' => [true, 30, 25, 26],
]);
