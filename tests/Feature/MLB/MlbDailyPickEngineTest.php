<?php

use App\Actions\MLB\GradePredictions;
use App\Models\MLB\Game;
use App\Models\MLB\PickCandidate;
use App\Models\MLB\PlayerProp;
use App\Models\MLB\Prediction;
use App\Models\MLB\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

function mlbPricedSlate(array $overrides = []): array
{
    $home = Team::factory()->create([
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Royals',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'STL',
        'location' => 'St. Louis',
        'name' => 'Cardinals',
    ]);
    $game = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-20 19:10:00',
        'game_time' => '19:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => null,
        'away_score' => null,
        'short_name' => 'STL @ KC',
        'probable_home_pitcher_espn_id' => '1001',
        'probable_away_pitcher_espn_id' => '1002',
        'odds_updated_at' => now(),
        'odds_data' => [
            'bookmakers' => [[
                'key' => 'draftkings',
                'title' => 'DraftKings',
                'markets' => [
                    [
                        'key' => 'h2h',
                        'outcomes' => [
                            ['name' => 'Kansas City Royals', 'price' => -115],
                            ['name' => 'St. Louis Cardinals', 'price' => -105],
                        ],
                    ],
                    [
                        'key' => 'spreads',
                        'outcomes' => [
                            ['name' => 'Kansas City Royals', 'price' => 145, 'point' => -1.5],
                            ['name' => 'St. Louis Cardinals', 'price' => -165, 'point' => 1.5],
                        ],
                    ],
                    [
                        'key' => 'totals',
                        'outcomes' => [
                            ['name' => 'Over', 'price' => -110, 'point' => 8.5],
                            ['name' => 'Under', 'price' => -110, 'point' => 8.5],
                        ],
                    ],
                    [
                        'key' => 'h2h_1st_5_innings',
                        'outcomes' => [
                            ['name' => 'Kansas City Royals', 'price' => 105],
                            ['name' => 'St. Louis Cardinals', 'price' => -125],
                        ],
                    ],
                ],
            ]],
        ],
        ...$overrides,
    ]);
    $prediction = Prediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_elo' => 1540,
        'away_team_elo' => 1500,
        'home_pitcher_elo' => 1580,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1560,
        'away_combined_elo' => 1490,
        'predicted_spread' => 1.8,
        'predicted_total' => 10.2,
        'win_probability' => 0.61,
        'confidence_score' => 58,
        'model_version' => 'test',
        'feature_version' => 'core-v3',
        'blend_version' => 'test',
        'model_metadata' => [],
    ]);

    PlayerProp::query()->create([
        'game_id' => $game->id,
        'player_name' => 'Bobby Witt Jr.',
        'market' => 'batter_total_bases',
        'bookmaker' => 'DraftKings',
        'line' => 1.5,
        'over_price' => 110,
        'under_price' => -130,
        'fetched_at' => now(),
        'recommended_side' => 'over',
        'confidence_score' => 72,
        'predicted_over_probability' => 61,
        'market_over_probability' => 49,
        'edge_probability' => 12,
        'data_quality_score' => 90,
        'match_quality_score' => 86,
    ]);

    return [$game->refresh(), $prediction, $home, $away];
}

it('generates dry-run MLB daily pick candidates without persisting rows', function (): void {
    mlbPricedSlate();

    $this->artisan('mlb:generate-daily-picks --date=2026-06-20 --season=2026 --dry-run')
        ->assertExitCode(0);

    expect(PickCandidate::query()->count())->toBe(0);
});

it('persists tracking-only MLB candidates with scores reasons and risks', function (): void {
    mlbPricedSlate();

    $this->artisan('mlb:generate-daily-picks --date=2026-06-20 --season=2026 --markets=moneyline,total,run_line,first_5,props')
        ->assertExitCode(0);

    $candidate = PickCandidate::query()->where('market_type', 'moneyline')->orderByDesc('score')->first();

    expect($candidate)->not->toBeNull()
        ->and($candidate->is_bet)->toBeFalse()
        ->and($candidate->is_public)->toBeFalse()
        ->and($candidate->is_tracking_only)->toBeTrue()
        ->and($candidate->recommendation_label)->toBe('tracking_only')
        ->and($candidate->score)->toBeGreaterThan(0)
        ->and($candidate->reason_codes)->toContain('model_market_agrees')
        ->and(data_get($candidate->feature_snapshot, 'internal_candidate_label'))->not->toBeNull();
});

it('applies MLB total bias correction to total candidates', function (): void {
    config(['mlb.picks.total_bias_correction' => 1.0]);
    mlbPricedSlate();

    $this->artisan('mlb:generate-daily-picks --date=2026-06-20 --season=2026 --markets=total')
        ->assertExitCode(0);

    $over = PickCandidate::query()->where('market_type', 'total')->where('side', 'over')->firstOrFail();

    expect((float) data_get($over->feature_snapshot, 'corrected_projected_total'))->toBe(9.2)
        ->and((float) $over->line)->toBe(8.5)
        ->and((float) $over->projected_value)->toBeGreaterThan(0);
});

