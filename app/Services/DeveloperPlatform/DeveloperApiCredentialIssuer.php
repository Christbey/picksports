<?php

namespace App\Services\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\IssuedDeveloperApiCredential;
use App\Models\DeveloperApiCredential;
use App\Models\DeveloperOrganization;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DeveloperApiCredentialIssuer
{
    /**
     * @param  list<string>  $scopes
     */
    public function issue(
        DeveloperOrganization $organization,
        string $name,
        array $scopes,
        ?User $creator = null,
        ?DateTimeInterface $expiresAt = null,
    ): IssuedDeveloperApiCredential {
        if (! $organization->isActive()) {
            throw new InvalidArgumentException('Credentials may only be issued to active developer organizations.');
        }

        $name = trim($name);
        $scopes = $this->normalizeScopes($scopes);

        if ($name === '' || $scopes === []) {
            throw new InvalidArgumentException('A credential name and at least one scope are required.');
        }

        do {
            $prefix = strtolower(Str::random(12));
        } while (DeveloperApiCredential::query()->where('prefix', $prefix)->exists());

        $secret = Str::random(48);
        $credential = $organization->credentials()->create([
            'created_by_user_id' => $creator?->getKey(),
            'name' => $name,
            'prefix' => $prefix,
            'secret_hash' => hash('sha256', $secret),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ]);

        return new IssuedDeveloperApiCredential(
            credential: $credential,
            plainTextToken: "psa_{$prefix}.{$secret}",
        );
    }

    public function revoke(DeveloperApiCredential $credential): void
    {
        if ($credential->revoked_at !== null) {
            return;
        }

        $credential->forceFill(['revoked_at' => now()])->saveQuietly();
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    private function normalizeScopes(array $scopes): array
    {
        return collect($scopes)
            ->map(fn (mixed $scope): string => trim((string) $scope))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
