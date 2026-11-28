<?php

namespace App\Services\MLB;

/** Construction alone grants no authority; the preparing coordinator owns this exact instance. */
final readonly class PreparedMlbArtifactMutation
{
    public array $payload;

    public function __construct(
        public string $artifactId,
        public string $trainingRunId,
        public string $fingerprint,
        public string $action,
        array $payload,
    ) {
        // Validate before copying, including recursive/reference cycles and allocation budgets.
        MlbTabularJsonBudget::encode($payload, 65536, 10000, 12);
        $this->payload = self::detached($payload);
    }

    /** Plain scalar copy detaches external PHP references without JSON numeric type coercion. */
    private static function detached(array $payload): array
    {
        $copy = [];
        foreach ($payload as $key => $value) {
            $copy[$key] = match (true) {
                is_array($value) => self::detached($value),
                is_string($value) => (string) $value,
                is_int($value) => (int) $value,
                is_float($value) => (float) $value,
                is_bool($value) => (bool) $value,
                default => null, // Only null remains after the strict scalar allocation admission above.
            };
        }

        return $copy;
    }
}
