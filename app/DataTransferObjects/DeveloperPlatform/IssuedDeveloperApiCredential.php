<?php

namespace App\DataTransferObjects\DeveloperPlatform;

use App\Models\DeveloperApiCredential;

final readonly class IssuedDeveloperApiCredential
{
    public function __construct(
        public DeveloperApiCredential $credential,
        public string $plainTextToken,
    ) {}
}
