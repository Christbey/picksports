<?php

namespace App\Models\Concerns;

/** Avoid repeatedly decoding large frozen prediction documents on one model instance. */
trait CachesJsonArrayCasts
{
    private array $decodedJsonArrays = [];

    private bool $cacheJsonArrays = false;

    /** Decode only for this read scope, then release potentially large documents. */
    public function withCachedJsonArrays(\Closure $read): mixed
    {
        if ($this->cacheJsonArrays) {
            return $read();
        }

        $this->cacheJsonArrays = true;
        try {
            return $read();
        } finally {
            $this->cacheJsonArrays = false;
            $this->decodedJsonArrays = [];
        }
    }

    protected function castAttribute($key, $value)
    {
        if (($this->getCasts()[$key] ?? null) !== 'array') {
            return parent::castAttribute($key, $value);
        }
        if (! $this->cacheJsonArrays) {
            return $this->decodeArrayAttribute($key, $value);
        }

        $cached = $this->decodedJsonArrays[$key] ?? null;
        if ($cached !== null && $cached['raw'] === $value) {
            return $cached['decoded'];
        }

        $decoded = $this->decodeArrayAttribute($key, $value);
        $this->decodedJsonArrays[$key] = ['raw' => $value, 'decoded' => $decoded];

        return $decoded;
    }

    protected function decodeArrayAttribute($key, $value)
    {
        return parent::castAttribute($key, $value);
    }
}
