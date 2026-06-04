<?php

it('lists v2 sport metadata with stable contract fields', function () {
    $response = $this->getJson('/api/v2/sports')
        ->assertOk()
        ->assertJsonPath('meta.version', 'v2')
        ->assertJsonPath('meta.authenticated_data_access', true)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'slug',
                    'label',
                    'namespace',
                    'capabilities',
                    'web' => [
                        'pages',
                        'details',
                        'player_props',
                        'requires_prediction_permission',
                    ],
                    'access' => [
                        'authenticated_data_access',
                        'free_access_is_policy_driven',
                    ],
                ],
            ],
            'meta' => [
                'version',
                'authenticated_data_access',
            ],
        ]);

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toEqual(array_keys((array) config('sports.domains', [])));
});

it('shows one v2 sport metadata payload', function () {
    $this->getJson('/api/v2/sports/mlb')
        ->assertOk()
        ->assertJsonPath('data.slug', 'mlb')
        ->assertJsonPath('data.namespace', 'MLB')
        ->assertJsonPath('data.access.authenticated_data_access', true)
        ->assertJsonPath('data.access.free_access_is_policy_driven', true);
});

it('returns a clean json 404 for unsupported v2 sports', function () {
    $this->getJson('/api/v2/sports/nhl')
        ->assertNotFound()
        ->assertJsonPath('message', 'Unsupported sport: nhl');
});
