<?php

namespace App\Services\Auth\Policy;

use Carbon\CarbonImmutable;

final readonly class ApiTokenGrant
{
    public function __construct(public string $plainTextToken, public string $profile, public array $abilities, public ?int $expiresEpoch, public ?int $lifetimeSeconds) {}

    public function metadata(): array
    {
        return ['token_profile' => $this->profile, 'abilities' => $this->abilities,
            'expires_at' => $this->expiresEpoch === null ? null : CarbonImmutable::createFromTimestampUTC($this->expiresEpoch)->format('Y-m-d\TH:i:s\Z'),
            'expires_epoch' => $this->expiresEpoch,
            'expires_in' => $this->expiresEpoch === null ? null : max(0, $this->expiresEpoch - CarbonImmutable::now('UTC')->getTimestamp())];
    }
}
