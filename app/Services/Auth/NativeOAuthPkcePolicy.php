<?php

namespace App\Services\Auth;

use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;

/** Current first-party public auth-code clients are the supported native-client class. */
final class NativeOAuthPkcePolicy
{
    public static function isUnownedPublicAuthorizationClient(Client $client): bool
    {
        $attributes = $client->getAttributes();
        foreach (['user_id', 'owner_id', 'owner_type'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                return false;
            }
        }

        return $client->firstParty() && ! $client->confidential() && $client->hasGrantType('authorization_code');
    }

    public function requiresS256(string $clientIdentifier): bool
    {
        // Match Passport's configured model/connection, without its request-local once() cache or a replica.
        $client = Passport::client()->newQuery()->useWritePdo()->whereKey($clientIdentifier)->first();
        if (! $client || $client->revoked || ! $client->hasGrantType('authorization_code')) {
            throw OAuthServerException::invalidRequest('client_id', 'The authorization client is unavailable.');
        }

        $native = self::isUnownedPublicAuthorizationClient($client);
        if (! $client->confidential() && $client->firstParty() && ! $native) {
            // Passport's empty()-based first-party check also accepts zero IDs or orphaned morph types.
            // Such metadata is neither a reviewed native client nor an owned-client exemption.
            throw OAuthServerException::invalidRequest('client_id', 'The authorization client ownership is inconsistent.');
        }

        return $native;
    }
}
