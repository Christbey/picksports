<?php

namespace App\Services\AI;

use App\Models\AiGeneration;

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
        $latencyMs = $this->unsignedCounter($latencyMs);
        $tokens = [
            'input' => $this->unsignedCounter($tokens['input'] ?? null),
            'output' => $this->unsignedCounter($tokens['output'] ?? null),
            'cached_input' => $this->unsignedCounter($tokens['cached_input'] ?? null),
        ];
        $costUsd = $this->cost($costUsd);
        $connection = $generation->getConnection();

        return $connection->transaction(function () use ($connection, $generation, $output, $latencyMs, $tokens, $costUsd, $metadata): AiGeneration {
            $generation = AiGeneration::on($connection->getName())->lockForUpdate()->findOrFail($generation->getKey());
            if ($generation->status !== 'running') {
                throw new \LogicException('Only running AI generations can be completed.');
            }

            $generation->forceFill([
                'status' => 'completed',
                'output_hash' => $this->payloadHash($output),
                'input_tokens' => $tokens['input'],
                'output_tokens' => $tokens['output'],
                'cached_input_tokens' => $tokens['cached_input'],
                'cost_usd' => $costUsd,
                'latency_ms' => $latencyMs,
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
        $latencyMs = $this->unsignedCounter($latencyMs);
        $connection = $generation->getConnection();

        return $connection->transaction(function () use ($connection, $generation, $errorCode, $latencyMs, $metadata): AiGeneration {
            $generation = AiGeneration::on($connection->getName())->lockForUpdate()->findOrFail($generation->getKey());
            if ($generation->status !== 'running') {
                throw new \LogicException('Only running AI generations can fail.');
            }

            $generation->forceFill([
                'status' => 'failed',
                'error_code' => mb_substr(trim($errorCode), 0, 500),
                'latency_ms' => $latencyMs,
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

    private function unsignedCounter(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0 || $value > 4294967295) {
            throw new \InvalidArgumentException('AI usage counters must be unsigned 32-bit integers or unavailable.');
        }

        return $value;
    }

    private function cost(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) > 64 || preg_match('/\A[0-9]+(?:\.[0-9]{1,6})?\z/D', $value) !== 1) {
            throw new \InvalidArgumentException('AI usage cost must be a nonnegative decimal with at most six fractional digits.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        if (strlen($whole) > 6) {
            throw new \InvalidArgumentException('AI usage cost exceeds the stored decimal capacity.');
        }

        return ($whole === '' ? '0' : $whole).'.'.str_pad($fraction, 6, '0');
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
