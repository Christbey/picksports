<?php

use App\Models\OAuthUser;
use App\Models\User;
use App\Providers\NativeOAuthServiceProvider;
use App\Services\Auth\NativeAuthCodeGrant;
use App\Services\Auth\NativeOAuthPkcePolicy;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\PassportServiceProvider;
use Laravel\Passport\PersonalAccessTokenFactory;
use Laravel\Passport\Token;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\AbstractResponseType;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->previousPkceResponse = Passport::$authorizationServerResponseType;
    $this->previousPkceEncryption = Passport::$tokenEncryptionKeyCallback;
    Passport::useAuthorizationServerResponseType(new NativePkceTestResponse);
    Passport::encryptTokensUsing(fn () => str_repeat('synthetic-pkce-', 3));
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('p', 32)));
    $this->app->forgetInstance('encrypter');
    if (class_exists(NativeOAuthServiceProvider::class)) {
        $binding = $this->app->getBindings()[AuthorizationServer::class]['concrete'];
        expect((new ReflectionFunction($binding))->getClosureThis())->toBeInstanceOf(NativeOAuthServiceProvider::class);
    }
    $this->pkceFactory = new NativePkceKeylessProvider($this->app);
    $this->pkceFactory->registerKeylessServer();
    $this->user = User::factory()->create();
    $this->actingAs($this->user, 'web');
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'Native PKCE fixture', '--redirect-uri' => ['picksports://oauth/callback']])->assertSuccessful();
    $this->nativeClient = Client::query()->where('name', 'Native PKCE fixture')->sole();
});

afterEach(function () {
    Passport::useAuthorizationServerResponseType($this->previousPkceResponse);
    Passport::encryptTokensUsing($this->previousPkceEncryption);
});

it('refuses a native plain or omitted PKCE method at the actual authorization router', function (bool $omitted) {
    $query = nativePkceQuery($this->nativeClient, 'plain');
    if ($omitted) {
        unset($query['code_challenge_method']);
    }
    $this->get('/oauth/authorize?'.http_build_query($query), ['Accept' => 'application/json'])->assertStatus(400);
    expect(AuthCode::query()->count())->toBe(0)->and(Token::query()->count())->toBe(0);
})->with([false, true]);

it('preserves native S256 scopes state and the real authorization token exchange without RSA keys', function () {
    $this->get('/oauth/authorize?'.http_build_query(nativePkceQuery($this->nativeClient, 'S256')))->assertOk();
    $serialized = $this->app['session.store']->get('authRequest');
    $request = unserialize($serialized, ['allowed_classes' => true]);
    expect($request->getCodeChallengeMethod())->toBe('S256')->and($request->getState())->toBe('synthetic-state')
        ->and(array_map(fn ($scope) => $scope->getIdentifier(), $request->getScopes()))->toBe(['mobile:read', 'mobile:write']);
    $approved = $this->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
    $code = nativePkceCode($approved->headers->get('Location'));
    $this->post('/oauth/token', nativePkceExchange($this->nativeClient, $code), ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('scopes', ['mobile:read', 'mobile:write']);
    expect(AuthCode::query()->sole()->revoked)->toBeTrue()->and(Token::query()->count())->toBe(1)
        ->and(Passport::refreshToken()->newQuery()->count())->toBe(1);
});

it('refuses a cached native plain authorization request before code persistence', function () {
    $old = $this->pkceFactory->legacyServer()->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'plain')));
    $old->setUser(new BridgeUser($this->user->getKey()));
    $this->withSession(['authToken' => 'synthetic-consent', 'authRequest' => serialize($old)])
        ->post('/oauth/authorize', ['auth_token' => 'synthetic-consent'], ['Accept' => 'application/json'])->assertStatus(400);
    expect(AuthCode::query()->count())->toBe(0)->and(Token::query()->count())->toBe(0);
});

it('refuses a previously issued native plain code despite a request-side S256 claim', function () {
    $old = $this->pkceFactory->legacyServer();
    $request = $old->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'plain')));
    $request->setUser(new BridgeUser($this->user->getKey()));
    $request->setAuthorizationApproved(true);
    $response = $old->completeAuthorizationRequest($request, new Response);
    $code = nativePkceCode($response->getHeaderLine('Location'));
    $this->post('/oauth/token', [...nativePkceExchange($this->nativeClient, $code), 'code_challenge_method' => 'S256'], ['Accept' => 'application/json'])->assertStatus(400);
    expect(Token::query()->count())->toBe(0)->and(Passport::refreshToken()->newQuery()->count())->toBe(0)
        ->and(AuthCode::query()->sole()->revoked)->toBeFalse();
});

