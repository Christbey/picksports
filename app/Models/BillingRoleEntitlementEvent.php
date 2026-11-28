<?php

namespace App\Models;

use App\Casts\UtcEvidenceDateTime;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only ordinary instance APIs; trusted bulk SQL remains an explicit boundary. */
class BillingRoleEntitlementEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_grant' => 'array', 'after_grant' => 'array', 'observed_at' => UtcEvidenceDateTime::class];
    }

    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Billing entitlement audit is immutable.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        if ($this->exists) {
            throw new LogicException('Billing entitlement audit cannot be deleted.');
        }

        return parent::delete();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Billing entitlement audit cannot perform numeric mutations.');
    }
}
