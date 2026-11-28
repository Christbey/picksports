<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class PlayerPropGradeRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entry_snapshot' => 'array', 'stat_snapshot' => 'array', 'previous_projection' => 'array',
            'projection' => 'array', 'revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function save(array $options = [])
    {
        if ($this->exists && $this->isDirty()) {
            throw new LogicException('Prop grade revisions are append-only, including quiet saves.');
        }
        $mapping = ['nba' => 'basketball_nba', 'cbb' => 'basketball_ncaab', 'wnba' => 'basketball_wnba',
            'nfl' => 'americanfootball_nfl', 'cfb' => 'americanfootball_ncaaf', 'mlb' => 'baseball_mlb'];
        $sources = [];
        foreach ($mapping as $slug => $provider) {
            if ($this->getAttribute($slug.'_prop_id') !== null) {
                $sources[$slug] = $this->getAttribute($slug.'_prop_id');
            }
        }
        $slug = array_key_first($sources);
        if (count($sources) !== 1 || $this->prop_table !== $slug.'_player_props' || $this->sport !== $mapping[$slug]
            || (int) $this->prop_id !== (int) $sources[$slug]) {
            throw new LogicException('Prop grade receipt requires exactly one matching sport/table/source identity.');
        }

        return parent::save($options);
    }

    protected function incrementOrDecrement($column, $amount, $extra, $method)
    {
        throw new LogicException('Prop grade revisions are append-only, including numeric mutation paths.');
    }

    public function delete()
    {
        throw new LogicException('Prop grade revisions cannot be deleted, including quiet deletes.');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Prop grade revisions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Prop grade revisions cannot be deleted.'));
    }
}
