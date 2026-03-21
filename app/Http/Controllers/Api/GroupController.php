<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'type' => 'nullable|string|max:100',
            'sport' => 'nullable|string|max:50',
            'season' => 'nullable|integer|min:2000|max:2100',
        ]);

        $query = $this->visibleGroups($request);

        foreach (['type', 'sport', 'season'] as $field) {
            if (isset($validated[$field])) {
                $query->where($field, $validated[$field]);
            }
        }

        return GroupResource::collection($query->latest('updated_at')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string|max:100',
            'sport' => 'nullable|string|max:50',
            'season' => 'nullable|integer|min:2000|max:2100',
        ]);

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

        $group->fill($validated)->save();

        return new GroupResource($group->fresh());
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
