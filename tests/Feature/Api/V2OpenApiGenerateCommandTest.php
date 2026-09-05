<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;

test('api v2 openapi generator writes every registered v2 route', function () {
    $path = base_path('tmp/openapi-v2-test.json');
    File::delete($path);

    $exitCode = Artisan::call('api:v2-openapi-generate', [
        '--output' => 'tmp/openapi-v2-test.json',
    ]);

    expect($exitCode)->toBe(0)
        ->and(File::exists($path))->toBeTrue();

    $spec = json_decode(File::get($path), true);

    expect($spec)
        ->toBeArray()
        ->and($spec['openapi'])->toBe('3.1.0')
        ->and($spec['servers'][0]['url'])->toBe('https://picksports.app')
        ->and($spec['paths'])->toBeArray();

    collect(RouteFacade::getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v2'))
        ->each(function (Route $route) use ($spec): void {
            $openApiPath = '/'.$route->uri();

            expect($spec['paths'])->toHaveKey($openApiPath);

            collect($route->methods())
                ->reject(fn (string $method): bool => $method === 'HEAD')
                ->each(fn (string $method) => expect($spec['paths'][$openApiPath])->toHaveKey(strtolower($method)));
        });

    File::delete($path);
});

test('api v2 openapi generator includes security and sport filter metadata', function () {
    $exitCode = Artisan::call('api:v2-openapi-generate', [
        '--stdout' => true,
    ]);

    $spec = json_decode(Artisan::output(), true);
    $predictionOperation = $spec['paths']['/api/v2/sports/{sport}/predictions']['get'];
    $sportParameter = collect($predictionOperation['parameters'])->firstWhere('name', 'sport');
    $queryParameters = collect($predictionOperation['parameters'])
        ->where('in', 'query')
        ->pluck('name')
        ->all();
    $userBetStoreOperation = $spec['paths']['/api/v2/user-bets']['post'];
    $idempotencyParameter = collect($userBetStoreOperation['parameters'])
        ->firstWhere('name', 'Idempotency-Key');
    $userBetStoreSchema = $spec['components']['schemas']['UserBetStoreRequest'];
    $developerSandboxOperation = $spec['paths']['/api/v2/developer/sandbox']['get'];
    $gameOperation = $spec['paths']['/api/v2/sports/{sport}/games/{game}']['get'];
    $gameParameter = collect($gameOperation['parameters'])->firstWhere('name', 'game');

    expect($exitCode)->toBe(0)
        ->and($predictionOperation['security'])->toBe([
            ['sanctumBearer' => []],
            ['nativeOAuth' => ['mobile:read']],
        ])
        ->and($sportParameter['schema']['enum'])->toBe(['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'])
        ->and($queryParameters)->toContain('season', 'season_type', 'from_date', 'to_date', 'market', 'per_page')
        ->and($spec['components']['securitySchemes']['sanctumBearer']['scheme'])->toBe('bearer')
        ->and($spec['components']['securitySchemes']['nativeOAuth']['flows']['authorizationCode']['authorizationUrl'])->toBe('/oauth/authorize')
        ->and($spec['components']['schemas']['SportMeta']['properties'])->toHaveKeys(['version', 'sport', 'contract', 'filters', 'warnings'])
        ->and($spec['components']['responses']['ValidationError']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/ApiErrorResponse')
        ->and($spec['components']['responses']['ValidationError']['headers'])->toHaveKey('X-Request-ID')
        ->and($spec['components']['schemas']['ApiErrorResponse']['required'])->toBe(['error', 'request_id', 'message'])
        ->and($spec['components']['schemas']['ApiErrorResponse']['properties']['error']['required'])->toBe(['code', 'message', 'request_id'])
        ->and($predictionOperation['responses']['200']['headers'])->toHaveKey('X-Request-ID')
        ->and($idempotencyParameter['in'])->toBe('header')
        ->and($idempotencyParameter['required'])->toBeFalse()
        ->and($userBetStoreOperation['responses'])->toHaveKeys(['409', '429'])
        ->and($userBetStoreOperation['responses']['201']['headers'])->toHaveKeys([
            'Idempotency-Replayed',
            'Idempotency-Key-Expires-At',
        ])
        ->and($userBetStoreOperation['requestBody']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/UserBetStoreRequest')
        ->and($userBetStoreOperation['responses']['201']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/UserBetResponse')
        ->and($userBetStoreSchema['properties']['prediction_sport']['enum'])->toBe(['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb', null])
        ->and($userBetStoreSchema['properties']['prediction_type'])->toBeFalse()
        ->and($spec['components']['schemas']['UserBet']['properties'])->not->toHaveKey('prediction_type');

    expect($gameOperation['responses']['200']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/SportGameResponse')
        ->and($spec['components']['schemas']['SportGame']['properties'])->toHaveKeys(['id', 'sport_event_id', 'sport'])
        ->and($spec['components']['schemas']['SportGame']['properties']['id']['type'])->toBe('integer')
        ->and($spec['components']['schemas']['SportGame']['properties']['sport_event_id']['pattern'])->toBe('^[0-9A-HJKMNP-TV-Z]{26}$')
        ->and($gameParameter['schema']['oneOf'][0]['type'])->toBe('integer')
        ->and($gameParameter['schema']['oneOf'][1]['pattern'])->toBe('^[0-9A-HJKMNP-TV-Z]{26}$');

    expect($developerSandboxOperation['security'])->toBe([['developerApiCredential' => []]])
        ->and($developerSandboxOperation['responses'])->toHaveKeys(['200', '401', '403', '429'])
        ->and($developerSandboxOperation['responses']['200']['headers'])->toHaveKeys([
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
            'RateLimit-Policy',
        ]);
});

test('api v2 openapi matches write scopes statuses and request body requirements', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);

    $output = Artisan::output();
    $spec = json_decode($output, true);
    $userBetStore = $spec['paths']['/api/v2/user-bets']['post'];
    $userBetDestroy = $spec['paths']['/api/v2/user-bets/{bet}']['delete'];
    $logout = $spec['paths']['/api/v2/auth/logout']['post'];
    $groupStore = $spec['paths']['/api/v2/groups']['post'];
    $bracketStore = $spec['paths']['/api/v2/cbb-brackets']['post'];
    $passkeyOptions = $spec['paths']['/api/v2/auth/passkeys/options']['post'];
    $deviceStore = $spec['paths']['/api/v2/auth/device-sessions']['post'];
    $deviceRefresh = $spec['paths']['/api/v2/auth/device-sessions/refresh']['post'];

    expect($userBetStore['security'])->toContain(['nativeOAuth' => ['mobile:write']])
        ->and($userBetStore['responses'])->toHaveKey('201')
        ->and($userBetDestroy['security'])->toContain(['nativeOAuth' => ['mobile:write']])
        ->and($userBetDestroy['responses'])->toHaveKey('204')
        ->and($userBetDestroy['responses']['204'])->not->toHaveKey('content')
        ->and(collect($userBetDestroy['parameters'])->firstWhere('name', 'Idempotency-Key'))->not->toBeNull()
        ->and($logout)->not->toHaveKey('requestBody')
        ->and($logout['responses'])->toHaveKey('204')
        ->and($logout['responses']['204'])->not->toHaveKey('content')
        ->and($groupStore['responses'])->toHaveKey('201')
        ->and($bracketStore['responses'])->toHaveKey('201')
        ->and($passkeyOptions['requestBody']['required'])->toBeFalse()
        ->and($deviceStore['responses']['201']['content']['application/json']['schema']['$ref'])
        ->toBe('#/components/schemas/NativeDeviceTokenResponse')
        ->and($deviceRefresh['requestBody']['required'])->toBeTrue();

    $objectSpec = json_decode($output);
    expect($objectSpec->components->schemas->SportGame->properties->home_linescores->items)
        ->toBeInstanceOf(stdClass::class);
});

test('api v2 openapi maps every success response and request body to a named contract', function () {
    Artisan::call('api:v2-openapi-generate', ['--stdout' => true]);

    $spec = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $schemas = $spec['components']['schemas'];
    $successSchemas = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                if (! str_starts_with((string) $status, '2') || $status === 204) {
                    continue;
                }

                $content = $response['content'] ?? [];
                expect($content)->not->toBeEmpty("{$method} {$path} {$status} must declare its response media type");

                foreach ($content as $mediaType => $media) {
                    $reference = $media['schema']['$ref'] ?? null;
                    expect($reference)
                        ->not->toBeNull("{$method} {$path} {$status} {$mediaType} must use a named response schema")
                        ->not->toBe('#/components/schemas/GenericJsonResponse');

                    $schemaName = str($reference)->afterLast('/')->toString();
                    expect($schemas)->toHaveKey($schemaName);
                    $successSchemas[] = $schemaName;
                }
            }

            if (isset($operation['requestBody'])) {
                $reference = $operation['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
                expect($reference)
                    ->not->toBeNull("{$method} {$path} must use a named request schema")
                    ->not->toBe('#/components/schemas/GenericJsonResponse');
                expect($schemas)->toHaveKey(str($reference)->afterLast('/')->toString());
            }
        }
    }

    expect($schemas)->not->toHaveKey('GenericJsonResponse')
        ->and($successSchemas)->toContain(
            'SportPredictionCollectionResponse',
            'SportTeamMetricCollectionResponse',
            'CbbBracketResponse',
            'TokenAuthResponse',
            'UserBetCsvExport',
        )
        ->and($spec['paths']['/api/v2/user-bets/export']['get']['responses']['200']['content'])
        ->toHaveKey('text/csv');
});
