<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperMeterBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperMeterBatch extends Model
{
    /** @use HasFactory<DeveloperMeterBatchFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'meter_code',
        'period_start',
        'period_end',
        'status',
        'idempotency_key',
        'usage_record_count',
        'total_units',
        'generated_at',
        'exported_at',
        'provider_reference',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'usage_record_count' => 'integer',
            'total_units' => 'integer',
            'generated_at' => 'immutable_datetime',
            'exported_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DeveloperMeterBatchItem::class);
    }

    protected static function newFactory(): DeveloperMeterBatchFactory
    {
        return DeveloperMeterBatchFactory::new();
    }
}
