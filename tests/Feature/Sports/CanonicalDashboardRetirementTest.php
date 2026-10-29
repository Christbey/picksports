<?php

use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Models\User;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    $this->withoutVite();
    Http::preventStrayRequests();
    Cache::flush();
});
afterEach(fn () => $this->travelBack());

function dashboardRetirementFixture(string $sport, int $daysForward = 0): array
{
    config(["prediction_lifecycle.canonical_reads.{$sport}" => true]);
    $context = app(SportContextResolver::class)->resolve($sport);
    $teamClass = $context->models['team'];
    $gameClass = $context->models['game'];
    $start = now()->addDays($daysForward)->addHours(2)->utc();
    $event = SportEvent::factory()->create(['sport' => $sport, 'season' => 2026, 'starts_at' => $start]);
    $game = $gameClass::factory()->create(['sport_event_id' => $event->id, 'season' => 2026, 'season_type' => '2',
        'game_date' => $start->toDateString(), 'game_time' => $start->toTimeString(), 'status' => 'STATUS_SCHEDULED',
        'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory()]);
    $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
    $input = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id]);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id, 'event_input_snapshot_id' => $input->id,
        'calculation_release_id' => $release->id, 'status' => 'succeeded']);
    $native = CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id,
        'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => 'draft',
        'generated_at' => now(), 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null]);
    foreach ([['moneyline', 'home', null, 0.7], ['spread', 'home', -4.5, null], ['total', 'combined', 40.0, null]] as [$type, $side, $line, $probability]) {
        PredictionMarket::factory()->create(['prediction_id' => $native->id, 'market_type' => $type,
            'selection' => $side, 'projected_line' => $line, 'probability' => $probability]);
    }
    $native->update(['publication_state' => 'published', 'published_at' => now()]);

    return [$game, $native->refresh()];
}

function dashboardRetirementLegacy(Model $game, string $sport): void
{
    $model = app(SportContextResolver::class)->resolve($sport)->models['prediction'];
    $model::create(['game_id' => $game->id, 'season' => 2026, 'season_type' => '2',
        'home_team_elo' => 1500, 'away_team_elo' => 1500, 'predicted_spread' => -20,
        'predicted_total' => 200, 'win_probability' => 0.1]);
}

it('inspects only selected canonical forecasts and stored game dates across every sport', function (string $sport) {
    [$game, $native] = dashboardRetirementFixture($sport);
    dashboardRetirementFixture($sport, daysForward: 1);
    dashboardRetirementLegacy($game, $sport);
    foreach (['draft', 'superseded', 'withdrawn'] as $index => $state) {
        $run = CalculationRun::factory()->create(['sport_event_id' => $native->sport_event_id,
            'event_input_snapshot_id' => $native->calculationRun->event_input_snapshot_id,
            'calculation_release_id' => $native->calculationRun->calculation_release_id, 'status' => 'succeeded']);
        CanonicalPrediction::factory()->create(['sport' => $sport, 'sport_event_id' => $native->sport_event_id,
            'calculation_run_id' => $run->id, 'phase' => 'pregame', 'publication_state' => $state,
            'revision' => $index + 2, 'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
            'generated_at' => now()->addDay(), 'published_at' => $state === 'draft' ? null : now()]);
    }
    $this->actingAs(User::factory()->admin()->create());
    DB::enableQueryLog();
    try {
        $response = $this->getJson('/api/v2/admin/payload-inspector?profile=dashboard&include_payload=true&sports='.$sport.'&date='.$game->game_date->toDateString())
            ->assertSuccessful()->assertJsonPath('data.payload.sports.0.predictions.available', true)
            ->assertJsonPath('data.payload.sports.0.predictions.source', 'canonical')
            ->assertJsonPath('data.payload.sports.0.predictions.for_date', 1)
            ->assertJsonPath('data.payload.sports.0.predictions.total', 2)
            ->assertJsonPath('data.payload.sports.0.dashboard_contract.status', 'passing');
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($response->json('data.payload.sports.0.predictions.latest_updated_at'))->toBe($native->generated_at->toIso8601String())
        ->and($queries)->not->toContain('"'.$sport.'_predictions"');
})->with(['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba']);

it('reports canonical gaps after withdrawal instead of hiding them behind legacy coverage', function () {
    [$game, $native] = dashboardRetirementFixture('nba');
    dashboardRetirementLegacy($game, 'nba');
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);
    $this->actingAs(User::factory()->admin()->create());
    $url = '/api/v2/admin/payload-inspector?profile=dashboard&include_payload=true&sports=nba&date='.$game->game_date->toDateString();
    $this->getJson($url)->assertSuccessful()->assertJsonPath('data.payload.sports.0.predictions.source', 'canonical')
        ->assertJsonPath('data.payload.sports.0.predictions.available', true)
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 0)
        ->assertJsonPath('data.payload.sports.0.predictions.total', 0)
        ->assertJsonPath('data.payload.sports.0.predictions.latest_updated_at', null)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.warnings.0.code', 'dashboard_prediction_gap');
    config(['prediction_lifecycle.canonical_reads.nba' => false]);
    $this->getJson($url)->assertSuccessful()->assertJsonPath('data.payload.sports.0.predictions.source', 'legacy')
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 1)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.status', 'passing');
});

