<?php

namespace App\Models\CFB;

use App\Models\Concerns\ResolvesPlayerHeadshotUrls;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<\Database\Factories\CfbPlayerFactory> */
    use HasFactory, ResolvesPlayerHeadshotUrls;

    protected $table = 'cfb_players';

    protected $fillable = [
        'team_id',
        'espn_id',
        'first_name',
        'last_name',
        'full_name',
        'name',
        'display_name',
        'jersey_number',
        'jersey',
        'position',
        'height',
        'weight',
        'year',
        'hometown',
        'headshot_url',
        'headshot',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    public function setNameAttribute(?string $value): void
    {
        $this->attributes['full_name'] = $value;
    }

    public function setDisplayNameAttribute(?string $value): void
    {
        $this->attributes['full_name'] ??= $value;
    }

    public function setJerseyAttribute(?string $value): void
    {
        $this->attributes['jersey_number'] = $value;
    }

    public function setHeadshotAttribute(?string $value): void
    {
        $this->attributes['headshot_url'] = $value;
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'player_id');
    }
}
