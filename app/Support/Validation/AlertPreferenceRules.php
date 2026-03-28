<?php

namespace App\Support\Validation;

class AlertPreferenceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function apiStore(): array
    {
        return [
            'enabled' => 'required|boolean',
            'sports' => 'prohibited',
            'sports.*' => 'prohibited',
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push,whatsapp',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'daily_digest_subscribed' => 'sometimes|boolean',
            'phone_number' => 'nullable|string|max:20',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function apiUpdate(): array
    {
        return [
            'enabled' => 'sometimes|boolean',
            'sports' => 'prohibited',
            'sports.*' => 'prohibited',
            'notification_types' => 'sometimes|array',
            'notification_types.*' => 'string|in:email,sms,push,whatsapp',
            'minimum_edge' => 'sometimes|numeric|min:0|max:100',
            'time_window_start' => 'sometimes|date_format:H:i',
            'time_window_end' => 'sometimes|date_format:H:i',
            'digest_mode' => 'sometimes|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'daily_digest_subscribed' => 'sometimes|boolean',
            'phone_number' => 'nullable|string|max:20',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settingsUpdate(): array
    {
        return [
            'enabled' => 'required|boolean',
            'sports' => 'prohibited',
            'sports.*' => 'prohibited',
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push,whatsapp',
            'enabled_template_ids' => 'nullable|array',
            'enabled_template_ids.*' => 'integer|exists:notification_templates,id',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'daily_digest_subscribed' => 'required|boolean',
            'phone_number' => 'nullable|string|max:20',
        ];
    }
}
