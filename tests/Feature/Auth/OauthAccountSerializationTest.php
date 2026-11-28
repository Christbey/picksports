<?php

use App\Models\OauthAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

it('keeps provider credentials and raw claims out of direct and nested account serialization', function () {
    $account = new OauthAccount([
        'provider' => 'google', 'provider_user_id' => 'synthetic-provider-id',
        'email' => 'synthetic@example.test', 'access_token' => 'synthetic-access-secret',
        'refresh_token' => 'synthetic-refresh-secret',
        'provider_claims' => ['unreviewed_claim' => 'synthetic-claim-secret'],
    ]);
    $user = (new User)->setRelation('oauthAccounts', new Collection([$account]));
    foreach ([$account->toArray(), $user->toArray()['oauth_accounts'][0]] as $serialized) {
        expect($serialized)->toMatchArray(['provider' => 'google', 'provider_user_id' => 'synthetic-provider-id'])
            ->and($serialized)->not->toHaveKeys(['access_token', 'refresh_token', 'provider_claims'])
            ->and(json_encode($serialized))->not->toContain('synthetic-access-secret', 'synthetic-refresh-secret', 'synthetic-claim-secret');
    }
    expect($account->access_token)->toBe('synthetic-access-secret')
        ->and($account->refresh_token)->toBe('synthetic-refresh-secret')
        ->and($account->provider_claims)->toBe(['unreviewed_claim' => 'synthetic-claim-secret']);
});
