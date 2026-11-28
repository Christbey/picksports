<?php

namespace App\Support;

use Closure;
use RecursiveCallbackFilterIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Finder\Iterator\ExcludeDirectoryFilterIterator;
use Symfony\Component\Finder\Iterator\RecursiveDirectoryIterator;
use Symfony\Component\Finder\SplFileInfo;

/** Bounded observed source bytes, not a filesystem snapshot or producer attestation. */
final class BoundedTrainingSourceInventory
{
    // The remaining default Finder VCS names begin with a dot and are pruned separately.
    private const LEGACY_VCS_DIRECTORIES = ['CVS', '_svn', '_darcs'];

    public const MAX_ENTRIES = 512;

    public const MAX_FILES = 128;

    public const MAX_DEPTH = 16;

    public const MAX_FILE_BYTES = 2097152;

    public const MAX_TOTAL_BYTES = 33554432;

    private function __construct(
        private readonly string $root,
        private readonly ?Closure $accept,
        private array $files,
        private readonly array $directories,
    ) {}

    /** The selector must be a pure relative-path predicate. Hidden/VCS policy matches File::allFiles. */
    public static function capture(string $directory, ?Closure $accept = null): self
    {
        if ($directory === '' || in_array('..', explode(DIRECTORY_SEPARATOR, $directory), true)) {
            throw new RuntimeException('Training source root must be an explicit directory without parent traversal.');
        }
        $directory = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($directory === '') {
            $directory = DIRECTORY_SEPARATOR;
        }
        while (str_ends_with($directory, DIRECTORY_SEPARATOR.'.')) {
            $directory = rtrim(substr($directory, 0, -2), DIRECTORY_SEPARATOR);
            if ($directory === '') {
                $directory = DIRECTORY_SEPARATOR;
            }
        }
        $rootIdentity = self::identity($directory, 0040000);
        if (($root = realpath($directory)) === false || self::identity($root, 0040000) !== $rootIdentity) {
            throw new RuntimeException('Training source root changed during canonical resolution.');
        }
        [$files, $directories] = self::scan($root, $accept);
        $set = new self($root, $accept, $files, $directories);
        foreach ($files as $relative => $entry) {
            $set->files[$relative]['sha256'] = hash('sha256', $set->readEntry($relative));
        }
        $set->assertTreeUnchanged();

        return $set;
    }

    /** @return array<string, array{bytes:int, sha256:string}> */
    public function descriptors(): array
    {
        $result = [];
        foreach ($this->orderedPaths() as $relative) {
            $result[$relative] = ['bytes' => $this->files[$relative]['bytes'], 'sha256' => $this->files[$relative]['sha256']];
        }

        return $result;
    }

