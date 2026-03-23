<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ValidationFinding extends Model
{
    protected $fillable = [
        'validation_run_id',
        'sport',
        'check_type',
        'scope_type',
        'scope_id',
        'status',
        'severity',
        'message',
        'facts',
        'recommended_action',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ValidationRun::class, 'validation_run_id');
    }
}
