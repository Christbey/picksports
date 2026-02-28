<?php

namespace App\Http\Resources\Sports;

use App\Support\PredictionDataPermissions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

abstract class AbstractPredictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    protected function basePredictionData(string $gameResourceClass): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'game' => $gameResourceClass::make($this->whenLoaded('game')),
        ];
    }

    protected function hasTierPermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        $permissionName = PredictionDataPermissions::permissionForField($permission) ?? $permission;

        try {
            return $user->hasPermissionTo($permissionName);
        } catch (PermissionDoesNotExist|GuardDoesNotMatch) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendStandardTimestamps(array $data): array
    {
        $data['created_at'] = $this->created_at?->toIso8601String();
        $data['updated_at'] = $this->updated_at?->toIso8601String();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function appendStandardGradingFields(array $data): array
    {
        $data['actual_spread'] = $this->actual_spread;
        $data['actual_total'] = $this->actual_total;
        $data['spread_error'] = $this->spread_error;
        $data['total_error'] = $this->total_error;
        $data['winner_correct'] = $this->winner_correct;
        $data['graded_at'] = $this->graded_at?->toIso8601String();

        return $data;
    }
}
