<?php

namespace App\Services\DeveloperPlatform;

use App\Models\DeveloperApiCredential;

class DeveloperApiCredentialAuthenticator
{
    public function authenticate(string $plainTextToken, ?string $requiredScope = null): ?DeveloperApiCredential
    {
        $parts = $this->tokenParts($plainTextToken);
        if ($parts === null) {
            return null;
        }

        [$prefix, $secret] = $parts;
        $credential = DeveloperApiCredential::query()
            ->with('organization')
            ->where('prefix', $prefix)
            ->first();

        if ($credential === null
            || ! hash_equals($credential->secret_hash, hash('sha256', $secret))
            || ! $credential->isUsable()
            || ($requiredScope !== null && ! $credential->hasScope($requiredScope))) {
            return null;
        }

        $credential->forceFill(['last_used_at' => now()])->saveQuietly();

        return $credential;
    }

    /**
     * @return array{string,string}|null
     */
    private function tokenParts(string $plainTextToken): ?array
    {
        if (! preg_match('/\Apsa_([a-z0-9]{12})\.([A-Za-z0-9]{48})\z/', $plainTextToken, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}
