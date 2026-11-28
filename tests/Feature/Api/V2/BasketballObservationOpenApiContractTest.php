<?php

use Illuminate\Support\Facades\Artisan;

it('documents nullable basketball leaderboard observations without closing other sport row fields', function () {
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);
    $schema = data_get(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR), 'components.schemas.SportPlayerLeaderboardRows.items');
    expect($schema['additionalProperties'])->toBeTrue()
        ->and($schema['properties']['points_per_game']['type'])->toBe(['number', 'null'])
        ->and($schema['properties']['estimated_epa_per_game']['type'])->toBe(['number', 'null'])
        ->and($schema['properties']['field_samples']['additionalProperties']['type'])->toBe('integer')
        ->and($schema['properties']['complete_box_score_games']['description'])->toContain('every included row')
        ->and($schema['properties']['minutes_samples']['description'])->toContain('positive minutes')
        ->and($schema['properties']['shooting_samples']['properties'])->toHaveKeys(['field_goals', 'three_point', 'free_throws']);
});
