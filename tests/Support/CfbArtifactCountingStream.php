<?php

namespace Tests\Support;

/** Synthetic endless source; PHP may read ahead one internal 8 KiB buffer. */
class CfbArtifactCountingStream
{
    public mixed $context;

    public static int $bytesRead = 0;

    public static bool $closed = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$bytesRead = 0;
        self::$closed = false;

        return true;
    }

    public function stream_read(int $count): string
    {
        self::$bytesRead += $count;

        return str_repeat('x', $count);
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}
