<?php

namespace App\Services\AI;

use App\Models\AiGeneration;
use Illuminate\Support\Facades\DB;

class AiGenerationRecorder
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     */
    public function start(
        string $purpose,
        string $promptVersion,
        string $provider,
        string $model,
        array $input,
        ?string $contextType = null,
        ?string $contextId = null,
        array $metadata = [],
    ): AiGeneration {
        return AiGeneration::query()->create([
            'purpose' => $this->identifier($purpose),
            'prompt_version' => trim($promptVersion),
            'provider' => $this->identifier($provider),
            'model' => trim($model),
            'context_type' => $contextType === null ? null : $this->identifier($contextType),
            'context_id' => $contextId,
            'status' => 'running',
            'input_hash' => $this->payloadHash($input),
            'metadata' => $metadata,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array{input?:int,output?:int,cached_input?:int}  $tokens
     * @param  array<string, mixed>  $metadata
     */
    public function complete(
        AiGeneration $generation,
        array $output,
        int $latencyMs,
        array $tokens = [],
        ?string $costUsd = null,
        array $metadata = [],
    ): AiGeneration {
        return DB::transaction(function () use ($generation, $output, $latencyMs, $tokens, $costUsd, $metadata): AiGeneration {
            $generation = AiGeneration::query()->lockForUpdate()->findOrFail($generation->getKey());
            if ($generation->status !== 'running') {
                throw new \LogicException('Only running AI generations can be completed.');
            }

            $generation->forceFill([
                'status' => 'completed',
                'output_hash' => $this->payloadHash($output),
                'input_tokens' => $this->nonNegative($tokens['input'] ?? null),
                'output_tokens' => $this->nonNegative($tokens['output'] ?? null),
                'cached_input_tokens' => $this->nonNegative($tokens['cached_input'] ?? null),
                'cost_usd' => $costUsd,
                'latency_ms' => max(0, $latencyMs),
                'metadata' => array_replace_recursive($generation->metadata ?? [], $metadata),
                'completed_at' => now(),
            ])->save();

            return $generation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fail(
        AiGeneration $generation,
        string $errorCode,
        int $latencyMs,
        array $metadata = [],
    ): AiGeneration {
        return DB::transaction(function () use ($generation, $errorCode, $latencyMs, $metadata): AiGeneration {
            $generation = AiGeneration::query()->lockForUpdate()->findOrFail($generation->getKey());
            if ($generation->status !== 'running') {
                throw new \LogicException('Only running AI generations can fail.');
            }

            $generation->forceFill([
                'status' => 'failed',
                'error_code' => mb_substr(trim($errorCode), 0, 500),
                'latency_ms' => max(0, $latencyMs),
                'metadata' => array_replace_recursive($generation->metadata ?? [], $metadata),
                'completed_at' => now(),
            ])->save();

            return $generation->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    private function identifier(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '_', $value) ?? '';
        $value = trim($value, '._-');

        if ($value === '') {
            throw new \InvalidArgumentException('AI generation identifiers cannot be empty.');
        }

        return $value;
    }

    private function nonNegative(mixed $value): ?int
    {
        return $value === null ? null : max(0, (int) $value);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
