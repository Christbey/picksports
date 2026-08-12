<?php

namespace App\Services\Api\V2\Concerns;

use App\Services\Api\V2\SportContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

trait BuildsSportQueries
{
    /** @var array<string, array<int, string>> */
    private array $columnsByTable = [];

    /**
     * @return class-string<Model>
     */
    private function requireModel(SportContext $context, string $key, string $label): string
    {
        $model = $context->models[$key] ?? null;

        if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
            abort(404, "{$label} are not available for {$context->slug}.");
        }

        return $model;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $this->columnsByTable[$table] ??= Schema::getColumnListing($table);

        return in_array($column, $this->columnsByTable[$table], true);
    }

    /**
     * @param  array<int, string>  $relations
     * @param  class-string<Model>  $model
     * @return array<int, string>
     */
    private function availableRelations(string $model, array $relations): array
    {
        return array_values(array_filter(
            $relations,
            fn (string $relation): bool => method_exists($model, explode('.', $relation, 2)[0]),
        ));
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters, int $default, int $max): int
    {
        return max(1, min((int) ($filters['per_page'] ?? $default), $max));
    }

    /**
     * @return array<int, int|string>
     */
    private function seasonTypeCandidates(SportContext $context, string $requestedSeasonType): array
    {
        $requested = trim($requestedSeasonType);

        if ($requested === '') {
            return [];
        }

        $typeNames = config("{$context->slug}.season.type_names", []);
        $typesByKey = config("{$context->slug}.season.types", []);
        $candidates = [$requested];

        if (is_numeric($requested)) {
            $code = (int) $requested;
            $candidates[] = $code;
            $matchedKey = array_search($code, $typesByKey, true);

            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typeNames[$matchedKey])) {
                    $candidates[] = (string) $typeNames[$matchedKey];
                }
            }
        } else {
            if (isset($typesByKey[$requested])) {
                $resolvedCode = $typesByKey[$requested];
                $candidates[] = $resolvedCode;
                $candidates[] = (string) $resolvedCode;
            }

            $matchedKey = array_search($requested, $typeNames, true);
            if ($matchedKey !== false) {
                $candidates[] = (string) $matchedKey;
                if (isset($typesByKey[$matchedKey])) {
                    $resolvedCode = $typesByKey[$matchedKey];
                    $candidates[] = $resolvedCode;
                    $candidates[] = (string) $resolvedCode;
                }
            }
        }

        return array_values(array_unique(array_filter(
            $candidates,
            fn (mixed $value): bool => $value !== null && $value !== ''
        )));
    }
}