    /** Preserve Collection::sortBy's SORT_REGULAR ordering and PHP's existing JSON encoding. */
    public function jsonMapHash(): string
    {
        $hashes = [];
        foreach ($this->orderedPaths() as $relative) {
            $hashes[$relative] = $this->files[$relative]['sha256'];
        }

        return hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR));
    }

    /** At most one admitted source body is retained; later reads verify its captured digest. */
    public function read(string $relative): string
    {
        $body = $this->readEntry($relative);
        if (! hash_equals($this->files[$relative]['sha256'], hash('sha256', $body))) {
            throw new RuntimeException('Training source contents changed after capture.');
        }

        return $body;
    }

    /** Match the Python source identity: ordered relative name, NUL, then exact raw bytes. */
    public function concatenatedHash(array $orderedPaths): string
    {
        if (count($orderedPaths) > self::MAX_FILES) {
            throw new RuntimeException('Training source path order exceeds its admitted inventory.');
        }
        foreach ($orderedPaths as $relative) {
            if (! is_string($relative) || ! array_key_exists($relative, $this->files)) {
                throw new RuntimeException('Training source path was not admitted.');
            }
        }
        if (count(array_unique($orderedPaths, SORT_STRING)) !== count($orderedPaths)) {
            throw new RuntimeException('Training source path order contains duplicate entries.');
        }
        $hash = hash_init('sha256');
        foreach ($orderedPaths as $relative) {
            $body = $this->read($relative);
            hash_update($hash, $relative."\0");
            hash_update($hash, $body);
        }
        $this->assertTreeUnchanged();

        return hash_final($hash);
    }

    /** Raw schema hashing without traversing unrelated files in its parent directory. */
    public static function hashFile(string $path): string
    {
        $identity = self::identity($path, 0100000);
        if ($identity['size'] < 0 || $identity['size'] > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Training schema exceeds its source byte bound.');
        }

        return hash('sha256', self::readExact($path, $identity));
    }

    private function readEntry(string $relative): string
    {
        $this->assertDirectoriesUnchanged();
        $entry = $this->files[$relative] ?? null;
        if ($entry === null) {
            throw new RuntimeException('Training source path was not admitted.');
        }

        return self::readExact($this->root.DIRECTORY_SEPARATOR.$relative, $entry['identity']);
    }

    private static function readExact(string $path, array $expected): string
    {
        if (self::identity($path, 0100000) !== $expected) {
            throw new RuntimeException('Training source identity changed before opening.');
        }
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException('Unable to open admitted training source.');
        }
        try {
            if (self::statIdentity(fstat($input)) !== $expected) {
                throw new RuntimeException('Training source descriptor changed before reading.');
            }
            $body = '';
            $read = 0;
            while (! feof($input)) {
                $chunk = fread($input, min(65536, $expected['size'] + 1 - $read));
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new RuntimeException('Training source stream failed before its admitted end.');
                }
                $read += strlen($chunk);
                if ($read > $expected['size']) {
                    throw new RuntimeException('Training source grew beyond its admitted size.');
                }
                $body .= $chunk;
            }
            if ($read !== $expected['size'] || self::statIdentity(fstat($input)) !== $expected
                || self::identity($path, 0100000) !== $expected) {
                throw new RuntimeException('Training source size or identity changed during reading.');
            }

            return $body;
        } finally {
            fclose($input);
        }
    }

    private function orderedPaths(): array
    {
        $order = array_combine(array_keys($this->files), array_keys($this->files));
        asort($order, SORT_REGULAR);

        return array_map(static fn (string|int $path): string => (string) $path, array_values($order));
    }

    private function assertTreeUnchanged(): void
    {
        [$files, $directories] = self::scan($this->root, $this->accept);
        $expected = $this->files;
        foreach ($expected as &$entry) {
            unset($entry['sha256']);
        }
        unset($entry);
        if ($files !== $expected || $directories !== $this->directories) {
            throw new RuntimeException('Training source membership or identity changed during capture.');
        }
    }

    private function assertDirectoriesUnchanged(): void
    {
        foreach ($this->directories as $relative => $identity) {
            $path = $relative === '' ? $this->root : $this->root.DIRECTORY_SEPARATOR.$relative;
            if (self::identity($path, 0040000) !== $identity) {
                throw new RuntimeException('Training source directory changed after capture.');
            }
        }
    }

    /** Count encountered entries before exclusion; prune ignored subtrees without descending into them. */
    private static function scan(string $root, ?Closure $accept): array
    {
        $directories = ['' => self::identity($root, 0040000)];
        $files = [];
        $entries = $bytes = 0;
        $raw = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
        $bounded = new RecursiveCallbackFilterIterator($raw, static function (SplFileInfo $file) use (&$entries): bool {
            if (++$entries > self::MAX_ENTRIES || substr_count($file->getRelativePathname(), DIRECTORY_SEPARATOR) > self::MAX_DEPTH) {
                throw new RuntimeException('Training source inventory exceeds its entry or depth bound.');
            }

            return ! str_starts_with($file->getFilename(), '.');
        });
        $policy = new ExcludeDirectoryFilterIterator($bounded, self::LEGACY_VCS_DIRECTORIES);
        foreach (new RecursiveIteratorIterator($policy, RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $relative = $file->getRelativePathname();
            $identity = self::identity($file->getPathname());
            $kind = $identity['mode'] & 0170000;
            if ($kind === 0040000) {
                $directories[$relative] = $identity;

                continue;
            }
            if ($kind !== 0100000) {
                throw new RuntimeException('Training source entries must be regular files or directories.');
            }
            if ($accept !== null && ! $accept($relative)) {
                continue;
            }
            $bytes += $identity['size'];
            if ($identity['size'] < 0 || $identity['size'] > self::MAX_FILE_BYTES || $bytes > self::MAX_TOTAL_BYTES || count($files) >= self::MAX_FILES) {
                throw new RuntimeException('Training source inventory exceeds its file or byte bound.');
            }
            $files[$relative] = ['bytes' => $identity['size'], 'identity' => $identity];
        }
        ksort($files, SORT_STRING);
        ksort($directories, SORT_STRING);

        return [$files, $directories];
    }

    private static function identity(string $path, ?int $kind = null): array
    {
        clearstatcache(true, $path);
        $identity = self::statIdentity(@lstat($path));
        if (($identity['mode'] & 0170000) === 0120000 || ($kind !== null && ($identity['mode'] & 0170000) !== $kind)) {
            throw new RuntimeException('Training source path has an unsafe type.');
        }

        return $identity;
    }

    private static function statIdentity(array|false $stat): array
    {
        if ($stat === false) {
            throw new RuntimeException('Unable to inspect training source identity.');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'size', 'mtime', 'ctime']));
    }
}
