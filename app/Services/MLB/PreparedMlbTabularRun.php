<?php

namespace App\Services\MLB;

use App\Services\ML\MlPreparedArtifactRegistration;

final readonly class PreparedMlbTabularRun
{
    public function __construct(public array $runAttributes, public MlPreparedArtifactRegistration $registration, public array $manifest, public array $evaluation) {}
}
