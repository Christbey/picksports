#!/usr/bin/env php
<?php

declare(strict_types=1);

$bytesPerSecond = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if (! is_int($bytesPerSecond)) {
    fwrite(STDERR, "Usage: php scripts/rate-limit-stream.php BYTES_PER_SECOND\n");
    exit(2);
}

$startedAt = hrtime(true);
$bytesWritten = 0;

while (! feof(STDIN)) {
    $chunk = fread(STDIN, 65536);

    if ($chunk === false) {
        fwrite(STDERR, "Unable to read the input stream.\n");
        exit(1);
    }

    if ($chunk === '') {
        continue;
    }

    $offset = 0;
    $length = strlen($chunk);

    while ($offset < $length) {
        $written = fwrite(STDOUT, substr($chunk, $offset));

        if ($written === false) {
            exit(1);
        }

        $offset += $written;
        $bytesWritten += $written;
    }

    $expectedNanoseconds = (int) (($bytesWritten / $bytesPerSecond) * 1_000_000_000);
    $elapsedNanoseconds = hrtime(true) - $startedAt;

    if ($expectedNanoseconds > $elapsedNanoseconds) {
        usleep((int) (($expectedNanoseconds - $elapsedNanoseconds) / 1000));
    }
}
