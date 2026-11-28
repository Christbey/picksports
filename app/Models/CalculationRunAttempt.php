<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

class CalculationRunAttempt extends Model
{
    public const STATUSES = ['running', 'succeeded', 'failed', 'abandoned'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'legacy_import' => 'boolean',
        ];
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        if ($this->isDirty($this->getKeyName())) {
            throw new LogicException('Calculation attempt identity and lease are immutable.');
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            CalculationRun::on($this->getConnectionName())
                ->whereKey($this->getRawOriginal('calculation_run_id'))->lockForUpdate()->firstOrFail();
            $persisted = $this->newQuery()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($persisted->status !== 'running') {
                throw new LogicException('Completed calculation attempts are immutable.');
            }
            foreach (['calculation_run_id', 'attempt_number', 'owner_token', 'started_at', 'lease_expires_at', 'legacy_import'] as $field) {
                if ($field === 'legacy_import'
                    ? (bool) $this->legacy_import !== (bool) $persisted->legacy_import
                    : ($this->getAttributes()[$field] ?? null) !== ($persisted->getAttributes()[$field] ?? null)) {
                    throw new LogicException('Calculation attempt identity and lease are immutable.');
                }
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Calculation attempt audit history cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Calculation attempt numeric fields cannot be incremented or decremented.');
    }

    public function setStatusAttribute(string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported calculation attempt status.');
        }
        $this->attributes['status'] = $status;
    }

    public function calculationRun(): BelongsTo
    {
        return $this->belongsTo(CalculationRun::class);
    }
}
