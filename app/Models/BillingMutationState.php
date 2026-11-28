<?php

namespace App\Models;

use App\Models\Concerns\UsesBillingUtcDates;
use Illuminate\Database\Eloquent\Model;

/** Mutable current cursor; every external admission/outcome is retained separately in immutable journal rows. */
class BillingMutationState extends Model
{
    use UsesBillingUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['subject_user_id' => 'integer', 'user_id' => 'integer', 'active_intent_id' => 'integer',
            'result' => 'encrypted:array', 'result_expires_at' => 'immutable_datetime', 'reconciliation_id' => 'integer', 'lease_sequence' => 'integer', 'attempts' => 'integer',
            'last_receipt_id' => 'integer', 'execution_started_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }
}
