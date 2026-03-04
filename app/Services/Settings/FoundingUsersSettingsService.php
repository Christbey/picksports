<?php

namespace App\Services\Settings;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FoundingUsersSettingsService
{
    public const LIMIT_KEY = 'founding_users.limit';

    public function getLimit(): int
    {
        $default = max(0, (int) config('founding_users.limit', 0));

        if (! $this->settingsTableExists()) {
            return $default;
        }

        $value = ApplicationSetting::getValue(self::LIMIT_KEY);
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, (int) $value);
    }

    public function setLimit(int $limit): void
    {
        if (! $this->settingsTableExists()) {
            throw new RuntimeException('Application settings table is missing.');
        }

        ApplicationSetting::setValue(self::LIMIT_KEY, max(0, $limit));
    }

    private function settingsTableExists(): bool
    {
        return Schema::hasTable('application_settings');
    }
}
