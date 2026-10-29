<?php

namespace App\Services\Api\V2;

use App\Models\User;
use App\Support\SportPredictionAccess;

class DashboardSportConfig
{
    private const PRESENTATION = [
        'NBA' => ['National Basketball Association', 'orange'],
        'CBB' => ["Men's College Basketball", 'blue'],
        'WCBB' => ["Women's College Basketball", 'purple'],
        'NFL' => ['National Football League', 'green'],
        'MLB' => ['Major League Baseball', 'orange'],
        'CFB' => ['College Football', 'blue'],
        'WNBA' => ["Women's National Basketball Association", 'purple'],
    ];

    public function __construct(
        private readonly SportContextResolver $sports,
        private readonly SportPredictionAccess $access,
    ) {}

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        $configs = [];
        foreach (self::PRESENTATION as $sport => [$name, $color]) {
            $context = $this->sports->resolve(strtolower($sport));
            $baseball = $sport === 'MLB';
            $configs[$sport] = [
                'full_name' => $name,
                'color' => $color,
                'prediction_model' => $context->models['prediction'] ?? null,
                'game_model' => $context->models['game'],
                'live_statuses' => $baseball ? ['STATUS_IN_PROGRESS', 'STATUS_DELAYED'] : ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'],
                'final_statuses' => ['STATUS_FINAL', 'STATUS_FULL_TIME'],
                'live_remaining_field' => $baseball ? 'live_outs_remaining' : 'live_seconds_remaining',
                'include_inning' => $baseball,
            ];
        }

        return $configs;
    }

    /** @return array<string, array<string, mixed>> */
    public function viewable(?User $user): array
    {
        return array_filter($this->all(), fn (string $sport): bool => $this->access->canView($user, $sport), ARRAY_FILTER_USE_KEY);
    }
}
