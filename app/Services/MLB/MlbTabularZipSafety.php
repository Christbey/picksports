<?php

namespace App\Services\MLB;

use RuntimeException;

/** Admit a bounded classic ZIP central directory before libzip allocates its entry inventory. */
final class MlbTabularZipSafety
{
    public static function inspect(string $path, int $maximumEntries, int $maximumFileBytes, int $maximumTotalBytes): void
    {
        if ($maximumEntries < 1 || $maximumEntries > 256 || $maximumFileBytes < 1 || $maximumFileBytes > 1073741824
            || $maximumTotalBytes < 1 || $maximumTotalBytes > 1073741824 || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('MLB ZIP limits/file are outside the bounded supported contract.');
        }
        $input = fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to open MLB ZIP metadata.');
        }
        try {
            $stat = fstat($input);
            $size = $stat['size'] ?? 0;
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $size < 22
                || $size > $maximumTotalBytes + 2097152 + $maximumEntries * 4096 + 65557) {
                throw new RuntimeException('MLB ZIP archive byte/type budget exceeded before libzip.');
            }
            $tailBytes = min(65557, $size);
            if (fseek($input, -$tailBytes, SEEK_END) !== 0) {
                throw new RuntimeException('Unable to seek MLB ZIP footer.');
            }
            $tail = self::read($input, $tailBytes);
            $position = strrpos($tail, "PK\x05\x06");
            $footer = null;
            while ($position !== false) {
                if (strlen($tail) - $position >= 22) {
                    $candidate = unpack('vdisk/vdirectory_disk/ventries_disk/ventries/Vdirectory_bytes/Vdirectory_offset/vcomment_bytes', substr($tail, $position + 4, 18));
                    if ($position + 22 + $candidate['comment_bytes'] === strlen($tail)) {
                        $footer = $candidate;
                        break;
                    }
                }
                $position = strrpos(substr($tail, 0, $position), "PK\x05\x06");
            }
            if ($footer === null || $footer['disk'] !== 0 || $footer['directory_disk'] !== 0
                || $footer['entries'] < 2 || $footer['entries'] !== $footer['entries_disk'] || $footer['entries'] > $maximumEntries + 1
                || $footer['directory_bytes'] > ($maximumEntries + 1) * 4096 || $footer['directory_bytes'] < 46 * $footer['entries']
                || $size - $tailBytes + $position !== $footer['directory_offset'] + $footer['directory_bytes']) {
                throw new RuntimeException('MLB ZIP directory count/size/offset is invalid; ZIP64 and multidisk layouts are unsupported.');
            }
            if (fseek($input, $footer['directory_offset']) !== 0) {
                throw new RuntimeException('Unable to seek MLB ZIP directory.');
            }
            $directory = self::read($input, $footer['directory_bytes']);
            $cursor = $total = 0;
            $names = [];
            for ($i = 0; $i < $footer['entries']; $i++) {
                if (substr($directory, $cursor, 4) !== "PK\x01\x02" || strlen($directory) - $cursor < 46) {
                    throw new RuntimeException('MLB ZIP central directory is incomplete.');
                }
                $header = substr($directory, $cursor, 46);
                $lengths = unpack('vname/vextra/vcomment', substr($header, 28, 6));
                $entryBytes = 46 + $lengths['name'] + $lengths['extra'] + $lengths['comment'];
                $uncompressed = unpack('Vbytes', substr($header, 24, 4))['bytes'];
                $attributes = unpack('Vattributes', substr($header, 38, 4))['attributes'];
                $localOffset = unpack('Voffset', substr($header, 42, 4))['offset'];
                $type = ($attributes >> 16) & 0170000;
                if ($lengths['name'] < 1 || $lengths['name'] > 256 || $lengths['extra'] > 2048 || $lengths['comment'] > 1024
                    || $cursor + $entryBytes > strlen($directory) || $uncompressed > $maximumFileBytes
                    || ! in_array($type, [0, 0100000], true) || $localOffset >= $footer['directory_offset']) {
                    throw new RuntimeException('MLB ZIP entry length/type/size/link budget exceeded.');
                }
                $name = self::entryName(substr($directory, $cursor + 46, $lengths['name']));
                if (isset($names[$name])) {
                    throw new RuntimeException('MLB ZIP contains duplicate entry names.');
                }
                $names[$name] = true;
                $total += $name === 'bundle.json' ? 0 : $uncompressed;
                if (($name === 'bundle.json' && $uncompressed > 2097152) || $total > $maximumTotalBytes) {
                    throw new RuntimeException('MLB ZIP declared uncompressed total exceeds its budget.');
                }
                $cursor += $entryBytes;
            }
            if ($cursor !== strlen($directory)) {
                throw new RuntimeException('MLB ZIP directory has unsupported trailing records.');
            }
        } finally {
            fclose($input);
        }
    }

    public static function entryName(mixed $entry): string
    {
        if (! is_string($entry) || $entry === '' || strlen($entry) > 256 || preg_match('/[\x00-\x1f\x7f]/', $entry)
            || str_contains($entry, '\\') || str_starts_with($entry, '/') || str_contains($entry, '//') || str_ends_with($entry, '/')
            || preg_match('/\A[A-Za-z]:/', $entry) || count(explode('/', $entry)) > 9 || preg_match('#(^|/)\.\.?(/|$)#', $entry)) {
            throw new RuntimeException('The MLB tabular bundle contains an unsafe entry name.');
        }

        return $entry;
    }

    private static function read($handle, int $bytes): string
    {
        $raw = stream_get_contents($handle, $bytes);
        if ($raw === false || strlen($raw) !== $bytes) {
            throw new RuntimeException('MLB ZIP metadata changed or was truncated during its bounded read.');
        }

        return $raw;
    }
}
