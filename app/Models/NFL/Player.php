<?php

namespace App\Models\NFL;

use App\Models\Concerns\ResolvesPlayerHeadshotUrls;
use Database\Factories\NflPlayerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    /** @use HasFactory<NflPlayerFactory> */
    use HasFactory, ResolvesPlayerHeadshotUrls;

    protected $table = 'nfl_players';

    protected $fillable = [
        'team_id',
        'espn_id',
        'first_name',
        'last_name',
        'full_name',
        'jersey_number',
        'position',
        'height',
        'weight',
        'age',
        'experience',
        'college',
        'status',
        'headshot_url',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function playerStats(): HasMany
    {
        return $this->hasMany(PlayerStat::class, 'player_id');
    }

    public function injuries(): HasMany
    {
        return $this->hasMany(PlayerInjury::class, 'player_id');
    }

    public function activeInjuries(): HasMany
    {
        return $this->injuries()
            ->where('is_active', true)
            ->orderByDesc('updated_at');
    }

    public function depthChartEntries(): HasMany
    {
        return $this->hasMany(DepthChartEntry::class, 'player_id');
    }

    protected static function newFactory(): NflPlayerFactory
    {
        return NflPlayerFactory::new();
    }
}
