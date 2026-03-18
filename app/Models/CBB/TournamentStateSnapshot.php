<?php

namespace App\Models\CBB;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentStateSnapshot extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<self>> */
    use HasFactory;

    protected $table = 'cbb_tournament_state_snapshots';

    protected $fillable = [
        'season',
        'as_of',
        'source',
        'status',
        'trigger_game_id',
        'games_final_count',
        'games_remaining_count',
        'field_size',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'as_of' => 'datetime',
            'trigger_game_id' => 'integer',
            'games_final_count' => 'integer',
            'games_remaining_count' => 'integer',
            'field_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function triggerGame(): BelongsTo
    {
        return $this->belongsTo(Game::class, 'trigger_game_id');
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(TournamentForecast::class, 'snapshot_id');
    }
}
