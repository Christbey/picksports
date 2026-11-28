<?php

namespace App\Models;

class BillingMutationReceipt extends BillingMutationRecord
{
    protected function casts(): array
    {
        return ['state_id' => 'integer', 'intent_id' => 'integer', 'lease_sequence' => 'integer',
            'cause_receipt_id' => 'integer', 'facts' => 'array', 'recorded_at' => 'immutable_datetime'];
    }
}
