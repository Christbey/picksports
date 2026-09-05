<?php

namespace App\Models;

use Database\Factories\SportEventProviderMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportEventProviderMapping extends Model
{
    /** @use HasFactory<SportEventProviderMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'sport_event_id',
        'provider',
        'provider_event_id',
        'provider_uid',
    ];

    public function sportEvent(): BelongsTo
    {
        return $this->belongsTo(SportEvent::class);
    }

    protected static function newFactory(): SportEventProviderMappingFactory
    {
        return SportEventProviderMappingFactory::new();
    }
}
