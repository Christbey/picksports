<?php

namespace App\Services\TournamentForecast;

use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Schema;

class CbbTournamentForecastTuningStore
{
    public const SETTINGS_KEY = 'cbb.tournament_forecast.tuned_params';

    /**
     * @return array<string, mixed>
     */
    public function getForSeason(int $season): array
    {
        $all = $this->all();
        $key = (string) $season;

        $params = $all[$key] ?? [];

        return is_array($params) ? $params : [];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function setForSeason(int $season, array $params): void
    {
        if (! $this->settingsTableExists()) {
            return;
        }

        $all = $this->all();
        $all[(string) $season] = $params;

        ApplicationSetting::setValue(self::SETTINGS_KEY, $all);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (! $this->settingsTableExists()) {
            return [];
        }

        $raw = ApplicationSetting::getValue(self::SETTINGS_KEY, '{}');
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function settingsTableExists(): bool
    {
        return Schema::hasTable('application_settings');
    }
}
