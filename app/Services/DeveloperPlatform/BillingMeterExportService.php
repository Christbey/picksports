<?php

namespace App\Services\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\BillingMeterBatchPayload;
use DateTimeInterface;

class BillingMeterExportService
{
    public function __construct(private readonly DeveloperMeterBatchBuilder $builder) {}

    public function prepare(
        DateTimeInterface $periodStart,
        DateTimeInterface $periodEnd,
        string $meterCode = 'api_request_units',
    ): BillingMeterBatchPayload {
        return BillingMeterBatchPayload::fromBatch(
            $this->builder->build($periodStart, $periodEnd, $meterCode),
        );
    }
}
