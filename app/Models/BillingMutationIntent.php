<?php

namespace App\Models;

class BillingMutationIntent extends BillingMutationRecord
{
    protected function casts(): array
    {
        return ['subject_user_id' => 'integer', 'user_id' => 'integer', 'actor_user_id' => 'integer',
            'livemode' => 'boolean', 'facts' => 'array', 'requested_at' => 'immutable_datetime'];
    }
}
