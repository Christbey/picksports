<?php

use Illuminate\Support\Facades\Artisan;

it('documents nullable historical daily-pick safety and observed candidate kickoff contracts', function () {
    expect(Artisan::call('api:v2-openapi-generate', ['--stdout' => true]))->toBe(0);
    $spec = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $schemas = $spec['components']['schemas'];
    expect(data_get($schemas, 'MlbDailyPicksData.properties.summary.$ref'))->toBe('#/components/schemas/MlbDailyStoredAssessment');
    expect(data_get($schemas, 'MlbDailyPicksData.properties.board_health.$ref'))->toBe('#/components/schemas/MlbDailyStoredAssessment');
    expect(data_get($schemas, 'MlbDailyPicksData.properties.candidates.items.$ref'))->toBe('#/components/schemas/MlbDailyPickCandidate');
    expect(data_get($schemas, 'MlbDailyStoredAssessment.properties.pregame_safe_rate.type'))->toBe(['number', 'null']);
    expect(data_get($schemas, 'MlbDailyStoredAssessment.properties.pregame_safe_rate.description'))->toContain('stored')->toContain('not current');
    expect(data_get($schemas, 'MlbDailyPickGame.required'))->toContain('starts_at');
    expect(data_get($schemas, 'MlbDailyPickGame.properties.starts_at.type'))->toBe(['string', 'null']);
    expect(data_get($schemas, 'MlbDailyPickGame.properties.starts_at.format'))->toBe('date-time');
});
