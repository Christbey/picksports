<?php

namespace App\Http\Controllers\Api\CBB;

use App\Http\Controllers\Controller;
use App\Http\Resources\CbbBracketResource;
use App\Models\CbbBracket;
use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BracketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'season' => 'nullable|integer|min:2000|max:2100',
        ]);

        $query = $this->ownedBrackets($request);

        if (isset($validated['season'])) {
            $query->where('season', $validated['season']);
        }

        return CbbBracketResource::collection(
            $query->with('group')->latest('updated_at')->get()
        );
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season' => 'required|integer|min:2000|max:2100',
            'group_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $limit = $validated['limit'] ?? 25;
        $groupId = $this->resolveOwnedGroupId($request, $validated['group_id'] ?? null, (int) $validated['season']);

        $query = CbbBracket::query()
            ->with('user:id,name')
            ->where('season', $validated['season'])
            ->orderByDesc('points_earned')
            ->orderByDesc('correct_picks')
            ->orderBy('submitted_at')
            ->orderBy('updated_at');

        if ($groupId !== null) {
            $query->where('group_id', $groupId);
        }

        $rows = $query
            ->limit($limit)
            ->get()
            ->values()
            ->map(fn (CbbBracket $bracket, int $index) => [
                'rank' => $index + 1,
                'bracket_id' => $bracket->id,
                'bracket_public_id' => $bracket->public_id,
                'bracket_name' => $bracket->name ?: 'Untitled bracket',
                'user_id' => $bracket->user_id,
                'user_name' => $bracket->user?->name,
                'points_earned' => (int) ($bracket->points_earned ?? 0),
                'max_points_remaining' => (int) ($bracket->max_points_remaining ?? 0),
                'correct_picks' => (int) ($bracket->correct_picks ?? 0),
                'incorrect_picks' => (int) ($bracket->incorrect_picks ?? 0),
                'submitted_at' => $bracket->submitted_at?->toIso8601String(),
                'updated_at' => $bracket->updated_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function showCurrent(Request $request): JsonResponse|CbbBracketResource
    {
        $validated = $request->validate([
            'season' => 'required|integer|min:2000|max:2100',
        ]);

        $bracket = $this->ownedBrackets($request)
            ->where('season', $validated['season'])
            ->latest('updated_at')
            ->first();

        if (! $bracket) {
            return response()->json(['data' => null]);
        }

        return new CbbBracketResource($bracket->load('group'));
    }

    public function show(Request $request, string $publicId): CbbBracketResource
    {
        return new CbbBracketResource($this->findOwnedBracket($request, $publicId));
    }

    public function store(Request $request): JsonResponse|CbbBracketResource
    {
        $validated = $request->validate([
            'season' => 'required|integer|min:2000|max:2100',
            'name' => 'nullable|string|max:255',
            'group_id' => 'nullable|integer',
            'picks' => 'nullable|array',
            'picks.*' => 'string|max:255',
        ]);

        $this->ensureSeasonUnlocked((int) $validated['season']);
        $groupId = $this->resolveOwnedGroupId($request, $validated['group_id'] ?? null, (int) $validated['season']);

        $bracket = CbbBracket::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'group_id' => $groupId,
            'season' => $validated['season'],
            'name' => $validated['name'] ?? null,
            'picks' => $validated['picks'] ?? [],
            'submitted_at' => now(),
        ]);

        return (new CbbBracketResource($bracket->fresh()->load('group')))
            ->response()
            ->setStatusCode(201);
    }

    public function upsertCurrent(Request $request): JsonResponse|CbbBracketResource
    {
        $validated = $request->validate([
            'season' => 'required|integer|min:2000|max:2100',
            'name' => 'nullable|string|max:255',
            'group_id' => 'nullable|integer',
            'picks' => 'required|array',
            'picks.*' => 'string|max:255',
        ]);

        $this->ensureSeasonUnlocked((int) $validated['season']);
        $groupId = $this->resolveOwnedGroupId($request, $validated['group_id'] ?? null, (int) $validated['season']);

        $bracket = $this->ownedBrackets($request)
            ->where('season', $validated['season'])
            ->latest('updated_at')
            ->first();

        if (! $bracket) {
            $bracket = CbbBracket::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'group_id' => $groupId,
                'season' => $validated['season'],
                'name' => $validated['name'] ?? null,
                'picks' => $validated['picks'],
                'submitted_at' => now(),
            ]);

            return (new CbbBracketResource($bracket->fresh()->load('group')))
                ->response()
                ->setStatusCode(201);
        }

        $bracket->forceFill([
            'name' => $validated['name'] ?? $bracket->name,
            'group_id' => $groupId ?? $bracket->group_id,
            'picks' => $validated['picks'],
            'submitted_at' => $bracket->submitted_at ?? now(),
        ])->save();

        return new CbbBracketResource($bracket->fresh()->load('group'));
    }

    public function update(Request $request, string $publicId): CbbBracketResource
    {
        $bracket = $this->findOwnedBracket($request, $publicId);

        $validated = $request->validate([
            'name' => 'sometimes|nullable|string|max:255',
            'group_id' => 'sometimes|nullable|integer',
            'picks' => 'sometimes|array',
            'picks.*' => 'string|max:255',
        ]);

        $this->ensureSeasonUnlocked((int) $bracket->season);

        $updates = [];

        if (array_key_exists('name', $validated)) {
            $updates['name'] = $validated['name'];
        }

        if (array_key_exists('group_id', $validated)) {
            $updates['group_id'] = $this->resolveOwnedGroupId($request, $validated['group_id'], (int) $bracket->season);
        }

        if (array_key_exists('picks', $validated)) {
            $updates['picks'] = $validated['picks'];
            $updates['submitted_at'] = $bracket->submitted_at ?? now();
        }

        if ($updates !== []) {
            $bracket->forceFill($updates)->save();
        }

        return new CbbBracketResource($bracket->fresh()->load('group'));
    }

    public function destroy(Request $request, string $publicId): JsonResponse
    {
        $bracket = $this->findOwnedBracket($request, $publicId);

        $this->ensureSeasonUnlocked((int) $bracket->season);

        $bracket->delete();

        return response()->json(status: 204);
    }

    private function ownedBrackets(Request $request): Builder
    {
        return CbbBracket::query()->where('user_id', $request->user()->id);
    }

    private function findOwnedBracket(Request $request, string $publicId): CbbBracket
    {
        return $this->ownedBrackets($request)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function resolveOwnedGroupId(Request $request, ?int $groupId, int $season): ?int
    {
        if ($groupId === null) {
            return null;
        }

        $resolvedId = Group::query()
            ->where('id', $groupId)
            ->where('sport', 'cbb')
            ->where('type', 'bracket_pool')
            ->where('season', $season)
            ->where(function (Builder $query) use ($request): void {
                $query->where('owner_id', $request->user()->id)
                    ->orWhereHas('users', fn (Builder $membership) => $membership->where('users.id', $request->user()->id));
            })
            ->value('id');

        if (! $resolvedId) {
            throw ValidationException::withMessages([
                'group_id' => 'Selected group is invalid for this season.',
            ]);
        }

        return $resolvedId;
    }

    private function ensureSeasonUnlocked(int $season): void
    {
        if (! CbbBracket::isLockedForSeason($season)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'Bracket is locked.',
            'lock_at' => CbbBracket::lockAtForSeason($season)?->toIso8601String(),
        ], 423));
    }
}
