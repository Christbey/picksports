<?php

namespace App\Services\Relationships;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/** Read-only inventory for an explicitly selected schema, never a migration or repair. */
final class RelationshipInventory
{
    /** @return array<string, array<string, mixed>> */
    public function inspect(): array
    {
        $inventory = [];

        foreach ($this->modelClasses() as $class) {
            $model = new $class;
            $schema = Schema::connection($model->getConnectionName());
            $table = $model->getTable();
            $exists = $schema->hasTable($table);
            $relations = [];
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->getNumberOfRequiredParameters() > 0
                    || ! str_starts_with($method->getDeclaringClass()->getName(), 'App\\')) {
                    continue;
                }
                $declaresRelation = $type instanceof ReflectionNamedType && is_a($type->getName(), Relation::class, true);
                if (! $declaresRelation) {
                    if ($type !== null) {
                        continue;
                    }
                    $lines = file($method->getFileName());
                    $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                    if (preg_match('/return\s+\$this->(?:hasOne|hasMany|belongsTo|belongsToMany|morphTo|morphMany|morphOne|morphToMany|hasOneThrough|hasManyThrough)\s*\(/', $body) !== 1) {
                        continue;
                    }
                }

                try {
                    $relation = $method->invoke($model);
                } catch (\InvalidArgumentException $exception) {
                    $relations[$method->getName()] = [
                        'type' => $type instanceof ReflectionNamedType ? class_basename($type->getName()) : 'ContextualRelation',
                        'requires_context' => true,
                        'kind' => 'context_required',
                        'reason' => $exception->getMessage(),
                        'source' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $method->getFileName()).':'.$method->getStartLine(),
                    ];

                    continue;
                }
                $definition = [
                    'type' => class_basename($relation),
                    'kind' => match (true) {
                        $relation instanceof BelongsToMany => 'many_to_many',
                        $relation instanceof MorphTo => 'morph_to',
                        $relation instanceof BelongsTo => 'belongs_to',
                        $relation instanceof HasOneOrMany => 'has_one_or_many',
                        default => 'other',
                    },
                    'related_model' => $relation instanceof MorphTo ? null : $relation->getRelated()::class,
                    'related_table' => $relation instanceof MorphTo ? null : $relation->getRelated()->getTable(),
                    'related_connection' => $relation instanceof MorphTo ? null : $relation->getRelated()->getConnection()->getName(),
                    'source' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $method->getFileName()).':'.$method->getStartLine(),
                ];
                if ($relation instanceof BelongsToMany) {
                    $definition += [
                        'pivot_table' => $relation->getTable(),
                        'foreign_key' => $relation->getForeignPivotKeyName(),
                        'related_key' => $relation->getRelatedPivotKeyName(),
                        'local_key' => $relation->getParentKeyName(),
                        'owner_key' => $relation->getRelatedKeyName(),
                        'pivot_columns' => $relation->getPivotColumns(),
                    ];
                } elseif ($relation instanceof BelongsTo) {
                    $definition += [
                        'foreign_key' => $relation->getForeignKeyName(),
                        'owner_key' => $relation->getOwnerKeyName(),
                    ];
                    if (! in_array($relation->getForeignKeyName(), array_column($exists ? $schema->getColumns($table) : [], 'name'), true)
                        && ($model->hasGetMutator($relation->getForeignKeyName()) || $model->hasAttributeGetMutator($relation->getForeignKeyName()))) {
                        $definition['foreign_key_source'] = 'accessor';
                    }
                    if ($relation instanceof MorphTo) {
                        $definition['morph_type'] = $relation->getMorphType();
                    }
                } elseif ($relation instanceof HasOneOrMany) {
                    $definition += [
                        'foreign_key' => $relation->getForeignKeyName(),
                        'local_key' => $relation->getLocalKeyName(),
                    ];
                }
                $relations[$method->getName()] = $definition;
            }
            ksort($relations);
            $inventory[$class] = [
                'table' => $table,
                'connection' => $model->getConnection()->getName(),
                'table_exists' => $exists,
                'primary_key' => $model->getKeyName(),
                'key_type' => $model->getKeyType(),
                'route_key' => $model->getRouteKeyName(),
                'columns' => $exists ? array_values($schema->getColumns($table)) : [],
                'indexes' => $exists ? array_values($schema->getIndexes($table)) : [],
                'foreign_keys' => $exists ? array_values($schema->getForeignKeys($table)) : [],
                'relations' => $relations,
            ];
        }

        return $inventory;
    }

    /** @return list<class-string<Model>> */
    public function modelClasses(): array
    {
        $classes = [];
        $root = app_path('Models');
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            if (! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
