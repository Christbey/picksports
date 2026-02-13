<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAlertPreference extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'enabled',
        'sports',
        'notification_types',
        'enabled_template_ids',
        'minimum_edge',
        'time_window_start',
        'time_window_end',
        'digest_mode',
        'digest_time',
        'phone_number',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sports' => 'array',
            'notification_types' => 'array',
            'enabled_template_ids' => 'array',
            'minimum_edge' => 'decimal:2',
            'time_window_start' => 'datetime:H:i:s',
            'time_window_end' => 'datetime:H:i:s',
            'digest_time' => 'datetime:H:i:s',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shouldReceiveEmailNotifications(): bool
    {
        return $this->enabled && in_array('email', $this->notification_types);
    }

    public function shouldReceiveSmsNotifications(): bool
    {
        return $this->enabled && in_array('sms', $this->notification_types) && $this->phone_number !== null;
    }

    public function shouldReceivePushNotifications(): bool
    {
        return $this->enabled && in_array('push', $this->notification_types);
    }

    public function isWithinTimeWindow(): bool
    {
        $now = now()->format('H:i:s');

        return $now >= $this->time_window_start->format('H:i:s')
            && $now <= $this->time_window_end->format('H:i:s');
    }

    public function isInterestedInSport(string $sport): bool
    {
        return in_array(strtolower($sport), array_map('strtolower', $this->sports));
    }

    public function hasSufficientEdge(float $expectedValue): bool
    {
        return $expectedValue >= $this->minimum_edge;
    }

    public function shouldSendAlertToUser(string $sport, float $expectedValue): bool
    {
        return $this->isInterestedInSport($sport)
            && $this->hasSufficientEdge($expectedValue)
            && $this->isWithinTimeWindow()
            && $this->digest_mode === 'realtime';
    }

    public function shouldReceiveTemplate(int $templateId): bool
    {
        if (! $this->enabled) {
            return false;
        }

        // If no templates are specified, send all templates (default behavior)
        if (empty($this->enabled_template_ids)) {
            return true;
        }

        return in_array($templateId, $this->enabled_template_ids);
    }
}
