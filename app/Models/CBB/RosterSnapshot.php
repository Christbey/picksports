<?php

namespace App\Models\CBB;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\CbbRosterSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RosterSnapshot extends Model
{
    use HasFactory;

    protected static function newFactory(): CbbRosterSnapshotFactory
    {
        return CbbRosterSnapshotFactory::new();
    }

    protected $table = 'cbb_roster_snapshots';

    protected $fillable = ['team_id', 'season', 'source', 'observed_at', 'content_hash', 'provider_metadata'];

    protected function casts(): array
    {
        return ['season' => 'integer', 'observed_at' => 'immutable_datetime', 'provider_metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Roster observations are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Roster observations are immutable.'));
    }

    public static function knownAt(int $teamId, int $season, CarbonInterface $asOf): ?self
    {
        $asOf = CarbonImmutable::instance($asOf)->setTimezone(config('app.timezone'));

        return static::query()->where('team_id', $teamId)->where('season', $season)->where('observed_at', '<=', $asOf)->where('created_at', '<=', $asOf)->orderByDesc('observed_at')->orderByDesc('id')->first();
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RosterEntry::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
