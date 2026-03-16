<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CfbdTeamMapping extends Model
{
    protected $fillable = [
        'cfbd_team_id',
        'cfbd_team_name',
        'cfbd_abbreviation',
        'espn_team_name',
        'sport',
        'conference',
        'division',
        'alternate_names',
    ];

    protected function casts(): array
    {
        return [
            'cfbd_team_id' => 'integer',
            'alternate_names' => 'array',
        ];
    }
}
