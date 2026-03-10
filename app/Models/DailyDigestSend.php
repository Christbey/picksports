<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyDigestSend extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'digest_date',
        'sent_at',
        'predictions_count',
        'player_props_count',
    ];

    protected function casts(): array
    {
        return [
            'digest_date' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
