<?php

namespace App\Models;

use Database\Factories\DeveloperMeterBatchItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperMeterBatchItem extends Model
{
    /** @use HasFactory<DeveloperMeterBatchItemFactory> */
    use HasFactory;

    protected $fillable = [
        'developer_meter_batch_id',
        'developer_organization_id',
        'developer_product_id',
        'idempotency_key',
        'usage_record_count',
        'units',
        'dimensions',
    ];

    protected function casts(): array
    {
        return [
            'usage_record_count' => 'integer',
            'units' => 'integer',
            'dimensions' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DeveloperMeterBatch::class, 'developer_meter_batch_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(DeveloperProduct::class, 'developer_product_id');
    }

    protected static function newFactory(): DeveloperMeterBatchItemFactory
    {
        return DeveloperMeterBatchItemFactory::new();
    }
}
