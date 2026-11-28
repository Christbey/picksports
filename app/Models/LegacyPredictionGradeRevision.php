<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LegacyPredictionGradeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'result_snapshot' => 'array', 'prediction_snapshot' => 'array',
            'grade_snapshot' => 'array', 'evaluation_snapshot' => 'array',
            'original_graded_at' => 'immutable_datetime', 'recorded_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Legacy grade revisions are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('Legacy grade revisions are immutable and cannot be deleted.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Legacy grade revisions are immutable.');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Legacy grade revisions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Legacy grade revisions cannot be deleted.'));
    }
}
