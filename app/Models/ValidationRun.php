<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ValidationRun extends Model
{
    protected $fillable = [
        'command_name',
        'scope',
        'status',
        'summary',
        'ai_summary',
        'ai_provider',
        'ai_model',
        'ai_generated_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'ai_summary' => 'array',
            'ai_generated_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function findings(): HasMany
    {
        return $this->hasMany(ValidationFinding::class);
    }
}
