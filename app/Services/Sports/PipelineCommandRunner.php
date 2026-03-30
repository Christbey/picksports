<?php

namespace App\Services\Sports;

use Illuminate\Support\Facades\Artisan;

class PipelineCommandRunner
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $command, array $arguments = []): int
    {
        return Artisan::call($command, $arguments);
    }
}
