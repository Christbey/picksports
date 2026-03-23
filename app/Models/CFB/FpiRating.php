<?php

namespace App\Models\CFB;

use Database\Factories\CfbFpiRatingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FpiRating extends Model
{
    /** @use HasFactory<CfbFpiRatingFactory> */
    use HasFactory;

    protected $table = 'cfb_fpi_ratings';

    protected $fillable = [
        'team_id',
        'season',
        'week',
        'fpi',
        'offense',
        'defense',
        'special_teams',
        'fpi_rating',
        'fpi_rank',
        'offensive_fpi',
        'defensive_fpi',
        'special_teams_fpi',
    ];

    protected function casts(): array
    {
        return [
            'fpi' => 'decimal:1',
            'fpi_rank' => 'integer',
            'offense' => 'decimal:1',
            'defense' => 'decimal:1',
            'special_teams' => 'decimal:1',
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

    public function getFpiRatingAttribute(): ?float
    {
        return $this->fpi === null ? null : (float) $this->fpi;
    }

    public function getOffensiveFpiAttribute(): ?float
    {
        return $this->offense === null ? null : (float) $this->offense;
    }

    public function getDefensiveFpiAttribute(): ?float
    {
        return $this->defense === null ? null : (float) $this->defense;
    }

    public function getSpecialTeamsFpiAttribute(): ?float
    {
        return $this->special_teams === null ? null : (float) $this->special_teams;
    }

    public function getFpiRankAttribute(): ?int
    {
        $value = $this->attributes['fpi_rank'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
