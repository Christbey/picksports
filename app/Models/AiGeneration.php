<?php

namespace App\Models;

use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiGeneration extends Model
{
    /** @use HasFactory<AiGenerationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'public_id',
        'purpose',
        'context_type',
        'context_id',
        'prompt_version',
        'provider',
        'model',
        'status',
        'input_hash',
        'output_hash',
        'input_tokens',
        'output_tokens',
        'cached_input_tokens',
        'cost_usd',
        'latency_ms',
        'error_code',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'latency_ms' => 'integer',
            'metadata' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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

    protected static function newFactory(): AiGenerationFactory
    {
        return AiGenerationFactory::new();
    }
}
