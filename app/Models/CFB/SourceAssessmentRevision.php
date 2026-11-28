<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SourceAssessmentRevision extends Model
{
    protected $table = 'cfb_source_assessment_revisions';

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'context_snapshot' => 'array', 'projection_snapshot' => 'array',
            'manifest_snapshot' => 'array', 'recorded_at' => 'immutable_datetime'];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('CFB source assessment revisions are immutable.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException('CFB source assessment revisions are immutable.');
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('CFB source assessment revisions are immutable.');
    }
}
