<?php

use App\Jobs\NFL\RetryGameResearch;
use App\Models\CFB\Game as CfbGame;
use App\Models\CFB\PlayerProp as CfbPlayerProp;
use App\Models\CFB\Team as CfbTeam;
use App\Models\NFL\Game as NflGame;
use App\Models\NFL\Team as NflTeam;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    Cache::flush();
    config(['subscriptions.enforce_tiers' => false]);
});

function selectedContractSpec(): array
{
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

function selectedContractAdmin(): User
{
    $user = User::factory()->admin()->create();
    Sanctum::actingAs($user);

    return $user;
}

function selectedRetryGame(): NflGame
{
    return NflGame::factory()->create(['home_team_id' => NflTeam::factory(), 'away_team_id' => NflTeam::factory(),
        'game_date' => now()->addDays(2)->toDateString(), 'game_time' => '18:00:00', 'status' => 'STATUS_SCHEDULED']);
}

it('declares actual queued retry and duplicate response fields without making them required for idle', function () {
    selectedContractAdmin();
    config(['nfl_research.enabled' => true, 'ai.features.nfl_game_context_research.enabled' => true]);
    $game = selectedRetryGame();
    $url = '/api/v2/admin/nfl/games/'.$game->id.'/research-retry';
    $first = $this->postJson($url)->assertAccepted();
    $duplicate = $this->postJson($url)->assertAccepted()->assertJsonPath('data.run_id', $first->json('data.run_id'));
    $this->getJson($url)->assertOk()->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(RetryGameResearch::class, 1);
    $schema = selectedContractSpec()['components']['schemas']['NflResearchRetryResponse'];
    expect(array_keys($schema['properties']['data']['properties']))->toContain(...array_keys($first->json('data')))
        ->and(array_keys($schema['properties']))->toContain(...array_keys($duplicate->json()))
        ->and($schema['properties']['data']['required'])->toBe(['status'])
        ->and($schema['properties']['data']['additionalProperties'])->toBeFalse()
        ->and($schema['additionalProperties'])->toBeFalse();
});

it('documents the actual disabled retry 422 normalized error without changing the queued 202', function () {
    selectedContractAdmin();
    config(['nfl_research.enabled' => false]);
    $game = selectedRetryGame();
    $url = '/api/v2/admin/nfl/games/'.$game->id.'/research-retry';
    $this->postJson($url)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertHeader('X-Request-ID');
    Queue::assertNothingPushed();
    $responses = selectedContractSpec()['paths']['/api/v2/admin/nfl/games/{game}/research-retry']['post']['responses'];
    expect($responses)->toHaveKeys(['202', '422'])
        ->and($responses['422']['$ref'])->toBe('#/components/responses/ValidationError');
});

it('admits the actual CFB raw prop current flag while leaving it optional for other sports', function () {
    selectedContractAdmin();
    $game = CfbGame::factory()->create(['home_team_id' => CfbTeam::factory(), 'away_team_id' => CfbTeam::factory()]);
    $prop = CfbPlayerProp::create(['game_id' => $game->id, 'player_name' => 'Synthetic prop player', 'market' => 'player_pass_yds',
        'line' => 0, 'is_current' => true, 'fetched_at' => now()]);
    $this->getJson('/api/v2/sports/cfb/player-props?only_ungraded=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $prop->id)->assertJsonPath('data.0.is_current', true)->assertJsonPath('data.0.line', 0);
    $schema = selectedContractSpec()['components']['schemas']['SportPlayerProp'];
    expect($schema['properties'])->toHaveKey('is_current')
        ->and($schema['properties']['is_current']['type'])->toBe('boolean')
        ->and($schema['required'])->not->toContain('is_current')
        ->and($schema['additionalProperties'])->toBeFalse();
});

it('documents strictly positive prop route and query IDs after actual malformed path refusals', function (string $scope, string $parameter, string $bad) {
    selectedContractAdmin();
    $this->getJson("/api/v2/sports/nba/{$scope}/{$bad}/player-props")->assertUnprocessable();
    $spec = selectedContractSpec();
    $path = '/api/v2/sports/{sport}/'.$scope.'/{'.$parameter.'}/player-props';
    $parameters = collect($spec['paths'][$path]['get']['parameters']);
    expect($parameters->firstWhere('name', $parameter)['schema'])->toBe(['type' => 'integer', 'minimum' => 1]);
    foreach (['game_id', 'player_id'] as $filter) {
        expect($parameters->firstWhere('name', $filter)['schema']['minimum'])->toBe(1);
    }
    $lookup = collect($spec['paths']['/api/v2/sports/{sport}/games/{game}']['get']['parameters'])->firstWhere('name', 'game')['schema'];
    expect($lookup['oneOf'][1]['pattern'])->toBe('^[0-9A-HJKMNP-TV-Z]{26}$');
})->with([['games', 'game', '0'], ['players', 'player', 'junk']]);

it('describes the real prediction phase and CFB-only board filters without changing other sport requests', function () {
    selectedContractAdmin();
    $this->getJson('/api/v2/sports/cfb/predictions?view=board&season=2026&season_type=2&has_value=0')->assertOk();
    $this->getJson('/api/v2/sports/nba/predictions?view=board')->assertUnprocessable();
    $parameters = collect(selectedContractSpec()['paths']['/api/v2/sports/{sport}/predictions']['get']['parameters']);
    expect($parameters->firstWhere('name', 'season_type')['schema']['type'])->toBe('string')
        ->and($parameters->pluck('name')->all())->toContain('view', 'board_version', 'has_value')
        ->and($parameters->firstWhere('name', 'view')['schema']['description'])->toContain('CFB')
        ->and($parameters->firstWhere('name', 'board_version')['schema']['pattern'])->toBe('^[a-f0-9]{64}$');
});

it('documents supported ungraded prop filtering while retaining internal research event IDs', function () {
    $spec = selectedContractSpec();
    $parameters = collect($spec['paths']['/api/v2/sports/{sport}/player-props']['get']['parameters']);
    expect($parameters->pluck('name')->all())->toContain('only_ungraded')
        ->and($spec['components']['schemas']['GameResearchSummaryData']['properties']['sport_event_id']['type'])->toBe(['integer', 'null']);
});

it('documents a real numeric-throttle telemetry 429 without sending a burst or any external report', function () {
    $path = '/api/v2/security/reports/csp';
    $request = Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.44']);
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);
    $throttle = new class(app(RateLimiter::class)) extends ThrottleRequests
    {
        public function fixtureSignature(Request $request): string
        {
            return $this->resolveRequestSignature($request);
        }
    };
    $key = $throttle->fixtureSignature($request);
    $limiter = app(RateLimiter::class);
    $limiter->increment($key, 60, 10000);
    try {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.44'])->postJson($path, [])
            ->assertStatus(429)->assertJsonPath('error.code', 'rate_limit_exceeded');
    } finally {
        $limiter->clear($key);
    }
    $responses = selectedContractSpec()['paths'][$path]['post']['responses'];
    expect($responses)->toHaveKey('429')->and($responses['429']['$ref'])->toBe('#/components/responses/RateLimited');
});
