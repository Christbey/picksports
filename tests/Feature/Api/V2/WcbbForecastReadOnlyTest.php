<?php

use App\Actions\WCBB\GenerateTournamentForecast;
use App\Models\User;
use App\Models\WCBB\Team;
use App\Models\WCBB\TeamMetric;
use App\Models\WCBB\TournamentForecast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

it('reads stored WCBB forecasts without generation or forecast SQL writes on a cold cache', function (string $state) {
    Cache::flush();
    Http::preventStrayRequests();
    config(['wcbb.tournament_forecast.refresh.enabled' => true]);
    Sanctum::actingAs(User::factory()->create());
    $team = Team::factory()->create();
    TeamMetric::query()->create(['team_id' => $team->id, 'season' => 2026, 'meets_minimum' => true]);
    if ($state !== 'missing') {
        TournamentForecast::factory()->create(['team_id' => $team->id, 'season' => 2026, 'champion_probability' => 0.18,
            'updated_at' => $state === 'stale' ? now()->subDay() : now()]);
    }
    $before = TournamentForecast::query()->orderBy('id')->get()->map->getAttributes()->all();
    $generated = 0;
    $generator = Mockery::mock(GenerateTournamentForecast::class);
    $generator->shouldReceive('execute')->andReturnUsing(function ($season) use ($team, &$generated) {
        $generated++;
        $row = TournamentForecast::query()->updateOrCreate(['team_id' => $team->id, 'season' => $season],
            ['champion_probability' => 0.99, 'simulation_runs' => 100]);

        return collect([$row]);
    });
    app()->instance(GenerateTournamentForecast::class, $generator);
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/\A\s*(?:insert|update|delete)\b/i', $query->sql)
            && str_contains(strtolower($query->sql), 'wcbb_tournament_forecasts')) {
            $writes[] = $query->sql;
        }
    });
    $response = $this->getJson('/api/v2/sports/wcbb/forecasts?season=2026')->assertOk();
    expect($generated)->toBe(0)->and($writes)->toBe([])
        ->and(TournamentForecast::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
    $response->assertJsonCount($state === 'missing' ? 0 : 1, 'data');
    if ($state !== 'missing') {
        $response->assertJsonPath('data.0.champion_probability', 0.18);
    }
    Http::assertNothingSent();
})->with(['missing', 'stale', 'fresh']);
