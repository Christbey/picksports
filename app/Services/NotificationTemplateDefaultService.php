<?php

namespace App\Services;

use App\Models\NotificationTemplate;
use App\Models\NotificationTemplateDefault;

class NotificationTemplateDefaultService
{
    public const ALERT_TYPES = [
        'betting_value_alert' => 'Betting Value Alert',
        'daily_betting_digest' => 'Daily Betting Digest',
    ];

    public function resolve(string $alertType): ?NotificationTemplate
    {
        $assignedTemplateId = NotificationTemplateDefault::query()
            ->where('alert_type', $alertType)
            ->value('template_id');

        if ($assignedTemplateId) {
            $assigned = NotificationTemplate::query()
                ->whereKey($assignedTemplateId)
                ->active()
                ->first();

            if ($assigned) {
                return $assigned;
            }
        }

        $fallbackName = self::ALERT_TYPES[$alertType] ?? null;
        if (! $fallbackName) {
            return null;
        }

        return NotificationTemplate::query()
            ->where('name', $fallbackName)
            ->active()
            ->first();
    }

    /**
     * @return array<string, int|null>
     */
    public function assignments(): array
    {
        $defaults = NotificationTemplateDefault::query()
            ->whereIn('alert_type', array_keys(self::ALERT_TYPES))
            ->pluck('template_id', 'alert_type')
            ->all();

        $result = [];
        foreach (self::ALERT_TYPES as $key => $label) {
            $result[$key] = isset($defaults[$key]) ? (int) $defaults[$key] : null;
        }

        return $result;
    }

    /**
     * @param  array<string, int|null>  $defaults
     */
    public function updateAssignments(array $defaults): void
    {
        foreach (self::ALERT_TYPES as $alertType => $label) {
            NotificationTemplateDefault::query()->updateOrCreate(
                ['alert_type' => $alertType],
                ['template_id' => $defaults[$alertType] ?? null]
            );
        }
    }
}

