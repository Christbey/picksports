<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperWebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperWebhookDelivery extends Model
{
    /** @use HasFactory<DeveloperWebhookDeliveryFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'developer_webhook_outbox_event_id',
        'developer_webhook_endpoint_id',
        'status',
        'attempts',
        'available_at',
        'locked_at',
        'last_attempt_at',
        'delivered_at',
        'response_status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'response_status' => 'integer',
        ];
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(DeveloperWebhookOutboxEvent::class, 'developer_webhook_outbox_event_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(DeveloperWebhookEndpoint::class, 'developer_webhook_endpoint_id');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query
            ->whereIn('status', ['pending', 'retry'])
            ->where('available_at', '<=', now())
            ->whereNull('locked_at');
    }

    protected static function newFactory(): DeveloperWebhookDeliveryFactory
    {
        return DeveloperWebhookDeliveryFactory::new();
    }
}
