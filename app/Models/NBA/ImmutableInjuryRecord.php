<?php

namespace App\Models\NBA;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class ImmutableInjuryRecord extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('NBA injury evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('NBA injury evidence is immutable.'));
    }
}
