<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CbbBracket extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $bracket): void {
            if (! $bracket->public_id) {
                $bracket->public_id = (string) Str::uuid();
            }
        });
    }

    protected $fillable = [
        'public_id',
        'user_id',
        'group_id',
        'season',
        'name',
        'picks',
        'points_earned',
        'max_points_remaining',
        'correct_picks',
        'incorrect_picks',
        'graded_through_round',
        'results',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'public_id' => 'string',
            'group_id' => 'integer',
            'picks' => 'array',
            'points_earned' => 'integer',
            'max_points_remaining' => 'integer',
            'correct_picks' => 'integer',
            'incorrect_picks' => 'integer',
            'results' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public static function lockAtForSeason(int $season): ?CarbonImmutable
    {
        $definition = config("cbb_bracket.locks.{$season}");

        if (! is_array($definition) || empty($definition['at'])) {
            return null;
        }

        $timezone = $definition['timezone'] ?? config('app.timezone');

        return CarbonImmutable::parse($definition['at'], $timezone);
    }

    public static function isLockedForSeason(int $season): bool
    {
        $lockAt = static::lockAtForSeason($season);

        if (! $lockAt) {
            return false;
        }

        return CarbonImmutable::now($lockAt->getTimezone())->greaterThanOrEqualTo($lockAt);
    }

    public function lockAt(): ?CarbonImmutable
    {
        return static::lockAtForSeason((int) $this->season);
    }

    public function isLocked(): bool
    {
        return static::isLockedForSeason((int) $this->season);
    }
}
