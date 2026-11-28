<?php

namespace App\Services\Api\V2;

use App\Models\CbbBracket;
use App\Models\DevicePushRegistration;
use App\Models\DeviceSession;
use App\Models\DeviceSessionRefreshToken;
use App\Models\Group;
use App\Models\UserAlertPreference;
use App\Models\UserBet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Reviewed local database operations only; no provider calls, deliveries or queue dispatch. */
final class AtomicIdempotentRoutes
{
    /** @return array<string, list<class-string<Model>>> */
    public static function operations(): array
    {
        return [
            'v2.user-bets.store' => [UserBet::class],
            'v2.user-bets.update' => [UserBet::class],
            'v2.user-bets.destroy' => [UserBet::class],
            'v2.cbb-brackets.store' => [CbbBracket::class],
            'v2.cbb-brackets.current.upsert' => [CbbBracket::class],
            'v2.cbb-brackets.update' => [CbbBracket::class],
            'v2.cbb-brackets.destroy' => [CbbBracket::class],
            'v2.groups.store' => [Group::class],
            'v2.groups.update' => [Group::class],
            'v2.alert-preferences.store' => [UserAlertPreference::class],
            'v2.alert-preferences.update' => [UserAlertPreference::class],
            'v2.auth.device-sessions.push-registrations.store' => [DevicePushRegistration::class],
            'v2.auth.device-sessions.push-registrations.destroy' => [DevicePushRegistration::class],
            'v2.auth.device-sessions.destroy' => [DeviceSession::class, DeviceSessionRefreshToken::class, DevicePushRegistration::class, PersonalAccessToken::class],
        ];
    }

    public function supports(?string $route, string $connection): bool
    {
        $models = self::operations()[$route ?? ''] ?? null;
        if ($models === null || DB::getDefaultConnection() !== $connection) {
            return false;
        }

        foreach ($models as $model) {
            if ((new $model)->getConnection()->getName() !== $connection) {
                return false;
            }
        }

        return true;
    }
}
