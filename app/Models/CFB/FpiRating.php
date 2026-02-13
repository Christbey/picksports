<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FpiRating extends Model
{
    /** @use HasFactory<\Database\Factories\CfbFpiRatingFactory> */
    use HasFactory;

    protected $table = 'cfb_fpi_ratings';

    protected $fillable = [
        'team_id',
        'season',
        'week',
        'fpi_rating',
        'fpi_rank',
        'offensive_fpi',
        'defensive_fpi',
        'special_teams_fpi',
    ];

    protected function casts(): array
    {
        return [
            'fpi_rating' => 'decimal:1',
            'offensive_fpi' => 'decimal:1',
            'defensive_fpi' => 'decimal:1',
            'special_teams_fpi' => 'decimal:1',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
