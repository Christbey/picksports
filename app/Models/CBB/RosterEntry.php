<?php

namespace App\Models\CBB;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Database\Factories\CbbRosterEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RosterEntry extends Model
{
    use HasFactory, RejectsPersistedModelMutation;

    protected static function newFactory(): CbbRosterEntryFactory
    {
        return CbbRosterEntryFactory::new();
    }

    public $timestamps = false;

    protected $table = 'cbb_roster_entries';

    protected $fillable = ['roster_snapshot_id', 'player_id', 'attributes'];

    protected function casts(): array
    {
        return ['attributes' => 'array'];
    }

    protected function immutableMutationMessage(): string
    {
        return 'Roster membership observations are immutable.';
    }

    public function rosterSnapshot(): BelongsTo
    {
        return $this->belongsTo(RosterSnapshot::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
