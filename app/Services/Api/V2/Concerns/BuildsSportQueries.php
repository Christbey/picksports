<?php

namespace App\Services\Api\V2\Concerns;

use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportQuerySchema;
use Illuminate\Database\Eloquent\Model;

trait BuildsSportQueries
{
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
        return SportQuerySchema::hasColumn($table, $column);
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
     * Game season_type is INTEGER in these five schemas; text aliases in a numeric
     * SQL comparison can coerce to zero on MySQL. The football schemas retain
     * their VARCHAR alias contract.
     *
     * @return array<int, int|string>
     */
    private function gameSeasonTypeCandidates(SportContext $context, string $requested): array
    {
        $candidates = $this->seasonTypeCandidates($context, $requested);
        if (! in_array($context->slug, ['nba', 'cbb', 'wcbb', 'wnba', 'mlb'], true)) {
            return $candidates;
        }
        $types = (array) config("{$context->slug}.season.types", []);
        $codes = array_values($types);
        $names = array_values(array_intersect_key((array) config("{$context->slug}.season.type_names", []), $types));
        if (! in_array(trim($requested), array_map('strval', [...array_keys($types), ...$codes, ...$names]), true)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter($candidates,
            fn (mixed $candidate): bool => (is_int($candidate) || (is_string($candidate) && preg_match('/^[0-9]+$/D', $candidate) === 1))
                && in_array((string) $candidate, array_map('strval', $codes), true)
        ))));
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
