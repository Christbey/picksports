<?php

namespace App\Services\Billing;

use RuntimeException;

final class BillingProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('Billing provider observation is unavailable.');
    }
}
