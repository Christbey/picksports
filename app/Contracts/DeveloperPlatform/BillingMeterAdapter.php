<?php

namespace App\Contracts\DeveloperPlatform;

use App\DataTransferObjects\DeveloperPlatform\BillingMeterBatchPayload;
use App\DataTransferObjects\DeveloperPlatform\BillingMeterExportReceipt;

interface BillingMeterAdapter
{
    public function export(BillingMeterBatchPayload $batch): BillingMeterExportReceipt;
}
