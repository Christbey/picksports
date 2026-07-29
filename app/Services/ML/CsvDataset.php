<?php

namespace App\Services\ML;

use Illuminate\Support\Facades\File;

class CsvDataset
{
    /**
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function write(string $path, iterable $rows): int
    {
        $rows = collect($rows)->values();
        if ($rows->isEmpty()) {
            return 0;
        }

        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to write CSV file: {$path}");
        }

        $headers = $rows
            ->flatMap(fn (array $row): array => array_keys($row))
            ->unique()
            ->values()
            ->all();

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header): string => $this->stringValue($row[$header] ?? null),
                $headers,
            ));
        }

        fclose($handle);

        return $rows->count();
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
