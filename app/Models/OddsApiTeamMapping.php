<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OddsApiTeamMapping extends Model
{
    protected $fillable = [
        'espn_team_name',
        'odds_api_team_name',
        'sport',
    ];
}
