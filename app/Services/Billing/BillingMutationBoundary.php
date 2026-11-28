<?php

namespace App\Services\Billing;

use App\Models\BillingMutationIntent;
use App\Models\BillingMutationReceipt;
use App\Models\BillingMutationState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BillingMutationBoundary
{
    public function __construct(private readonly BillingDatabaseBoundary $database) {}

    public function assert(?User $user = null, bool $standalone = true): void
    {
        $this->database->assert($standalone);
        $connection = DB::connection()->getName();
        foreach ([new BillingMutationIntent, new BillingMutationReceipt, new BillingMutationState, $user ?? new User] as $model) {
            if ($model->getConnection()->getName() !== $connection) {
                throw new LogicException('Billing mutation models require the checked default connection.');
            }
        }
    }
}
