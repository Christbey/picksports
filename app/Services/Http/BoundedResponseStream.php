<?php

namespace App\Services\Http;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(private readonly int $maximumBytes)
    {
        $resource = fopen('php://temp/maxmemory:1048576', 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Unable to allocate bounded response stream.');
        }
        $this->stream = Utils::streamFor($resource);
    }

    public function write(string $string): int
    {
        if ($this->tell() + strlen($string) > $this->maximumBytes) {
            throw new RuntimeException('Approved response exceeded its byte limit.');
        }

        return $this->stream->write($string);
    }
}
