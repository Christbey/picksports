<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeveloperWebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeveloperWebhookEndpoint extends Model
{
    /** @use HasFactory<DeveloperWebhookEndpointFactory> */
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'public_id',
        'developer_organization_id',
        'name',
        'url',
        'signing_secret',
        'events',
        'status',
        'last_success_at',
        'last_failure_at',
    ];

    protected $hidden = ['signing_secret'];

    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'events' => 'array',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(DeveloperWebhookDelivery::class);
    }

    public function subscribesTo(string $eventType): bool
    {
        return $this->status === 'active'
            && (in_array('*', $this->events ?? [], true) || in_array($eventType, $this->events ?? [], true));
    }

    protected static function newFactory(): DeveloperWebhookEndpointFactory
    {
        return DeveloperWebhookEndpointFactory::new();
    }
}
