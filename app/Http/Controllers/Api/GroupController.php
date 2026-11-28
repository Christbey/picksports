<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GroupIndexRequest;
use App\Http\Requests\Api\GroupStoreRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GroupController extends Controller
{
    public function index(GroupIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();

        $query = $this->visibleGroups($request);

        foreach (['type', 'sport', 'season'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        return GroupResource::collection($query->latest('updated_at')->get());
    }

    public function store(GroupStoreRequest $request)
    {
        $validated = $request->validated();

        $group = DB::transaction(function () use ($request, $validated): Group {
            $group = Group::query()->create([
                'owner_id' => $request->user()->id,
                'name' => $validated['name'],
                'type' => $validated['type'] ?? 'bracket_pool',
                'sport' => $validated['sport'] ?? null,
                'season' => $validated['season'] ?? null,
            ]);

            $group->users()->attach($request->user()->id, [
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            return $group;
        });

        return (new GroupResource($group->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $publicId): GroupResource
    {
        $group = $this->editableGroups($request)
            ->where('public_id', $publicId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $connection = $group->getConnection();
        $pdo = $connection->getPdo();
        $id = $group->getRawOriginal('id');
        $publicIdentity = $group->getRawOriginal('public_id');
        $ownerId = $request->user()->getKey();
        $literal = (new Group)->setConnection($connection->getName());
        if ($literal->getConnection() !== $connection) {
            throw new RuntimeException('Group update connection changed.');
        }

        $updated = $connection->transaction(function () use ($connection, $pdo, $literal, $id, $ownerId, $publicIdentity, $validated): Group {
            $current = $literal->newQuery()->useWritePdo()->whereKey($id)
                ->where('public_id', $publicIdentity)->where('owner_id', $ownerId)
                ->lockForUpdate()->firstOrFail();
            if ($current->getConnection() !== $connection || $connection->getPdo() !== $pdo) {
                throw new RuntimeException('Group update connection changed.');
            }
            $current->fill($validated);
            if ($current->save() !== true || ! $current->exists
                || $current->getConnection() !== $connection || $connection->getPdo() !== $pdo
                || $current->getTable() !== $literal->getTable()
                || (string) $current->getKey() !== (string) $id) {
                throw new RuntimeException('Group update was not persisted.');
            }
            $stored = $connection->table($literal->getTable())->useWritePdo()->where('id', $id)
                ->select(['id', 'owner_id', 'public_id', 'name'])->first();
            if ($stored === null || (string) $stored->owner_id !== (string) $ownerId
                || $stored->public_id !== $publicIdentity
                || (array_key_exists('name', $validated) && $stored->name !== $validated['name'])) {
                throw new RuntimeException('Group update changed during persistence.');
            }

            return $current->fresh();
        });

        return new GroupResource($updated);
    }

    private function visibleGroups(Request $request): Builder
    {
        return Group::query()
            ->where(function (Builder $query) use ($request): void {
                $query->where('owner_id', $request->user()->id)
                    ->orWhereHas('users', fn (Builder $membership) => $membership->where('users.id', $request->user()->id));
            });
    }

    private function editableGroups(Request $request): Builder
    {
        return Group::query()->where('owner_id', $request->user()->id);
    }
}
