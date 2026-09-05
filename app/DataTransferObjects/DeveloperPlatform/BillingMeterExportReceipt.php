<?php

namespace App\DataTransferObjects\DeveloperPlatform;

final readonly class BillingMeterExportReceipt
{
    public function __construct(
        public string $providerReference,
        public string $acceptedAt,
    ) {}
}
