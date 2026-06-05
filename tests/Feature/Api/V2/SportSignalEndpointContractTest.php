<?php

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

function actAsV2SignalContractUser(): User
{
    $user = User::factory()->create();

    Sanctum::actingAs($user);

    return $user;
}
