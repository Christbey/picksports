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

    expect($exitCode)->toBe(0)
        ->and($predictionOperation['security'])->toBe([['sanctumBearer' => []]])
        ->and($sportParameter['schema']['enum'])->toBe(['nba', 'wnba', 'mlb', 'nfl', 'cbb', 'wcbb', 'cfb'])
        ->and($queryParameters)->toContain('season', 'season_type', 'from_date', 'to_date', 'market', 'per_page')
        ->and($spec['components']['securitySchemes']['sanctumBearer']['scheme'])->toBe('bearer')
        ->and($spec['components']['schemas']['SportMeta']['properties'])->toHaveKeys(['version', 'sport', 'contract', 'filters', 'warnings']);
});
