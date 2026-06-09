<?php

use App\Actions\Validation\Checks\OddsCompletenessCheck;
use App\Models\NBA\Game;
use App\Models\NBA\Team;

test('odds completeness warns for partial missing provider coverage below the hard fail threshold', function () {
    config()->set('validation.thresholds.odds_completeness.missing_or_stale_fail_pct', 0.50);
    config()->set('validation.thresholds.odds_completeness.soft_availability_hours', 9999);
    config()->set('validation.thresholds.odds_completeness.expected_availability_hours', 9999);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameDate = now()->copy()->addDay()->startOfDay()->addHours(19);

    Game::factory()
        ->count(10)
        ->sequence(fn ($sequence) => ['espn_event_id' => 'complete-'.$sequence->index])
        ->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => (int) now()->year,
            'season_type' => 2,
            'status' => 'STATUS_SCHEDULED',
            'game_date' => $gameDate,
            'game_time' => '19:00:00',
            'odds_api_event_id' => fn () => fake()->uuid(),
            'odds_data' => completeOddsPayload(),
            'odds_updated_at' => now(),
        ]);

    Game::factory()
        ->count(4)
        ->sequence(fn ($sequence) => ['espn_event_id' => 'missing-'.$sequence->index])
        ->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => (int) now()->year,
            'season_type' => 2,
            'status' => 'STATUS_SCHEDULED',
            'game_date' => $gameDate,
            'game_time' => '19:00:00',
            'odds_api_event_id' => null,
            'odds_data' => null,
            'odds_updated_at' => null,
        ]);

    $result = app(OddsCompletenessCheck::class)->run('nba', config('validation.sports.nba'));

    expect($result['status'])->toBe('warning')
        ->and($result['metadata']['market_ready_games'])->toBe(14)
        ->and($result['metadata']['blocking_odds_problem_games'])->toBe(4)
        ->and($result['metadata']['missing_or_stale_fail_pct'])->toBe(0.50);
});

test('odds completeness fails when missing or stale odds affect most market ready games', function () {
    config()->set('validation.thresholds.odds_completeness.missing_or_stale_fail_pct', 0.50);
    config()->set('validation.thresholds.odds_completeness.soft_availability_hours', 9999);
    config()->set('validation.thresholds.odds_completeness.expected_availability_hours', 9999);

    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $gameDate = now()->copy()->addDay()->startOfDay()->addHours(19);

    Game::factory()
        ->count(4)
        ->sequence(fn ($sequence) => ['espn_event_id' => 'missing-'.$sequence->index])
        ->create([
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'season' => (int) now()->year,
            'season_type' => 2,
            'status' => 'STATUS_SCHEDULED',
            'game_date' => $gameDate,
            'game_time' => '19:00:00',
            'odds_data' => null,
            'odds_updated_at' => null,
        ]);

    $result = app(OddsCompletenessCheck::class)->run('nba', config('validation.sports.nba'));

    expect($result['status'])->toBe('failing')
        ->and($result['metadata']['market_ready_games'])->toBe(4)
        ->and($result['metadata']['blocking_odds_problem_games'])->toBe(4);
});

test('odds completeness treats provider unavailable odds outside the expected window as availability info', function () {
    $this->travelTo('2026-06-09 15:00:00');

    $home = Team::factory()->create();
    $away = Team::factory()->create();

    $game = Game::factory()->create([
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'season' => 2026,
        'season_type' => 2,
        'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-06-10',
        'game_time' => '19:00:00',
        'odds_api_event_id' => fake()->uuid(),
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    $result = app(OddsCompletenessCheck::class)->run('nba', config('validation.sports.nba'));
    $sampleGame = collect(data_get($result, 'metadata.sample_games'))->firstWhere('game_id', $game->id);

    expect($result['status'])->toBe('passing')
        ->and($result['metadata']['market_ready_games'])->toBe(1)
        ->and($result['metadata']['blocking_odds_problem_games'])->toBe(0)
        ->and($result['metadata']['games_missing_odds'])->toBe(1)
        ->and($result['metadata']['provider_unavailable_far_odds'])->toBe(1)
        ->and($result['metadata']['provider_unavailable_expected_window_odds'])->toBe(0)
        ->and($result['metadata']['sample_game_ids'])->toBe([])
        ->and($result['metadata']['sample_missing_odds_game_ids'])->toContain($game->id)
        ->and($result['metadata']['sample_expected_missing_odds_game_ids'])->toBe([])
        ->and(data_get($sampleGame, 'odds_availability_bucket'))->toBe('early')
        ->and(data_get($sampleGame, 'flagged'))->toBeFalse();
});

function completeOddsPayload(): array
{
    return [
        'bookmakers' => [
            [
                'key' => 'draftkings',
                'markets' => [
                    ['key' => 'h2h'],
                    ['key' => 'spreads'],
                    ['key' => 'totals'],
                ],
            ],
        ],
    ];
}
