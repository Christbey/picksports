<?php

use App\Models\DeviceSession;
use App\Models\User;
use App\Services\Auth\Policy\ApiTokenPolicyAuthenticator;
use App\Services\Auth\TokenAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['api_tokens.finite_issuance_enabled' => true, 'api_tokens.ttl_minutes' => 60]);
});

function finitePolicyLoginPayload(string $profile = 'interactive'): array
{
    User::factory()->create(['email' => 'finite-policy@example.com', 'password' => Hash::make('fixture-password')]);

    return ['email' => 'finite-policy@example.com', 'password' => 'fixture-password', 'device_name' => 'fixture-api', 'token_profile' => $profile];
}

it('issues explicit finite interactive credentials instead of wildcard authority', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25T18:00:00Z'));
    $response = $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload())->assertOk();
    $token = User::where('email', 'finite-policy@example.com')->firstOrFail()->tokens()->sole();
    expect($token->abilities)->toBe(['mobile:read', 'mobile:write']);
    $response->assertJsonPath('expires_in', 3600)->assertJsonPath('token_profile', 'interactive')
        ->assertJsonPath('expires_at', '2026-11-25T19:00:00Z');
});

it('expires new credentials at the exact epoch through the HTTP guard', function (string $issuedAt) {
    $start = CarbonImmutable::parse($issuedAt);
    $this->travelTo($start);
    expect(CarbonImmutable::now('UTC')->getTimestamp())->toBe($start->getTimestamp());
    $token = $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload())->assertOk()->json('access_token');
    $this->travelTo($start->addSeconds(3599));
    expect(CarbonImmutable::now('UTC')->getTimestamp())->toBe($start->getTimestamp() + 3599);
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertOk();
    $this->travelTo($start->addSeconds(3600));
    expect(CarbonImmutable::now('UTC')->getTimestamp())->toBe($start->getTimestamp() + 3600);
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertUnauthorized();
})->with(['2026-11-25T18:00:00Z', '2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z']);

it('allows an explicit read-only profile to read but refuses mutations', function () {
    $token = $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload('read_only'))->assertOk()->json('access_token');
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertOk();
    Auth::forgetGuards();
    $this->withToken($token)->postJson('/api/v2/auth/logout')->assertForbidden();
});

it('rejects an unknown token profile before inserting credentials', function () {
    $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload('administrator'))->assertUnprocessable();
    expect(User::where('email', 'finite-policy@example.com')->firstOrFail()->tokens()->count())->toBe(0);
});

it('preserves legacy issuance while activation is off and refuses unavailable profiles', function () {
    config(['api_tokens.finite_issuance_enabled' => false]);
    $payload = finitePolicyLoginPayload();
    $this->postJson('/api/v2/auth/login', $payload)->assertOk()->assertJsonPath('token_profile', 'legacy_interactive')->assertJsonPath('expires_at', null);
    $user = User::where('email', $payload['email'])->firstOrFail();
    expect($user->tokens()->sole()->abilities)->toBe(['*']);
    $this->postJson('/api/v2/auth/login', [...$payload, 'token_profile' => 'read_only'])->assertUnprocessable();
    expect($user->tokens()->count())->toBe(1);
});

it('keeps enforcing marked tokens when issuance is subsequently disabled', function () {
    $token = $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload())->assertOk()->json('access_token');
    config(['api_tokens.finite_issuance_enabled' => false]);
    $this->travel(61)->minutes();
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertUnauthorized();
});

it('refuses malformed marked credentials without changing legacy token policy', function (array $patch) {
    $user = User::factory()->create();
    $token = $user->createToken('fixture-legacy');
    $token->accessToken->forceFill($patch)->save();
    Auth::forgetGuards();
    $this->withToken($token->plainTextToken)->getJson('/api/v2/auth/me')->assertUnauthorized();
})->with([
    [['api_policy_version' => 'unknown']],
    [['api_policy_version' => 'interactive-api-v1', 'api_token_profile' => 'interactive']],
    [['api_token_profile' => 'read_only']],
]);

it('refuses invalid finite lifetimes before creating a token', function ($minutes) {
    config(['api_tokens.ttl_minutes' => $minutes]);
    $user = User::factory()->create();
    expect(fn () => app(TokenAuthService::class)->issueAccessToken($user, 'fixture'))->toThrow(RuntimeException::class);
    expect($user->tokens()->count())->toBe(0);
})->with([0, 4, 1441, 'unbounded', null, true, 5.5]);

it('prunes one bounded expired epoch batch without deleting legacy or unexpired credentials', function () {
    // Integration fixtures may have committed earlier global queue entries.
    $cursor = (int) (DB::table('personal_access_tokens')->max('id') ?? 0);
    $user = User::factory()->create();
    $service = app(TokenAuthService::class);
    $first = $service->issueAccessToken($user, 'expired-one');
    $second = $service->issueAccessToken($user, 'expired-two');
    $this->travel(2)->hours();
    $service->issueAccessToken($user, 'current');
    $user->createToken('legacy-permanent');
    $this->artisan('auth:prune-policy-tokens', ['--hours' => '0', '--limit' => '1', '--after-id' => (string) $cursor])->assertExitCode(0);
    expect($user->tokens()->count())->toBe(4);
    $this->artisan('auth:prune-policy-tokens', ['--hours' => '0', '--limit' => '1', '--after-id' => (string) $cursor, '--apply' => true])->assertExitCode(0);
    expect($user->tokens()->count())->toBe(3)
        ->and(PersonalAccessToken::findToken($first))->toBeNull()
        ->and(PersonalAccessToken::findToken($second))->not->toBeNull();
});

