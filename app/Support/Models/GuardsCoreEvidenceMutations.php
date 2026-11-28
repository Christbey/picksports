<?php

namespace App\Support\Models;

use App\Services\Predictions\CoreEvidenceMutationGuard;
use LogicException;

/** Guards instance APIs; explicit query-builder/bulk SQL remains a trusted caller boundary. */
trait GuardsCoreEvidenceMutations
{
    public function save(array $options = [])
    {
        return app(CoreEvidenceMutationGuard::class)->run($this, 'save', $this->getDirty(), fn () => parent::save($options));
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return app(CoreEvidenceMutationGuard::class)->run($this, 'delete', [], fn () => parent::delete());
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        if (! $this->exists) {
            throw new LogicException('Unsaved evidence instances cannot perform implicit bulk numeric updates.');
        }
        $current = $this->getAttribute($column);
        $value = is_numeric($current) && is_numeric($amount)
            ? $current + ($method === 'increment' ? $amount : -$amount)
            : null;
        $patch = array_merge([$column => $value], $extra);

        return app(CoreEvidenceMutationGuard::class)->run($this, 'numeric', $patch,
            fn () => parent::incrementOrDecrement($column, $amount, $extra, $method));
    }
}