it('preserves owned public plain authorization compatibility', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Owned public fixture', ['picksports://oauth/callback'], confidential: false, user: OAuthUser::query()->findOrFail($this->user->getKey()));
    $this->get('/oauth/authorize?'.http_build_query(nativePkceQuery($client, 'plain')))->assertOk();
    $approved = $this->post('/oauth/authorize', ['auth_token' => $this->app['session.store']->get('authToken')])->assertRedirect();
    $code = nativePkceCode($approved->headers->get('Location'));
    $this->post('/oauth/token', nativePkceExchange($client, $code), ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('accepted', true);
});

it('preserves confidential authorization without PKCE and the client credentials grant', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Confidential fixture', ['https://client.example.test/callback'], confidential: true);
    $query = nativePkceQuery($client, 'S256');
    unset($query['code_challenge'], $query['code_challenge_method']);
    $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    $service = app(ClientRepository::class)->createClientCredentialsGrantClient('Service fixture');
    $this->post('/oauth/token', ['grant_type' => 'client_credentials', 'client_id' => $service->getKey(), 'client_secret' => $service->plainSecret, 'scope' => 'mobile:read'], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('accepted', true)->assertJsonPath('scopes', ['mobile:read']);
});

it('preserves S256 loopback IP ephemeral ports but refuses changed host path and localhost-name ports', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Loopback fixture', ['http://127.0.0.1:51004/callback'], confidential: false);
    $query = nativePkceQuery($client, 'S256');
    $query['redirect_uri'] = 'http://127.0.0.1:51005/callback';
    $this->get('/oauth/authorize?'.http_build_query($query))->assertOk();
    foreach (['http://127.0.0.2:51005/callback', 'http://127.0.0.1:51005/other'] as $redirect) {
        $query['redirect_uri'] = $redirect;
        $this->get('/oauth/authorize?'.http_build_query($query), ['Accept' => 'application/json'])->assertStatus(401);
    }
    $localhost = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Localhost fixture', ['http://localhost:51004/callback'], confidential: false);
    $query = nativePkceQuery($localhost, 'S256');
    $query['redirect_uri'] = 'http://localhost:51005/callback';
    $this->get('/oauth/authorize?'.http_build_query($query), ['Accept' => 'application/json'])->assertStatus(401);
});

it('accepts an exact IPv6 loopback redirect at the actual native setup command', function () {
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'IPv6 fixture', '--redirect-uri' => ['http://[::1]:51004/callback']])->assertSuccessful();
    expect(Client::query()->where('name', 'IPv6 fixture')->sole()->redirect_uris)->toBe(['http://[::1]:51004/callback']);
});

it('refuses zero-valued ownership rather than treating it as an unowned native client', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Zero owner fixture', ['picksports://original'], confidential: false);
    $client->forceFill(['owner_id' => 0])->save();
    $before = $client->fresh()->getAttributes();
    $this->artisan('auth:configure-native-oauth-client', ['--name' => 'Zero owner fixture', '--redirect-uri' => ['picksports://changed']])->assertFailed();
    expect($client->fresh()->getAttributes())->toBe($before);
});

function nativePkceQuery(Client $client, string $method): array
{
    $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    return ['response_type' => 'code', 'client_id' => $client->getKey(), 'redirect_uri' => $client->redirect_uris[0], 'scope' => 'mobile:read mobile:write', 'state' => 'synthetic-state', 'code_challenge' => $method === 'plain' ? $verifier : rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => $method];
}
function nativePkceExchange(Client $client, string $code): array
{
    return ['grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'redirect_uri' => $client->redirect_uris[0], 'code' => $code, 'code_verifier' => 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'];
}
function nativePkceCode(string $location): string
{
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query['code'];
}
class NativePkceKeylessProvider extends PassportServiceProvider
{
    public function registerKeylessServer(): void
    {
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->registerAuthorizationServer();
    }

    protected function makeCryptKey(string $type): CryptKey
    {
        return Mockery::mock(CryptKey::class);
    }

    protected function buildAuthCodeGrant(): AuthCodeGrant
    {
        $type = class_exists(NativeOAuthServiceProvider::class) ? NativeOAuthServiceProvider::class : PassportServiceProvider::class;

        return (new ReflectionMethod($type, 'buildAuthCodeGrant'))->invoke(new $type($this->app));
    }

    public function legacyServer(): AuthorizationServer
    {
        $server = $this->makeAuthorizationServer(new NativePkceTestResponse);
        $grant = new AuthCodeGrant($this->app->make(AuthCodeRepository::class), $this->app->make(RefreshTokenRepository::class), new DateInterval('PT10M'));
        $grant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $server->enableGrantType($grant, Passport::tokensExpireIn());

        return $server;
    }
}
class NativePkceTestResponse extends AbstractResponseType
{
    public function generateHttpResponse(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['accepted' => true, 'scopes' => array_map(fn ($scope) => $scope->getIdentifier(), $this->accessToken->getScopes())], JSON_THROW_ON_ERROR));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}

