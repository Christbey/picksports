<?php

namespace App\Services\Settings;

use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Support\SportCatalog;

class AlertPreferencePageDataService
{
    public const SPORTS = SportCatalog::ALL;

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $data = [
            'preference' => $this->preferenceForUser($user),
            'availableTemplates' => $this->availableTemplates(),
        ];

        if ($user->isAdmin() || $user->can('view-alert-stats')) {
            $data['adminStats'] = $this->adminStats();
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function preferenceForUser(User $user): ?array
    {
        $preference = UserAlertPreference::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $preference) {
            return null;
        }

        return [
            'enabled' => $preference->enabled,
            'sports' => $preference->sports,
            'notification_types' => $preference->notification_types,
            'enabled_template_ids' => $preference->enabled_template_ids ?? [],
            'minimum_edge' => $preference->minimum_edge,
            'time_window_start' => $preference->time_window_start?->format('H:i'),
            'time_window_end' => $preference->time_window_end?->format('H:i'),
            'digest_mode' => $preference->digest_mode,
            'digest_time' => $preference->digest_time?->format('H:i'),
            'phone_number' => $preference->phone_number,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function availableTemplates(): array
    {
        return NotificationTemplate::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'description'])
            ->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function adminStats(): array
    {
        $baseQuery = UserAlertPreference::query();

        return [
            'total_users_with_alerts' => (clone $baseQuery)->where('enabled', true)->count(),
            'total_preferences' => (clone $baseQuery)->count(),
            'users_by_sport' => collect(self::SPORTS)
                ->mapWithKeys(fn (string $sport) => [
                    $sport => UserAlertPreference::query()
                        ->whereJsonContains('sports', $sport)
                        ->count(),
                ])
                ->all(),
        ];
    }
}
