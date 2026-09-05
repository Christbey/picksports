<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperWebhookOutboxEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperWebhookOutboxEvent extends Model
{
    /** @use HasFactory<DeveloperWebhookOutboxEventFactory> */
    use HasFactory, HasPublicUlid;

    public const UPDATED_AT = null;

    protected $fillable = [
        'public_id',
        'developer_organization_id',
        'event_id',
        'event_type',
        'payload',
        'payload_hash',
        'occurred_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DeveloperWebhookDelivery::class, 'developer_webhook_outbox_event_id');
    }

    protected static function newFactory(): DeveloperWebhookOutboxEventFactory
    {
        return DeveloperWebhookOutboxEventFactory::new();
    }
}
