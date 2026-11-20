<?php

namespace App\Models;

use App\Models\NBA\Player;
use App\Models\Relations\AllowedLegacyPropGameMorphTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

class PlayerProp extends Model
{
    protected $fillable = [
        'gameable_type',
        'gameable_id',
        'player_id',
        'sport',
        'odds_api_event_id',
        'player_name',
        'market',
        'bookmaker',
        'line',
        'over_price',
        'under_price',
        'raw_data',
        'fetched_at',
        'actual_value',
        'hit_over',
        'error',
        'graded_at',
    ];

    protected function casts(): array
    {
        return [
            'line' => 'decimal:2',
            'over_price' => 'integer',
            'under_price' => 'integer',
            'raw_data' => 'array',
            'fetched_at' => 'datetime',
            'actual_value' => 'decimal:2',
            'hit_over' => 'boolean',
            'error' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    public function gameable(): MorphTo
    {
        $class = self::supportedGameModel($this->sport, $this->gameable_type);
        $query = $class === null ? $this->newQuery()->setEagerLoads([]) : (new $class)->newQuery();
        if ($class === null && (array_key_exists('gameable_type', $this->getAttributes()) || $this->gameable_id !== null)) {
            $query->whereRaw('1 = 0');
        }

        return new AllowedLegacyPropGameMorphTo($query, $this, 'gameable_id', 'id', 'gameable_type', 'gameable');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo($this->resolvePlayerModelClass(), 'player_id');
    }

    /** @return class-string<Model>|null */
    public static function supportedGameModel(?string $providerSport, ?string $type): ?string
    {
        $expected = match ($providerSport) {
            'basketball_nba' => NBA\Game::class,
            'basketball_ncaab' => CBB\Game::class,
            'americanfootball_nfl' => NFL\Game::class,
            'baseball_mlb' => MLB\Game::class,
            default => null,
        };
        $actual = $type !== null ? static::getActualClassNameForMorph($type) : null;

        return $expected !== null && $actual === $expected ? $expected : null;
    }

    private function resolvePlayerModelClass(): string
    {
        return match ($this->sport) {
            'basketball_nba' => Player::class,
            'basketball_ncaab' => CBB\Player::class,
            'americanfootball_nfl' => NFL\Player::class,
            'baseball_mlb' => MLB\Player::class,
            default => throw new InvalidArgumentException("Unsupported sport for player relation: {$this->sport}"),
        };
    }
}
