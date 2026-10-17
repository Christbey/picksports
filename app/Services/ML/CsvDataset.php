<?php

namespace App\Services\ML;

use Illuminate\Support\Facades\File;

class CsvDataset
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, callable(array): mixed>  $derivedColumns
     */
    public function write(string $path, iterable $rows, array $derivedColumns = []): int
    {
        $spool = tmpfile();
        if ($spool === false) {
            throw new \RuntimeException('Unable to create temporary CSV spool.');
        }
        $temporaryPath = null;
        $handle = null;
        $headers = [];
        $dataHeaders = [];
        $seenHeaders = [];
        $seenDataHeaders = [];
        $count = 0;

        try {
            foreach ($rows as $row) {
                $keys = array_keys($row);
                foreach ($derivedColumns as $name => $callback) {
                    if (array_key_exists($name, $row)) {
                        throw new \RuntimeException('Derived CSV column already exists: '.$name);
                    }
                }
                $this->appendHeaders($dataHeaders, $seenDataHeaders, $keys);
                $this->appendHeaders($headers, $seenHeaders, [...$keys, ...array_keys($derivedColumns)]);
                $encoded = [];
                foreach ($row as $name => $value) {
                    $encoded[base64_encode((string) $name)] = base64_encode($this->stringValue($value));
                }
                $line = json_encode($encoded, JSON_THROW_ON_ERROR)."\n";
                if (fwrite($spool, $line) !== strlen($line)) {
                    throw new \RuntimeException('Unable to write temporary CSV spool.');
                }
                $count++;
            }
            if ($count === 0) {
                return 0;
            }

            $constants = [];
            foreach ($derivedColumns as $name => $callback) {
                $constants[$name] = $this->stringValue($callback($dataHeaders));
            }
            File::ensureDirectoryExists(dirname($path));
            $temporaryPath = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(16)).'.tmp';
            $handle = fopen($temporaryPath, 'xb');
            if ($handle === false) {
                throw new \RuntimeException("Unable to write temporary CSV file for: {$path}");
            }
            if (fputcsv($handle, $headers) === false || ! rewind($spool)) {
                throw new \RuntimeException('Unable to start CSV publication.');
            }
            $writtenRows = 0;
            while (($line = fgets($spool)) !== false) {
                $encoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $values = [];
                foreach ($headers as $header) {
                    if (array_key_exists($header, $constants)) {
                        $values[] = $constants[$header];

                        continue;
                    }
                    $value = base64_decode($encoded[base64_encode((string) $header)] ?? '', true);
                    if ($value === false) {
                        throw new \RuntimeException('Invalid CSV spool value.');
                    }
                    $values[] = $value;
                }
                if (fputcsv($handle, $values) === false) {
                    throw new \RuntimeException("Unable to write CSV row for: {$path}");
                }
                $writtenRows++;
            }
            if (! feof($spool) || $writtenRows !== $count || ! fflush($handle)) {
                throw new \RuntimeException('CSV spool read or publication flush failed.');
            }
            $closed = fclose($handle);
            $handle = null;
            if (! $closed) {
                throw new \RuntimeException("Unable to close CSV file for: {$path}");
            }
            clearstatcache(true, $temporaryPath);
            $size = filesize($temporaryPath);
            if ($size === false || $size === 0) {
                throw new \RuntimeException("CSV file is empty for: {$path}");
            }
            if (! @rename($temporaryPath, $path)) {
                throw new \RuntimeException("Unable to publish CSV file atomically: {$path}");
            }
            $temporaryPath = null;

            return $count;
        } finally {
            fclose($spool);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($temporaryPath !== null && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array<int, int|string>  $headers
     * @param  array<string, true>  $seen
     * @param  array<int, int|string>  $names
     */
    private function appendHeaders(array &$headers, array &$seen, array $names): void
    {
        $new = [];
        foreach ($names as $name) {
            $key = 'header:'.$name;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $new[] = $name;
            }
        }
        if ($new !== []) {
            $headers = array_values(array_unique([...$headers, ...$new], SORT_REGULAR));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    public function read(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (! is_array($values)) {
                continue;
            }

            $rows[] = array_combine($headers, array_pad($values, count($headers), '')) ?: [];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{train:list<array<string,string>>,validation:list<array<string,string>>,test:list<array<string,string>>}
     */
    public function chronologicalSplit(
        array $rows,
        float $trainPercent,
        float $validationPercent,
    ): array {
        usort($rows, fn (array $left, array $right): int => [
            $left['game_start_at'] ?? $left['game_date'] ?? '',
            $left['game_id'] ?? '',
        ] <=> [
            $right['game_start_at'] ?? $right['game_date'] ?? '',
            $right['game_id'] ?? '',
        ]);

        $count = count($rows);
        $trainCount = (int) floor($count * ($trainPercent / 100));
        $validationCount = (int) floor($count * ($validationPercent / 100));

        if ($count >= 3 && $validationPercent > 0 && $validationCount === 0) {
            $validationCount = 1;
            $trainCount = max(1, $trainCount - 1);
        }

        if ($count >= 3 && $count - $trainCount - $validationCount === 0) {
            $trainCount = max(1, $trainCount - 1);
        }

        return [
            'train' => array_slice($rows, 0, $trainCount),
            'validation' => array_slice($rows, $trainCount, $validationCount),
            'test' => array_slice($rows, $trainCount + $validationCount),
        ];
    }

    /**
     * @param  list<string>  $paths
     */
    public function hashFiles(array $paths): string
    {
        $hash = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($hash, basename($path)."\0");
            hash_update_file($hash, $path);
        }

        return hash_final($hash);
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $value === null ? '' : (string) $value;
    }
}
