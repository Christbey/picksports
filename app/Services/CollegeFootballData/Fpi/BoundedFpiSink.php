<?php

namespace App\Services\CollegeFootballData\Fpi;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Bounds bytes accepted by the HTTP sink, before JSON materialization. */
final class BoundedFpiSink implements StreamInterface
{
    use StreamDecoratorTrait;

    public const MAXIMUM_BYTES = 2097152;

    private StreamInterface $stream;

    private int $written = 0;

    public function write(string $string): int
    {
        if ($this->written + strlen($string) > self::MAXIMUM_BYTES + 1) {
            throw new RuntimeException('FPI HTTP response byte limit exceeded during receipt.');
        }
        $bytes = $this->stream->write($string);
        $this->written += $bytes;

        return $bytes;
    }
}
