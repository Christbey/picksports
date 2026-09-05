<?php

namespace App\DataTransferObjects\DeveloperPlatform;

use App\Models\DeveloperWebhookEndpoint;

final readonly class IssuedDeveloperWebhookEndpoint
{
    public function __construct(
        public DeveloperWebhookEndpoint $endpoint,
        public string $signingSecret,
    ) {}
}
