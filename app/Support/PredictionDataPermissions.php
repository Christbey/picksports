<?php

namespace App\Support;

class PredictionDataPermissions
{
    /**
     * @var array<string, string>
     */
    private const FIELD_PERMISSION_MAP = [
        'spread' => 'view-prediction-spread',
        'win_probability' => 'view-prediction-win-probability',
        'confidence_score' => 'view-prediction-confidence-score',
        'elo_diff' => 'view-prediction-elo-diff',
        'away_elo' => 'view-prediction-away-elo',
        'home_elo' => 'view-prediction-home-elo',
        'betting_value' => 'view-prediction-betting-value',
    ];

    public static function permissionForField(string $field): ?string
    {
        return self::FIELD_PERMISSION_MAP[$field] ?? null;
    }

    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, string>
     */
    public static function permissionsForFields(array $fields): array
    {
        return collect($fields)
            ->filter(fn ($field) => is_string($field) && $field !== '')
            ->map(fn (string $field) => self::permissionForField($field))
            ->filter(fn ($permission) => is_string($permission) && $permission !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public static function allPermissionNames(): array
    {
        return array_values(self::FIELD_PERMISSION_MAP);
    }
}

