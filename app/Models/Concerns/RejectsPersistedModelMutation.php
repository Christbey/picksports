<?php

namespace App\Models\Concerns;

use LogicException;

/** Protects instance methods, including quiet writes; raw query builders remain a separate boundary. */
trait RejectsPersistedModelMutation
{
    abstract protected function immutableMutationMessage(): string;

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException($this->immutableMutationMessage());
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException($this->immutableMutationMessage());
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException($this->immutableMutationMessage());
    }
}
