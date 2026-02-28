<?php

namespace App\Support\Validation;

use App\Support\SportCatalog;
use Illuminate\Validation\Rule;

class AlertPreferenceRules
{
    /**
     * @return array<string, mixed>
     */
    public static function apiStore(): array
    {
        return [
            'enabled' => 'required|boolean',
            'sports' => 'required|array',
            'sports.*' => ['string', Rule::in(SportCatalog::ALL)],
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
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
            'sports' => 'sometimes|array',
            'sports.*' => ['string', Rule::in(SportCatalog::ALL)],
            'notification_types' => 'sometimes|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'minimum_edge' => 'sometimes|numeric|min:0|max:100',
            'time_window_start' => 'sometimes|date_format:H:i',
            'time_window_end' => 'sometimes|date_format:H:i',
            'digest_mode' => 'sometimes|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
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
            'sports' => 'required|array',
            'sports.*' => ['string', Rule::in(SportCatalog::ALL)],
            'notification_types' => 'required|array',
            'notification_types.*' => 'string|in:email,sms,push',
            'enabled_template_ids' => 'nullable|array',
            'enabled_template_ids.*' => 'integer|exists:notification_templates,id',
            'minimum_edge' => 'required|numeric|min:0|max:100',
            'time_window_start' => 'required|date_format:H:i',
            'time_window_end' => 'required|date_format:H:i',
            'digest_mode' => 'required|in:realtime,daily_summary',
            'digest_time' => 'nullable|date_format:H:i',
            'phone_number' => 'nullable|string|max:20',
        ];
    }
}
