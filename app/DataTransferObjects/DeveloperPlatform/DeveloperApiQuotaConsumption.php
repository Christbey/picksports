<?php

namespace App\DataTransferObjects\DeveloperPlatform;

use App\Models\DeveloperApiUsageRecord;
use Carbon\CarbonImmutable;

final readonly class DeveloperApiQuotaConsumption
{
    public function __construct(
        public int $limit,
        public int $used,
        public int $remaining,
        public CarbonImmutable $resetsAt,
        public DeveloperApiUsageRecord $usageRecord,
    ) {}
}
