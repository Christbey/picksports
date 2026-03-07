<?php

namespace App\Services;

class NotificationVariableRegistry
{
    /**
     * Get all available notification variables grouped by category.
     */
    public static function all(): array
    {
        return [
            'user' => [
                'name' => 'User\'s full name',
                'email' => 'User\'s email address',
                'phone' => 'User\'s phone number',
            ],
            'prediction' => [
                'sport' => 'Sport name (NBA, NFL, etc.)',
                'game_description' => 'Full game description (Team A vs Team B)',
                'home_team' => 'Home team name',
                'away_team' => 'Away team name',
                'pick_type' => 'Type of pick (spread, moneyline, over/under)',
                'recommended_pick' => 'The recommended pick',
                'edge_percentage' => 'Calculated edge percentage',
                'confidence' => 'Confidence level',
                'odds' => 'Current odds',
                'game_time' => 'Game start time',
                'game_date' => 'Game date',
            ],
            'system' => [
                'app_name' => 'Application name',
                'app_url' => 'Application URL',
                'support_email' => 'Support email address',
            ],
        ];
    }

    /**
     * Get flat list of all variables with descriptions.
     */
    public static function flat(): array
    {
        $flat = [];

        foreach (self::all() as $category => $variables) {
            foreach ($variables as $key => $description) {
                $flat["{$category}.{$key}"] = $description;
            }
        }

        return $flat;
    }

    /**
     * Get list of variable names only (for validation).
     */
    public static function keys(): array
    {
        return array_keys(self::flat());
    }

    /**
     * Convert notification data to flat array for template substitution.
     */
    public static function flattenData(array $data): array
    {
        $flattened = [];

        foreach ($data as $category => $values) {
            if (is_array($values)) {
                foreach ($values as $key => $value) {
                    $flattened["{$category}.{$key}"] = $value;
                }
            }
        }

        return $flattened;
    }

    /**
     * Get variables for admin UI.
     */
    public static function forAdminUI(): array
    {
        $variables = [];

        foreach (self::all() as $category => $items) {
            foreach ($items as $key => $description) {
                $variables[] = [
                    'name' => "{$category}.{$key}",
                    'description' => $description,
                    'category' => ucfirst($category),
                ];
            }
        }

        return $variables;
    }
}
