<?php

namespace App\Services\Auth\Native;

use App\Models\DeviceSession;
use Carbon\CarbonInterface;

final readonly class DeviceTokenPair
{
    public function __construct(
        public DeviceSession $deviceSession,
        public string $accessToken,
        public string $refreshToken,
        public CarbonInterface $accessTokenExpiresAt,
        public CarbonInterface $refreshTokenExpiresAt,
    ) {}
}
