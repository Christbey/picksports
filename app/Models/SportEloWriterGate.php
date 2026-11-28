<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

class SportEloWriterGate extends Model
{
    public const SPORTS = ['nfl', 'nba', 'mlb', 'cbb', 'wcbb'];

    protected $primaryKey = 'sport';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['protocol_version' => 'integer', 'generation' => 'integer', 'drain_verified_at' => 'immutable_datetime'];
    }

    public function setSportAttribute(string $sport): void
    {
        if (! in_array($sport, self::SPORTS, true)) {
            throw new InvalidArgumentException('Unsupported Elo integrity sport.');
        }
        $this->attributes['sport'] = $sport;
    }

    public function save(array $options = []): bool
    {
        if ($this->exists && $this->isDirty('sport')) {
            throw new LogicException('Elo sport gate identity cannot change.');
        }

        return parent::save($options);
    }
}