it('stores MLB over under results when predictions are graded', function (): void {
    [$game, $prediction] = mlbPricedSlate([
        'status' => 'STATUS_FINAL',
        'home_score' => 6,
        'away_score' => 5,
    ]);

    $prediction->forceFill([
        'model_metadata' => [
            'market_context' => [
                'market_total' => 8.5,
            ],
        ],
    ])->save();

    app(GradePredictions::class)->executeForGame($game->id);

    $prediction->refresh();

    expect($prediction->total_pick_side)->toBe('over')
        ->and((float) $prediction->total_pick_line)->toBe(8.5)
        ->and($prediction->total_pick_result)->toBe('win')
        ->and((float) $prediction->total_pick_edge)->toBe(1.7);
});

it('returns MLB over under results from the v2 prediction API', function (): void {
    [$game, $prediction] = mlbPricedSlate([
        'status' => 'STATUS_FINAL',
        'home_score' => 3,
        'away_score' => 2,
    ]);

    $prediction->forceFill([
        'model_metadata' => [
            'market_context' => [
                'market_total' => 8.5,
            ],
        ],
    ])->save();

    app(GradePredictions::class)->executeForGame($game->id);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/mlb/predictions?from_date=2026-06-20&to_date=2026-06-20&season=2026&per_page=100')
        ->assertOk()
        ->assertJsonPath('data.0.total_pick_side', 'over')
        ->assertJsonPath('data.0.total_pick_line', 8.5)
        ->assertJsonPath('data.0.total_pick_result', 'loss')
        ->assertJsonPath('data.0.total_result.side', 'over')
        ->assertJsonPath('data.0.total_result.line', 8.5)
        ->assertJsonPath('data.0.total_result.result', 'loss')
        ->assertJsonPath('data.0.total_result.actual_total', 5);
});

it('returns MLB daily picks from the v2 API as tracking only', function (): void {
    mlbPricedSlate();
    $this->artisan('mlb:generate-daily-picks --date=2026-06-20 --season=2026 --markets=moneyline,total,props')
        ->assertExitCode(0);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v2/sports/mlb/daily-picks?date=2026-06-20&season=2026')
        ->assertOk()
        ->assertJsonPath('data.mode', 'tracking_only')
        ->assertJsonPath('data.public_promoted_count', 0)
        ->assertJsonPath('data.summary.public_promoted_count', 0)
        ->assertJsonPath('data.board_health.status', 'tracking_ready')
        ->assertJsonPath('data.market_counts.all', 5)
        ->assertJsonPath('data.achievements.0.key', 'clean_slate')
        ->assertJsonPath('data.performance_summary.sample_warning', 'Sample too small for public betting validation.')
        ->assertJsonPath('data.top_picks.0.is_bet', false);

    expect($response->json('data.candidate_count'))->toBeGreaterThan(0)
        ->and($response->json('data.top_picks'))->not->toBeEmpty();
});

it('returns slate counts even when no MLB daily pick candidates exist', function (): void {
    mlbPricedSlate([
        'odds_data' => null,
        'odds_updated_at' => null,
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/mlb/daily-picks?date=2026-06-20&season=2026')
        ->assertOk()
        ->assertJsonPath('data.candidate_count', 0)
        ->assertJsonPath('data.summary.slate_games', 1)
        ->assertJsonPath('data.summary.priced_games', 0)
        ->assertJsonPath('data.board_health.status', 'needs_odds')
        ->assertJsonPath('data.market_counts.all', 0)
        ->assertJsonPath('data.achievements.0.key', 'no_force_picks');
});

it('does not leak next local date games into the MLB daily pick slate', function (): void {
    [, , $home, $away] = mlbPricedSlate([
        'odds_data' => null,
        'odds_updated_at' => null,
        'game_date' => '2026-06-20 00:00:00',
        'game_time' => '16:10:00',
    ]);

    Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-21 00:00:00',
        'game_time' => '13:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'short_name' => 'KC @ STL',
    ]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v2/sports/mlb/daily-picks?date=2026-06-20&season=2026')
        ->assertOk()
        ->assertJsonPath('data.summary.slate_games', 1)
        ->assertJsonPath('data.summary.priced_games', 0);
});

it('uses the same local date window for MLB v2 prediction queries', function (): void {
    [$game, , $home, $away] = mlbPricedSlate([
        'game_date' => '2026-06-20 00:00:00',
        'game_time' => '16:10:00',
    ]);

    $nextDayGame = Game::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-21 00:00:00',
        'game_time' => '13:10:00',
        'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $away->id,
        'away_team_id' => $home->id,
        'short_name' => 'KC @ STL',
    ]);

    Prediction::query()->create([
        'game_id' => $nextDayGame->id,
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'home_team_elo' => 1500,
        'away_team_elo' => 1500,
        'home_pitcher_elo' => 1500,
        'away_pitcher_elo' => 1500,
        'home_combined_elo' => 1500,
        'away_combined_elo' => 1500,
        'predicted_spread' => 0.1,
        'predicted_total' => 8.4,
        'win_probability' => 0.51,
        'confidence_score' => 50,
        'model_version' => 'test',
        'feature_version' => 'core-v3',
        'blend_version' => 'test',
        'model_metadata' => [],
    ]);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v2/sports/mlb/predictions?from_date=2026-06-20&to_date=2026-06-20&season=2026&per_page=100')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.game.id'))->toBe($game->id)
        ->and($response->json('data.0.game.game_time'))->toBe('16:10:00');
});
