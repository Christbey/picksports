<?php

namespace App\Models\CFB;

use Illuminate\Database\Eloquent\Model;

class AvailabilityReport extends Model
{
    protected $table = 'cfb_availability_reports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entries' => 'array', 'issues' => 'array', 'reported_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }
}
