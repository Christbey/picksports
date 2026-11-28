<?php

namespace App\Services\NFL;

use RuntimeException;

/** Streaming byte truth: read at most the expected length plus one, never an unbounded EOF hash. */
final class NflTabularFiles
{
    public static function digest($stream, int $expectedBytes, $copy = null): string
    {
        if (! is_resource($stream) || $expectedBytes < 0 || $expectedBytes > 2147483647) {
            throw new RuntimeException('NFL bounded file length/stream is invalid.');
        }
        $hash = hash_init('sha256');
        $read = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, min(65536, $expectedBytes + 1 - $read));
            if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                throw new RuntimeException('NFL bounded stream failed before its expected end.');
            }
            $read += strlen($chunk);
            if ($read > $expectedBytes) {
                throw new RuntimeException('NFL file grew beyond its admitted byte length.');
            }
            hash_update($hash, $chunk);
            if ($copy !== null && $chunk !== '' && fwrite($copy, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('NFL private staging copy was incomplete.');
            }
        }
        if ($read !== $expectedBytes) {
            throw new RuntimeException('NFL file is shorter than its admitted byte length.');
        }

        return hash_final($hash);
    }

    public static function hash(string $path, int $expectedBytes): string
    {
        $handle = self::open($path, $expectedBytes);
        try {
            return self::digest($handle, $expectedBytes);
        } finally {
            fclose($handle);
        }
    }

    public static function copy(string $source, string $target, int $expectedBytes, string $sha256): void
    {
        $input = self::open($source, $expectedBytes);
        try {
            self::copyStream($input, $target, $expectedBytes, $sha256);
        } finally {
            fclose($input);
        }
    }

    public static function copyStream($input, string $target, int $expectedBytes, string $sha256): void
    {
        $output = fopen($target, 'xb');
        if ($output === false) {
            throw new RuntimeException('Unable to create an owned NFL immutable input copy.');
        }
        try {
            if (! chmod($target, 0600) || ! hash_equals($sha256, self::digest($input, $expectedBytes, $output)) || ! fflush($output)) {
                throw new RuntimeException('NFL immutable input copy failed its byte/hash verification.');
            }
        } finally {
            $closed = fclose($output);
        }
        if (! $closed || ! chmod($target, 0400)) {
            throw new RuntimeException('NFL immutable input copy could not be sealed.');
        }
    }

    private static function open(string $path, int $expectedBytes)
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('NFL bounded source must be a regular file.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open NFL bounded source.');
        }
        $stat = fstat($handle);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== $expectedBytes) {
            fclose($handle);
            throw new RuntimeException('NFL bounded source size/type changed before reading.');
        }

        return $handle;
    }
}
