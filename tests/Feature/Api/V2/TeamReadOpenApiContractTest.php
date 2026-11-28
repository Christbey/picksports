<?php

use Illuminate\Support\Facades\Artisan;

test('team average and injury OpenAPI contracts match their real query and nullable evidence boundaries', function () {
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);
    $spec = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    foreach (['/api/v2/sports/{sport}/stats/team/season-averages', '/api/v2/sports/{sport}/teams/{team}/stats/season-averages'] as $path) {
        $parameters = collect($spec['paths'][$path]['get']['parameters'])->where('in', 'query')->keyBy('name');
        expect($parameters->keys()->all())->toBe(['season', 'season_type'])
            ->and($parameters['season']['schema']['minimum'])->toBe(1)
            ->and($parameters['season']['schema']['maximum'])->toBe(9999);
    }
    $injury = collect($spec['paths']['/api/v2/sports/{sport}/injuries']['get']['parameters'])->where('in', 'query')->keyBy('name');
    expect($injury->keys()->all())->toBe(['active', 'actionable', 'team_id', 'status', 'limit'])
        ->and($injury['limit']['schema']['maximum'])->toBe(500)
        ->and($spec['components']['schemas']['SportInjury']['properties']['source_clock_status']['enum'])->toBe(['unverified_storage_clock'])
        ->and($spec['components']['schemas']['SportInjury']['properties']['is_stale']['type'])->toBe(['boolean', 'null'])
        ->and($spec['components']['schemas']['InjuryObservationFreshness']['properties']['coverage_status']['enum'])->toBe(['unverified'])
        ->and($spec['components']['schemas']['SportTeamStatAverage']['required'])->toContain('season', 'season_type', 'sample_sizes')
        ->and($spec['components']['schemas']['TeamStatAverageScope']['properties']['selection']['enum'])->toBe(['explicit', 'latest_stored_completed']);
});

test('depth chart OpenAPI advertises only supported team scope and no ignored game filters', function () {
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);
    $spec = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $team = collect($spec['paths']['/api/v2/sports/{sport}/teams/{team}/depth-charts']['get']['parameters'])->where('in', 'query')->keyBy('name');
    $game = collect($spec['paths']['/api/v2/sports/{sport}/games/{game}/depth-charts']['get']['parameters'])->where('in', 'query');
    expect($team->keys()->all())->toBe(['season', 'season_type', 'before_date'])
        ->and($team['season']['schema']['minimum'])->toBe(1)
        ->and($team['season']['schema']['maximum'])->toBe(9999)
        ->and($team['season_type']['schema']['type'])->toBe('string')
        ->and($game)->toHaveCount(0);
});