it('boots exactly one set of Passport routes and the native server factory in a clean synthetic child', function () {
    $root = storage_path('framework/testing/pkce-route-probe-'.Str::uuid());
    File::ensureDirectoryExists($root);
    File::put($root.'/.env.testing', "APP_ENV=testing\n");
    $environment = [];
    foreach (array_keys(array_merge(getenv(), $_ENV, $_SERVER)) as $name) {
        if (is_string($name)) {
            $environment[$name] = false;
        }
    }
    $environment = [...$environment, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'TMPDIR' => sys_get_temp_dir(), 'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'APP_KEY' => 'base64:'.base64_encode(str_repeat('r', 32)), 'APP_CONFIG_CACHE' => $root.'/config.php', 'APP_ROUTES_CACHE' => $root.'/routes.php', 'APP_SERVICES_CACHE' => $root.'/services.php', 'APP_PACKAGES_CACHE' => $root.'/packages.php', 'LARAVEL_STORAGE_PATH' => $root.'/storage', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'DATABASE_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'];
    try {
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/NativePkceRouteProbe.php'), $root], base_path(), $environment);
        $process->setTimeout(20);
        $process->mustRun();
        $proof = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($proof['route_add_counts'])->toBe(['GET oauth/authorize' => 1, 'POST oauth/authorize' => 1, 'DELETE oauth/authorize' => 1, 'POST oauth/token' => 1])
            ->and($proof['vendor_provider_loaded'])->toBeTrue()->and($proof['native_provider_loaded'])->toBeTrue()
            ->and($proof['server_factory_owner'])->toBe(NativeOAuthServiceProvider::class)->and($proof['synthetic_environment_path'])->toBe($root);
    } finally {
        File::deleteDirectory($root);
    }
});

it('retains the parent grant factories and configured lifetimes without adding auth code to the personal token context', function () {
    $oldAccess = (new ReflectionProperty(Passport::class, 'tokensExpireIn'))->getValue();
    $oldRefresh = (new ReflectionProperty(Passport::class, 'refreshTokensExpireIn'))->getValue();
    try {
        Passport::tokensExpireIn(new DateInterval('PT17M'));
        Passport::refreshTokensExpireIn(new DateInterval('P23D'));
        $server = app(AuthorizationServer::class);
        $grants = (new ReflectionProperty(AuthorizationServer::class, 'enabledGrantTypes'))->getValue($server);
        $ttls = (new ReflectionProperty(AuthorizationServer::class, 'grantTypeAccessTokenTTL'))->getValue($server);
        expect($grants['authorization_code'])->toBeInstanceOf(NativeAuthCodeGrant::class)
            ->and($grants['refresh_token'])->toBeInstanceOf(RefreshTokenGrant::class)
            ->and($grants['client_credentials'])->toBeInstanceOf(ClientCredentialsGrant::class)
            ->and($ttls['authorization_code']->format('%i'))->toBe('17')
            ->and((new ReflectionProperty(AuthCodeGrant::class, 'authCodeTTL'))->getValue($grants['authorization_code'])->format('%i'))->toBe('10')
            ->and((new ReflectionProperty(AbstractGrant::class, 'refreshTokenTTL'))->getValue($grants['authorization_code'])->format('%d'))->toBe('23');
        $factory = app(PersonalAccessTokenFactory::class);
        $personal = (new ReflectionProperty(PersonalAccessTokenFactory::class, 'server'))->getValue($factory);
        expect(array_keys((new ReflectionProperty(AuthorizationServer::class, 'enabledGrantTypes'))->getValue($personal)))->toBe(['personal_access']);
    } finally {
        Passport::tokensExpireIn($oldAccess);
        Passport::refreshTokensExpireIn($oldRefresh);
    }
});

it('preserves denying a cached plain request without issuing a code', function () {
    $old = $this->pkceFactory->legacyServer()->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'plain')));
    $old->setUser(new BridgeUser($this->user->getKey()));
    $response = $this->withSession(['authToken' => 'synthetic-denial', 'authRequest' => serialize($old)])->delete('/oauth/authorize', ['auth_token' => 'synthetic-denial']);
    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('access_denied')
        ->and(AuthCode::query()->count())->toBe(0)->and(Token::query()->count())->toBe(0);
});

it('retains normal S256 verifier rejection before token writes', function () {
    $server = app(AuthorizationServer::class);
    $request = $server->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'S256')));
    $request->setUser(new BridgeUser($this->user->getKey()));
    $request->setAuthorizationApproved(true);
    $code = nativePkceCode($server->completeAuthorizationRequest($request, new Response)->getHeaderLine('Location'));
    $this->post('/oauth/token', [...nativePkceExchange($this->nativeClient, $code), 'code_verifier' => str_repeat('x', 43)], ['Accept' => 'application/json'])->assertStatus(400);
    expect(Token::query()->count())->toBe(0)->and(Passport::refreshToken()->newQuery()->count())->toBe(0)->and(AuthCode::query()->sole()->revoked)->toBeFalse();
});

