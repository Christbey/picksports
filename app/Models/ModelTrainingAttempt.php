<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

class ModelTrainingAttempt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'result' => 'array',
        ];
    }

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            // Follow the service lock order and inspect persisted state, including stale/quiet instances.
            ModelRun::query()->select(['id'])->whereKey($this->getOriginal('cycle_run_id'))->lockForUpdate()->firstOrFail();
            $current = self::query()->select(['id', 'status', 'cycle_run_id', 'attempt_number', 'owner_token', 'python_run_id', 'started_at', 'lease_expires_at'])
                ->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== 'running') {
                throw new LogicException('Terminal training attempts are immutable.');
            }
            foreach (['cycle_run_id', 'attempt_number', 'owner_token', 'python_run_id', 'started_at', 'lease_expires_at'] as $field) {
                if ($this->getRawOriginal($field) !== $current->getRawOriginal($field) || $this->isDirty($field)) {
                    throw new LogicException('Training attempt identity and lease are immutable.');
                }
            }
            if (! in_array($this->status, ['running', 'succeeded', 'failed', 'abandoned'], true)) {
                throw new LogicException('Invalid training attempt status.');
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        throw new LogicException('Training attempts cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Training attempts cannot be numerically mutated.');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(ModelRun::class, 'cycle_run_id');
    }
}
