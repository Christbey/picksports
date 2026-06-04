<?php

use App\Services\Api\V2\SportContextResolver;

it('resolves every configured sport into a v2 sport context', function () {
    $resolver = app(SportContextResolver::class);
    $configuredSports = array_keys((array) config('sports.domains', []));

    expect($configuredSports)->not->toBeEmpty();

    foreach ($configuredSports as $sport) {
        $context = $resolver->resolve($sport);

        expect($context->slug)->toBe($sport)
            ->and($context->namespace)->not->toBeEmpty()
            ->and($context->models)->toHaveKeys(['team', 'game'])
            ->and($context->resources)->toHaveKeys(['team', 'game'])
            ->and($context->requiresAuthenticatedDataAccess())->toBeTrue();
    }
});

it('returns null for unsupported sports without falling back to another context', function () {
    expect(app(SportContextResolver::class)->find('nhl'))->toBeNull();
});