it('classifies legacy and morph ownership strictly without empty-value coercion', function (array $ownership) {
    $client = new Client(['secret' => null, 'grant_types' => ['authorization_code', 'refresh_token'], ...$ownership]);
    expect(NativeOAuthPkcePolicy::isUnownedPublicAuthorizationClient($client))->toBeFalse();
})->with([
    [['user_id' => 0]], [['user_id' => '0']], [['user_id' => '']],
    [['owner_id' => 0]], [['owner_id' => '0']], [['owner_id' => false]],
    [['owner_type' => '']], [['owner_type' => OAuthUser::class]],
]);

it('refuses an old native code with no PKCE while retaining standard code encryption', function () {
    $old = $this->pkceFactory->legacyServer();
    $grants = (new ReflectionProperty(AuthorizationServer::class, 'enabledGrantTypes'))->getValue($old);
    $grants['authorization_code']->disableRequireCodeChallengeForPublicClients();
    $query = nativePkceQuery($this->nativeClient, 'S256');
    unset($query['code_challenge'], $query['code_challenge_method']);
    $request = $old->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams($query));
    $request->setUser(new BridgeUser($this->user->getKey()));
    $request->setAuthorizationApproved(true);
    $code = nativePkceCode($old->completeAuthorizationRequest($request, new Response)->getHeaderLine('Location'));
    $exchange = nativePkceExchange($this->nativeClient, $code);
    unset($exchange['code_verifier']);
    $this->post('/oauth/token', $exchange, ['Accept' => 'application/json'])->assertStatus(400);
    expect(Token::query()->count())->toBe(0)->and(Passport::refreshToken()->newQuery()->count())->toBe(0);
});

it('refuses inconsistent first-party public ownership at authorization entry', function (array $ownership, string $method) {
    $this->nativeClient->forceFill($ownership)->save();
    expect($this->nativeClient->fresh()->firstParty())->toBeTrue();
    $this->get('/oauth/authorize?'.http_build_query(nativePkceQuery($this->nativeClient, $method)), ['Accept' => 'application/json'])->assertStatus(400);
    expect(AuthCode::query()->count())->toBe(0)->and(Token::query()->count())->toBe(0);
})->with([
    [['owner_id' => 0], 'plain'],
    [['owner_type' => 'untrusted-owner-type', 'owner_id' => null], 'plain'],
    [['owner_id' => 0], 'S256'],
    [['owner_type' => 'untrusted-owner-type', 'owner_id' => null], 'S256'],
]);

it('refuses cached plain consent after first-party ownership becomes inconsistent', function (array $ownership) {
    $old = $this->pkceFactory->legacyServer()->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'plain')));
    $old->setUser(new BridgeUser($this->user->getKey()));
    $this->nativeClient->forceFill($ownership)->save();
    $this->withSession(['authToken' => 'synthetic-consent', 'authRequest' => serialize($old)])
        ->post('/oauth/authorize', ['auth_token' => 'synthetic-consent'], ['Accept' => 'application/json'])->assertStatus(400);
    expect(AuthCode::query()->count())->toBe(0)->and(Token::query()->count())->toBe(0);
})->with([
    [['owner_id' => 0]],
    [['owner_type' => 'untrusted-owner-type', 'owner_id' => null]],
]);

it('refuses old plain code exchange after first-party ownership becomes inconsistent', function (array $ownership) {
    $old = $this->pkceFactory->legacyServer();
    $request = $old->validateAuthorizationRequest((new ServerRequest('GET', '/oauth/authorize'))->withQueryParams(nativePkceQuery($this->nativeClient, 'plain')));
    $request->setUser(new BridgeUser($this->user->getKey()));
    $request->setAuthorizationApproved(true);
    $response = $old->completeAuthorizationRequest($request, new Response);
    $code = nativePkceCode($response->getHeaderLine('Location'));
    $this->nativeClient->forceFill($ownership)->save();
    $this->post('/oauth/token', nativePkceExchange($this->nativeClient, $code), ['Accept' => 'application/json'])->assertStatus(400);
    expect(Token::query()->count())->toBe(0)->and(Passport::refreshToken()->newQuery()->count())->toBe(0)
        ->and(AuthCode::query()->sole()->revoked)->toBeFalse();
})->with([
    [['owner_id' => 0]],
    [['owner_type' => 'untrusted-owner-type', 'owner_id' => null]],
]);
