<?php

namespace App\Models;

use App\Casts\UtcEvidenceDateTime;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only instance API; explicit trusted query-builder/SQL is a separate boundary. */
class PredictionPostgameEnrichment extends Model
{
    public const CLOSING_MARKET = 'closing_market_v1';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason_codes' => 'array', 'entry_context' => 'array', 'source_quotes' => 'array', 'output' => 'array',
            'revision' => 'integer', 'observed_at' => UtcEvidenceDateTime::class, 'source_max_captured_at' => UtcEvidenceDateTime::class];
    }

    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Postgame enrichment receipts are immutable.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        if ($this->exists) {
            throw new LogicException('Postgame enrichment receipts cannot be deleted.');
        }

        return parent::delete();
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Postgame enrichment receipts cannot perform numeric mutations.');
    }
}
