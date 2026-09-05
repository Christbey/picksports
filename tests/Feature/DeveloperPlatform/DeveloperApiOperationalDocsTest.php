<?php

it('publishes the api v2 operational documentation set', function () {
    $directory = base_path('docs/api/v2');

    foreach (['README.md', 'CHANGELOG.md', 'deprecation-policy.md', 'sla.md', 'sandbox.md'] as $document) {
        expect($directory.'/'.$document)->toBeFile()
            ->and(filesize($directory.'/'.$document))->toBeGreaterThan(0);
    }

    expect(file_get_contents($directory.'/deprecation-policy.md'))
        ->toContain('/api/v2', '180 days', 'Sunset')
        ->and(file_get_contents($directory.'/sla.md'))
        ->toContain('99.9%', 'X-Request-ID')
        ->and(file_get_contents($directory.'/sandbox.md'))
        ->toContain('/api/v2/developer/sandbox', 'Idempotency-Key')
        ->and(file_get_contents($directory.'/CHANGELOG.md'))
        ->toContain('2026-08-12', 'provider-neutral billing meter batches');
});