it('preserves a token actually linked to a native session during epoch pruning', function () {
    $user = User::factory()->create();
    $token = app(TokenAuthService::class)->issueAccessToken($user, 'linked-fixture');
    $row = PersonalAccessToken::findToken($token);
    DeviceSession::factory()->create(['user_id' => $user->id, 'access_token_id' => $row->id]);
    $this->travel(2)->hours();
    $this->artisan('auth:prune-policy-tokens', ['--hours' => '0', '--apply' => true])->assertExitCode(0);
    expect(PersonalAccessToken::findToken($token))->not->toBeNull();
});

it('composes existing token admission and never restores a previously invalid token', function () {
    $token = new PersonalAccessToken;
    $deny = new ApiTokenPolicyAuthenticator(fn () => false);
    $allow = new ApiTokenPolicyAuthenticator(fn () => true);
    expect($deny($token, true))->toBeFalse()->and($allow($token, false))->toBeFalse()->and($allow($token, true))->toBeTrue();
});

it('reports remaining expiry rather than the original lifetime after response delay', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25T18:00:00Z'));
    $grant = app(TokenAuthService::class)->issueGrant(User::factory()->create(), 'fixture-delayed');
    $this->travel(30)->seconds();
    expect($grant->metadata()['expires_in'])->toBe(3570);
});

it('keeps actual token response keys within its closed OpenAPI contract', function (bool $enabled) {
    config(['api_tokens.finite_issuance_enabled' => $enabled]);
    $response = $this->postJson('/api/v2/auth/login', finitePolicyLoginPayload())->assertOk();
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);
    $schema = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas']['TokenAuthResponse'];
    expect(array_diff(array_keys($response->json()), array_keys($schema['properties'])))->toBe([])
        ->and(array_diff($schema['required'], array_keys($response->json())))->toBe([]);
})->with([true, false]);

it('gates bounded epoch cleanup independently from authentication enforcement', function () {
    $event = collect(app(Schedule::class)->events())->firstWhere('description', 'Auth: Prune Finite API Credentials');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *')
        ->and($event->onOneServer)->toBeTrue()->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->command)->toContain('--limit=1000', '--hours=24', '--apply');
    config(['api_tokens.finite_issuance_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
    config(['api_tokens.finite_issuance_enabled' => true]);
    expect($event->filtersPass(app()))->toBeTrue();
});

it('refuses marker rollback rather than resurrecting an expired token', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25T18:00:00Z'));
    $token = app(TokenAuthService::class)->issueAccessToken(User::factory()->create(), 'rollback-fixture');
    $this->travel(61)->minutes();
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertUnauthorized();
    $migration = require database_path('migrations/2026_11_26_080000_add_api_token_policy_epochs.php');
    $refused = false;
    try {
        $migration->down();
    } catch (RuntimeException) {
        $refused = true;
    }
    Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/v2/auth/me')->assertUnauthorized();
    expect($refused)->toBeTrue()->and(Schema::hasColumn('personal_access_tokens', 'api_policy_version'))->toBeTrue();
});

class ForeignPolicyFixtureToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $connection = 'finite_policy_foreign';
}

it('refuses split token persistence before an unmarked token can escape rollback', function () {
    $defaultCountBefore = DB::table('personal_access_tokens')->count();
    $model = Sanctum::$personalAccessTokenModel;
    config(['database.connections.finite_policy_foreign' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    Schema::connection('finite_policy_foreign')->create('personal_access_tokens', function (Blueprint $table) {
        $table->id();
        $table->morphs('tokenable');
        $table->string('name');
        $table->string('token', 64)->unique();
        $table->text('abilities')->nullable();
        $table->timestamp('expires_at')->nullable();
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();
    });
    $user = User::factory()->create();
    try {
        Sanctum::usePersonalAccessTokenModel(ForeignPolicyFixtureToken::class);
        $failed = false;
        try {
            app(TokenAuthService::class)->issueAccessToken($user, 'split-fixture');
        } catch (Throwable $exception) {
            $failed = $exception instanceof RuntimeException
                && $exception->getMessage() === 'Finite API tokens require one resolved database connection.';
        }
        expect($failed)->toBeTrue()
            ->and(DB::connection('finite_policy_foreign')->table('personal_access_tokens')->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->count())->toBe($defaultCountBefore);
    } finally {
        Sanctum::usePersonalAccessTokenModel($model);
        DB::purge('finite_policy_foreign');
        config(['database.connections.finite_policy_foreign' => null]);
    }
});
