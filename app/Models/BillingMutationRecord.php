<?php

namespace App\Models;

use App\Models\Concerns\UsesBillingUtcDates;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable journal entries; raw SQL remains an explicit administrative boundary. */
abstract class BillingMutationRecord extends Model
{
    use UsesBillingUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Billing mutation history is append only.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Billing mutation history cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Billing mutation history cannot be numerically mutated.');
    }
}
