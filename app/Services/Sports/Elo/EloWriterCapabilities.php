<?php

namespace App\Services\Sports\Elo;

/** No production adapter is coordinated in slice one. Configuration cannot override this boundary. */
class EloWriterCapabilities
{
    public function supports(string $sport, int $protocol): bool
    {
        return false;
    }
}
