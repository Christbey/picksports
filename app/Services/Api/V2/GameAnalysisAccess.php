<?php

namespace App\Services\Api\V2;

use App\Models\User;
use App\Support\TierAccessBypass;
use Spatie\Permission\Exceptions\GuardDoesNotMatch;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/** Same prediction disclosure checks as the existing NFL permission middleware. */
class GameAnalysisAccess
{
    public function __construct(private readonly TierAccessBypass $tiers) {}

    public function authorize(?User $user, string $sport): void
    {
        abort_unless($user?->isAdmin(), 403);
        if ($this->tiers->shouldBypassTierChecks($user)) {
            return;
        }
        foreach (["view-{$sport}-predictions", 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
            try {
                $allowed = $user->hasPermissionTo($permission);
            } catch (PermissionDoesNotExist|GuardDoesNotMatch) {
                $allowed = false;
            }
            abort_unless($allowed, 403);
        }
    }
}
