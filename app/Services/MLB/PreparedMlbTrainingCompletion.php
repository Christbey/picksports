<?php

namespace App\Services\MLB;

/** Inert carrier; only its preparing service's exact-object ownership may authorize commit. */
final readonly class PreparedMlbTrainingCompletion
{
    public function __construct(
        public PreparedMlbTabularRun $registration,
        public string $cycleId,
        public int $attemptId,
        public string $ownerToken,
        public string $pythonRunId,
        public string $outputHash,
        public string $datasetHash,
        public string $fingerprint,
        public array $specification,
        public array $decision,
    ) {}
}
