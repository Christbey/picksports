<?php

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

it('returns MLB daily picks from the v2 API as tracking only', function (): void {
    mlbPricedSlate();
    $this->artisan('mlb:generate-daily-picks --date=2026-06-20 --season=2026 --markets=moneyline,total,props')
        ->assertExitCode(0);

    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v2/sports/mlb/daily-picks?date=2026-06-20&season=2026')
        ->assertOk()
        ->assertJsonPath('data.mode', 'tracking_only')
        ->assertJsonPath('data.public_promoted_count', 0)
        ->assertJsonPath('data.top_picks.0.is_bet', false);

    expect($response->json('data.candidate_count'))->toBeGreaterThan(0)
        ->and($response->json('data.top_picks'))->not->toBeEmpty();
});
