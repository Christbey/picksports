<?php

namespace App\Services\Api\V2;

use App\Support\InjuryImpactScorer;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use WeakMap;

/** Prepares only explicitly loaded injuries, never expands the requested injury coverage. */
#[Scoped]
final class PreparedInjuryImpacts
{
    /** @var WeakMap<Model, array<string, mixed>> */
    private WeakMap $impacts;

    public function __construct(private readonly InjuryImpactScorer $scorer)
    {
        $this->impacts = new WeakMap;
    }

    /** @param iterable<Model> $models */
    public function prepare(iterable $models): void
    {
        $pending = collect($models)->all();
        $seen = new WeakMap;
        $groups = [];
        while ($pending !== []) {
            $model = array_pop($pending);
            if (! $model instanceof Model || isset($seen[$model])) {
                continue;
            }
            $seen[$model] = true;
            if (str_ends_with($model->getTable(), '_player_injuries')) {
                $groups[explode('_', $model->getTable())[0]][] = $model;
            }
            foreach ($model->getRelations() as $related) {
                if ($related instanceof Model) {
                    $pending[] = $related;
                } elseif ($related instanceof \Illuminate\Support\Collection) {
                    foreach ($related as $child) {
                        $pending[] = $child;
                    }
                }
            }
        }
        foreach ($groups as $sport => $injuries) {
            $injuries = new Collection($injuries);
            $injuries->loadMissing('player');
            $this->scorer->preload($sport, $injuries->pluck('player_id')->filter()->map(fn ($id): int => (int) $id)->all());
            foreach ($injuries as $injury) {
                $this->impacts[$injury] = $this->scorer->describe($sport, (int) $injury->player_id, $injury->status);
            }
        }
    }

    /** @return array<string, mixed> */
    public function get(Model $injury): array
    {
        return $this->impacts[$injury]
            ?? throw new LogicException('Prepare loaded injury impacts before serializing injury resources.');
    }
}
