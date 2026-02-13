<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAlertSent extends Model
{
    protected $table = 'user_alerts_sent';

    protected $fillable = [
        'user_id',
        'sport',
        'alert_type',
        'prediction_id',
        'prediction_type',
        'expected_value',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_value' => 'decimal:2',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getTodayCountForUser(int $userId): int
    {
        return static::where('user_id', $userId)
            ->whereDate('sent_at', today())
            ->count();
    }

    public static function getCountForUserSince(int $userId, \DateTimeInterface $since): int
    {
        return static::where('user_id', $userId)
            ->where('sent_at', '>=', $since)
            ->count();
    }
}
