<?php

namespace App\Models;

use App\Models\Concerns\UsesBillingUtcDates;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Model methods are guarded; raw SQL/query-builder writes remain an explicit administrative boundary. */
abstract class BillingLedgerEntry extends Model
{
    use UsesBillingUtcDates;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    abstract protected function immutableFields(): array;

    abstract protected function mutableFields(): array;

    abstract protected function terminal(string $status): bool;

    abstract protected function statuses(): array;

    public function save(array $options = []): bool
    {
        if (! in_array($this->status, $this->statuses(), true)) {
            throw new LogicException('Invalid billing ledger status.');
        }
        if (! $this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            BillingCustomerReconciliation::on($this->getConnection()->getName())
                ->whereKey($this->getRawOriginal('reconciliation_id'))->lockForUpdate()->firstOrFail();
            $current = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($this->terminal((string) $current->status)) {
                throw new LogicException('Terminal billing ledger entries are immutable.');
            }
            foreach ($this->immutableFields() as $field) {
                if ($this->isDirty($field) || $this->identityValue($this->getOriginal($field)) !== $this->identityValue($current->getAttribute($field))) {
                    throw new LogicException('Billing ledger identity is immutable.');
                }
            }
            if (array_diff(array_keys($this->getDirty()), [...$this->mutableFields(), 'updated_at']) !== []) {
                throw new LogicException('Unsupported billing ledger mutation.');
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Billing ledger history cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Billing ledger history cannot be numerically mutated.');
    }

    private function identityValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('U.u');
        }
        if (is_array($value)) {
            // Native MySQL JSON canonicalizes object-key order; list order remains significant.
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(fn ($item) => $this->identityValue($item), $value);
        }

        return $value;
    }
}
