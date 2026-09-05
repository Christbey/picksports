<?php

use Illuminate\Support\Facades\Artisan;

it('fails readiness when a required provider review status is unconfirmed', function () {
    config()->set('provider-redistribution.providers', [
        'required-feed' => [
            'label' => 'Required feed',
            'required_for_public_api' => true,
            'status' => 'unconfirmed',
        ],
        'optional-feed' => [
            'label' => 'Optional feed',
            'required_for_public_api' => false,
            'status' => 'unconfirmed',
        ],
    ]);

    $exitCode = Artisan::call('developer-platform:check-redistribution-licenses', ['--json' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain(
            '"ready": false',
            'required_status_not_confirmed',
            'does not make a legal determination',
        );
});

it('passes when every required provider review status is confirmed', function () {
    config()->set('provider-redistribution.providers', [
        'reviewed-feed' => [
            'label' => 'Reviewed feed',
            'required_for_public_api' => true,
            'status' => 'confirmed',
            'evidence_reference' => 'internal-review-123',
            'reviewed_at' => '2026-08-12',
            'owner' => 'data-operations',
        ],
        'optional-feed' => [
            'label' => 'Optional feed',
            'required_for_public_api' => false,
            'status' => 'unconfirmed',
        ],
    ]);

    $this->artisan('developer-platform:check-redistribution-licenses')
        ->assertSuccessful()
        ->expectsOutputToContain('All required provider review statuses are configured as confirmed.');
});

it('fails readiness for an invalid configured status', function () {
    config()->set('provider-redistribution.providers', [
        'bad-feed' => [
            'required_for_public_api' => false,
            'status' => 'probably',
        ],
    ]);

    $this->artisan('developer-platform:check-redistribution-licenses', ['--json' => true])
        ->assertFailed()
        ->expectsOutputToContain('invalid_status');
});
