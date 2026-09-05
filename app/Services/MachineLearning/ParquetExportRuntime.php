<?php

namespace App\Services\MachineLearning;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class ParquetExportRuntime
{
    public function assertAvailable(): void
    {
        $process = new Process([$this->pythonBinary(), '-c', 'import pyarrow']);
        $process->setTimeout(30);

        try {
            $process->mustRun();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Parquet export requires a configured Python runtime with PyArrow; use --format=jsonl for the portable intermediate.',
                previous: $exception,
            );
        }

        if (! is_file($this->writerPath())) {
            throw new RuntimeException('The configured Parquet writer script does not exist.');
        }
    }

    public function convert(string $jsonlPath, string $parquetPath): void
    {
        $this->assertAvailable();

        $process = new Process([
            $this->pythonBinary(),
            $this->writerPath(),
            '--input',
            $jsonlPath,
            '--output',
            $parquetPath,
        ]);
        $process->setTimeout(3600);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            throw new RuntimeException(
                'PyArrow failed to create the Parquet export: '.trim($exception->getProcess()->getErrorOutput()),
                previous: $exception,
            );
        }

        if (! is_file($parquetPath) || filesize($parquetPath) === 0) {
            throw new RuntimeException('PyArrow did not create a non-empty Parquet file.');
        }
    }

    private function pythonBinary(): string
    {
        return (string) config('ml.archive.python_binary', 'python3');
    }

    private function writerPath(): string
    {
        return (string) config('ml.archive.parquet_writer', base_path('scripts/export_jsonl_to_parquet.py'));
    }
}
