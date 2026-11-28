<?php

namespace App\Models\NBA;

use App\Models\Concerns\RejectsPersistedModelMutation;
use Illuminate\Database\Eloquent\Model;

abstract class ImmutableInjuryRecord extends Model
{
    use RejectsPersistedModelMutation;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function immutableMutationMessage(): string
    {
        return 'NBA injury evidence is immutable.';
    }
}
