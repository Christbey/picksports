<?php

namespace App\Services\Billing;

final readonly class BillingMutationLease
{
    public function __construct(public int $stateId, public int $intentId, public int $userId,
        public string $ownerToken, public bool $newlyAdmitted) {}
}