it('reports unavailable canonical storage without falling back to legacy counts', function () {
    [$game] = dashboardRetirementFixture('nba');
    dashboardRetirementLegacy($game, 'nba');
    $this->mock(CanonicalSportPredictionQuery::class, function ($mock) {
        $mock->shouldReceive('supports')->andReturnTrue();
        $mock->shouldReceive('queryForSport')->once()->andThrow(new RuntimeException('storage unavailable'));
    });
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v2/admin/payload-inspector?profile=dashboard&include_payload=true&sports=nba&date='.$game->game_date->toDateString())
        ->assertSuccessful()->assertJsonPath('data.payload.sports.0.predictions.available', false)
        ->assertJsonPath('data.payload.sports.0.predictions.source', 'canonical')
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 0)
        ->assertJsonPath('data.payload.sports.0.dashboard_contract.warnings.0.code', 'dashboard_predictions_unavailable');
});

it('preserves MLB delayed-game presentation in both dashboard and live scoreboard', function () {
    [$game, $native] = dashboardRetirementFixture('mlb');
    $game->update(['status' => 'STATUS_DELAYED', 'home_score' => 4, 'away_score' => 2, 'inning' => 5, 'inning_state' => 'Top']);
    $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Dashboard')->has('sports', 1)
            ->where('sports.0.fullName', 'Major League Baseball')->where('sports.0.color', 'orange')
            ->where('sports.0.predictions.0.id', $native->public_id)->where('sports.0.predictions.0.is_live', true)
            ->where('sports.0.predictions.0.inning', 5)->where('sports.0.predictions.0.home_score', 4));
    $this->getJson('/api/v2/live-scoreboard')->assertSuccessful()->assertJsonCount(1, 'data.games')
        ->assertJsonPath('data.games.0.id', $native->public_id)->assertJsonPath('data.games.0.is_live', true)
        ->assertJsonPath('data.games.0.inning', 5)->assertJsonPath('data.games.0.home_score', 4);
});

it('retains permission filtering across the shared dashboard and scoreboard configuration', function () {
    [$nba] = dashboardRetirementFixture('nba');
    [$cfb] = dashboardRetirementFixture('cfb');
    $nba->update(['status' => 'STATUS_IN_PROGRESS']);
    $cfb->update(['status' => 'STATUS_IN_PROGRESS']);
    foreach (['nba', 'nfl', 'mlb', 'cbb', 'cfb', 'wcbb', 'wnba'] as $sport) {
        config(["sports.domains.{$sport}.web.requires_prediction_permission" => true]);
    }
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('dashboard'))->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('sports', 0));
    $this->getJson('/api/v2/live-scoreboard')->assertSuccessful()->assertJsonCount(0, 'data.games');
    $user->givePermissionTo(Permission::findOrCreate('view-nba-predictions', 'web'));
    $this->get(route('dashboard'))->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->has('sports', 1)->where('sports.0.name', 'NBA'));
    $this->getJson('/api/v2/live-scoreboard')->assertSuccessful()->assertJsonCount(1, 'data.games')
        ->assertJsonPath('data.games.0.sport', 'NBA');
});

it('keeps native diagnostics available without a registered legacy prediction model', function () {
    [$game] = dashboardRetirementFixture('nba');
    $context = app(SportContextResolver::class)->resolve('nba');
    $models = $context->models;
    unset($models['prediction']);
    $nativeContext = new SportContext($context->slug, $context->label, $context->namespace,
        $models, $context->resources, $context->capabilities, $context->web);
    $this->mock(SportContextResolver::class)->shouldReceive('find')->with('nba')->andReturn($nativeContext);
    $this->actingAs(User::factory()->admin()->create())
        ->getJson('/api/v2/admin/payload-inspector?profile=dashboard&include_payload=true&sports=nba&date='.$game->game_date->toDateString())
        ->assertSuccessful()->assertJsonPath('data.payload.sports.0.predictions.available', true)
        ->assertJsonPath('data.payload.sports.0.predictions.source', 'canonical')
        ->assertJsonPath('data.payload.sports.0.predictions.for_date', 1)
        ->assertJsonPath('data.payload.sports.0.v2_contracts.predictions.available', true);
});
