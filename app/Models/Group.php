<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Group extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'owner_id',
        'name',
        'type',
        'sport',
        'season',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $group): void {
            if (! $group->public_id) {
                $group->public_id = (string) Str::uuid();
            }
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_users')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function brackets(): HasMany
    {
        return $this->hasMany(CbbBracket::class);
    }

    public function joinLink(): HasOne
    {
        return $this->hasOne(GroupJoinLink::class);
    }
}
