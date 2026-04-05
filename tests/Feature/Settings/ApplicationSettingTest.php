<?php

use App\Models\ApplicationSetting;

uses()->group('settings');

it('round trips typed scalar values through application settings', function () {
    ApplicationSetting::setValue('settings.bool_false', false);
    ApplicationSetting::setValue('settings.int', 42);
    ApplicationSetting::setValue('settings.float', 3.14);
    ApplicationSetting::setValue('settings.string', 'hello');
    ApplicationSetting::setValue('settings.null', null);

    expect(ApplicationSetting::getValue('settings.bool_false'))->toBeFalse()
        ->and(ApplicationSetting::getValue('settings.int'))->toBe(42)
        ->and(ApplicationSetting::getValue('settings.float'))->toBe(3.14)
        ->and(ApplicationSetting::getValue('settings.string'))->toBe('hello')
        ->and(ApplicationSetting::getValue('settings.null', 'fallback'))->toBeNull();
});

it('round trips structured values through application settings', function () {
    $payload = [
        'enabled' => false,
        'thresholds' => [
            'spread' => 2.5,
            'total' => 3.0,
        ],
    ];

    ApplicationSetting::setValue('settings.array', $payload);

    expect(ApplicationSetting::getValue('settings.array'))->toBe($payload);
});

it('preserves legacy raw string values when decoding application settings', function () {
    ApplicationSetting::query()->create([
        'key' => 'settings.legacy',
        'value' => 'legacy-value',
    ]);

    expect(ApplicationSetting::getValue('settings.legacy'))->toBe('legacy-value');
});

it('throws when attempting to persist a value that cannot be json encoded', function () {
    $resource = fopen('php://memory', 'rb');

    expect(fn () => ApplicationSetting::setValue('settings.invalid', ['resource' => $resource]))
        ->toThrow(JsonException::class);

    if (is_resource($resource)) {
        fclose($resource);
    }
});
