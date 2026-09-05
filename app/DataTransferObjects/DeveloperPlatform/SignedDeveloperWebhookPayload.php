<?php

namespace App\DataTransferObjects\DeveloperPlatform;

final readonly class SignedDeveloperWebhookPayload
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public array $headers,
    ) {}
}
