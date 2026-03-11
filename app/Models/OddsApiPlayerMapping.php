<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OddsApiPlayerMapping extends Model
{
    protected $fillable = [
        'espn_player_name',
        'espn_player_id',
        'odds_api_player_name',
        'suggested_espn_player_name',
        'suggested_player_id',
        'suggested_match_quality_score',
        'sport',
    ];

    protected function casts(): array
    {
        return [
            'espn_player_id' => 'integer',
            'suggested_player_id' => 'integer',
            'suggested_match_quality_score' => 'integer',
        ];
    }
}
