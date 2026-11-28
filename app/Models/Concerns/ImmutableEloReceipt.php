<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableEloReceipt
{
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Elo receipts are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Elo receipts cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Elo receipts cannot be numerically mutated.');
    }
}
