<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameWeather extends Model
{
    protected $table = 'cfb_game_weather';

    protected $fillable = [
        'game_id',
        'latitude',
        'longitude',
        'location_source',
        'provider',
        'observed_at',
        'temperature_f',
        'feels_like_f',
        'wind_speed_mph',
        'wind_gust_mph',
        'wind_direction_degrees',
        'precipitation_probability',
        'precipitation_inches',
        'humidity_percent',
        'condition_code',
        'is_indoor',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'observed_at' => 'datetime',
            'temperature_f' => 'decimal:2',
            'feels_like_f' => 'decimal:2',
            'wind_speed_mph' => 'decimal:2',
            'wind_gust_mph' => 'decimal:2',
            'wind_direction_degrees' => 'integer',
            'precipitation_probability' => 'decimal:2',
            'precipitation_inches' => 'decimal:3',
            'humidity_percent' => 'decimal:2',
            'is_indoor' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'game_id');
    }
}
