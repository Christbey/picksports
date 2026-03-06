<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EpaStateBaseline extends Model
{
    protected $fillable = [
        'sport',
        'season',
        'source_season',
        'state_key',
        'expected_points',
        'sample_size',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'source_season' => 'integer',
            'expected_points' => 'decimal:4',
            'sample_size' => 'integer',
        ];
    }
}
