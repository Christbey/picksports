<?php

namespace App\Services\NFL;

use Illuminate\Contracts\Console\Kernel;
use Throwable;

class NflPregamePipelineRunner
{
    public function __construct(private readonly Kernel $kernel) {}

    /**
     * @param  list<array{name: string, command: string, arguments: array<string, mixed>, continue_on_failure?: bool}>  $steps
     * @param  callable(string, string, int): void  $afterStep
     * @return array{successful: bool, failed_step: string|null, exit_code: int}
     */
    public function run(array $steps, callable $afterStep): array
    {
        $failure = null;
        foreach ($steps as $step) {
            try {
                $exitCode = $this->kernel->call($step['command'], $step['arguments']);
                $output = $this->kernel->output();
            } catch (Throwable $exception) {
                report($exception);
                $exitCode = 1;
                $output = $exception->getMessage();
            }
            $afterStep($step['name'], $output, $exitCode);

            if ($exitCode !== 0) {
                $failure ??= [
                    'successful' => false,
                    'failed_step' => $step['name'],
                    'exit_code' => $exitCode,
                ];
                if (! ($step['continue_on_failure'] ?? false)) {
                    return $failure;
                }
            }
        }

        return $failure ?? [
            'successful' => true,
            'failed_step' => null,
            'exit_code' => 0,
        ];
    }
}
