<?php

namespace App\Models;

use App\Models\Concerns\UsesBillingUtcDates;
use Illuminate\Database\Eloquent\Model;

class BillingCustomerReconciliation extends Model
{
    use UsesBillingUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'cache_invalidation_pending' => 'boolean', 'cache_generation' => 'integer',
            'operator_request_id' => 'integer', 'operator_generation' => 'integer', 'requested_targets' => 'array',
            'cache_lease_sequence' => 'integer', 'generation' => 'integer', 'lease_sequence' => 'integer',
            'attempts' => 'integer', 'lease_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime', 'last_observed_at' => 'immutable_datetime'];
    }
}
