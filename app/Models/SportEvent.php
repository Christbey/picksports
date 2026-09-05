<?php

namespace App\Models;

use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use Database\Factories\SportEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SportEvent extends Model
{
    /** @use HasFactory<SportEventFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'public_id',
        'sport',
        'season',
        'season_type',
        'week',
        'starts_at',
        'name',
        'short_name',
        'status',
        'neutral_site',
    ];

    protected function casts(): array
    {
        return [
            'season' => 'integer',
            'week' => 'integer',
            'starts_at' => 'immutable_datetime',
            'neutral_site' => 'boolean',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function providerMappings(): HasMany
    {
        return $this->hasMany(SportEventProviderMapping::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(CanonicalPrediction::class);
    }

    public function inputSnapshots(): HasMany
    {
        return $this->hasMany(EventInputSnapshot::class);
    }

    public function calculationRuns(): HasMany
    {
        return $this->hasMany(CalculationRun::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(SportEventResult::class);
    }

    public function nflGame(): HasOne
    {
        return $this->hasOne(NflGame::class);
    }

    public function mlbGame(): HasOne
    {
        return $this->hasOne(MlbGame::class);
    }

    public function nbaGame(): HasOne
    {
        return $this->hasOne(NbaGame::class);
    }

    public function wnbaGame(): HasOne
    {
        return $this->hasOne(WnbaGame::class);
    }

    public function cbbGame(): HasOne
    {
        return $this->hasOne(CbbGame::class);
    }

    public function wcbbGame(): HasOne
    {
        return $this->hasOne(WcbbGame::class);
    }

    public function cfbGame(): HasOne
    {
        return $this->hasOne(CfbGame::class);
    }

    protected static function newFactory(): SportEventFactory
    {
        return SportEventFactory::new();
    }
}
