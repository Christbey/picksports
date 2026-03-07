<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OddsApiPlayerMapping extends Model
{
    protected $fillable = [
        'espn_player_name',
        'odds_api_player_name',
        'sport',
    ];
}
