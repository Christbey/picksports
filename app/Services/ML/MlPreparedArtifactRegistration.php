<?php

namespace App\Services\ML;

/** Owned uploads are staged before SQL. Unreferenced staged objects need explicit audited garbage collection. */
final readonly class MlPreparedArtifactRegistration
{
    public function __construct(
        public string $trainingRunId,
        public string $artifactId,
        public string $runContextHash,
        public array $artifactAttributes,
        public array $runUpdates,
        public array $admission,
    ) {}
}
