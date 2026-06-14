<?php

use App\Models\MLB\Game as MlbGame;
use App\Models\MLB\Prediction as MlbPrediction;
use App\Models\MLB\Team as MlbTeam;
use App\Models\User;
use App\Services\MLB\MlbBettingSignalService;
use App\Services\NBA\NbaBettingSignalService;
use App\Services\NFL\NflBettingSignalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;

dataset('v2SignalContractSports', [
    'nba' => ['nba', NbaBettingSignalService::class],
    'mlb' => ['mlb', MlbBettingSignalService::class],
    'nfl' => ['nfl', NflBettingSignalService::class],
]);

it('requires authenticated access for v2 signal endpoints', function (string $slug) {
    $this->getJson("/api/v2/sports/{$slug}/signals")
        ->assertUnauthorized();
})->with('v2SignalContractSports');

it('returns clean json 404 responses for unsupported v2 signal endpoints', function () {
    actAsV2SignalContractUser();

    $this->getJson('/api/v2/sports/nhl/signals')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');

    $this->getJson('/api/v2/sports/cbb/signals')
        ->assertNotFound()
        ->assertJsonPath('message', 'Betting signals are not available for cbb.');
});

it('lists v2 betting signals with stable metadata', function (string $slug, string $serviceClass) {
    Cache::flush();
    Carbon::setTestNow('2026-06-04 12:00:00');
    actAsV2SignalContractUser();

    $this->mock($serviceClass)
        ->shouldReceive('signals')
        ->once()
        ->with(2026, Mockery::on(fn (Carbon $date): bool => $date->toDateString() === '2026-06-01'))
        ->andReturn([
            'summary' => [
                'strong' => 1,
                'lean' => 2,
            ],
            'recommendations' => [
                [
                    'id' => 101,
                    'classification' => 'bet',
                    'market' => 'moneyline',
                ],
            ],
        ]);

    $response = $this->getJson("/api/v2/sports/{$slug}/signals?season=2026&as_of_date=2026-06-01")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'summary',
                'recommendations',
            ],
            'meta' => [
                'version',
                'sport',
                'contract',
                'filters',
                'tier',
                'freshness',
                'warnings',
            ],
        ])
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.sport', $slug)
        ->assertJsonPath('meta.contract', 'sports.signals.index')
        ->assertJsonPath('meta.filters.season', 2026)
        ->assertJsonPath('meta.filters.as_of_date', '2026-06-01')
        ->assertJsonPath('data.summary.strong', 1)
        ->assertJsonPath('data.recommendations.0.classification', 'bet');

    expect($response->json('meta.freshness'))->toBeArray()
        ->and($response->json('meta.warnings'))->toBeArray();

    Carbon::setTestNow();
})->with('v2SignalContractSports');

it('exposes mlb live monitor signals through the v2 signal endpoint', function () {
    Cache::flush();
    Carbon::setTestNow('2026-06-14 15:30:00');
    actAsV2SignalContractUser();

    $homeTeam = MlbTeam::factory()->create([
        'location' => 'St. Louis',
        'name' => 'Cardinals',
        'abbreviation' => 'STL',
    ]);
    $awayTeam = MlbTeam::factory()->create([
        'location' => 'Chicago',
        'name' => 'Cubs',
        'abbreviation' => 'CHC',
    ]);
    $game = MlbGame::factory()->create([
        'season' => 2026,
        'season_type' => config('mlb.season.types.regular'),
        'game_date' => '2026-06-14',
        'game_time' => '15:05:00',
        'status' => config('mlb.statuses.in_progress'),
        'inning' => 7,
        'inning_half' => 'bottom',
        'home_score' => 4,
        'away_score' => 3,
        'home_team_id' => $homeTeam->id,
        'away_team_id' => $awayTeam->id,
        'short_name' => 'CHC @ STL',
    ]);

    MlbPrediction::query()->create([
        'game_id' => $game->id,
        'season' => 2026,
        'season_type' => (string) config('mlb.season.types.regular'),
        'home_team_elo' => 1510,
        'away_team_elo' => 1490,
        'home_pitcher_elo' => 1525,
        'away_pitcher_elo' => 1480,
        'home_combined_elo' => 1520,
        'away_combined_elo' => 1485,
        'predicted_spread' => 1.2,
        'predicted_total' => 8.4,
        'win_probability' => 0.58,
        'confidence_score' => 61,
        'live_predicted_spread' => 1.8,
        'live_win_probability' => 0.78,
        'live_predicted_total' => 8.1,
        'live_outs_remaining' => 15,
        'live_updated_at' => now(),
        'model_version' => 'test',
        'feature_version' => 'test',
        'blend_version' => 'test',
    ]);

    $this->getJson('/api/v2/sports/mlb/signals?season=2026&as_of_date=2026-06-14')
        ->assertOk()
        ->assertJsonPath('meta.contract', 'sports.signals.index')
        ->assertJsonPath('data.live.0.type', 'live')
        ->assertJsonPath('data.live.0.matchup', 'CHC @ STL')
        ->assertJsonPath('data.live.0.live_win_probability', 0.78)
        ->assertJsonPath('data.live.0.is_stale', false)
        ->assertJsonPath('data.live.0.signal', 'major_live_swing');

    Carbon::setTestNow();
});

function actAsV2SignalContractUser(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}
