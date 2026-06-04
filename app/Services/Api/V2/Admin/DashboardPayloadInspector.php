<?php

namespace App\Services\Api\V2\Admin;

use App\Services\Api\V2\SportContext;
use App\Services\Api\V2\SportContextResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardPayloadInspector
{
    public function __construct(
        private readonly SportContextResolver $sports,
    ) {}

    /**
     * @param  array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}  $inputs
     * @return array<string, mixed>
     */
    public function inspect(array $inputs): array
    {
        $contexts = collect($inputs['sports'])
            ->map(fn (string $sport): ?SportContext => $this->sports->find($sport))
            ->filter()
            ->values();

        $warnings = $this->warnings($inputs, $contexts->all());
        $data = [
            'profile' => $inputs['profile'],
            'generated_at' => now()->toIso8601String(),
            'inputs' => $inputs,
            'diagnostics' => [
                'requested_sports_count' => count($inputs['sports']),
                'resolved_sports_count' => $contexts->count(),
                'payload_included' => $inputs['include_payload'],
                'warnings_included' => $inputs['include_warnings'],
                'warning_count' => count($warnings),
            ],
        ];

        if ($inputs['include_warnings']) {
            $data['diagnostics']['warnings'] = $warnings;
        }

        if ($inputs['include_payload']) {
            $data['payload'] = [
                'sports' => $contexts
                    ->map(fn (SportContext $context): array => $this->sportPayload($context, $inputs['date']))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'data' => $data,
            'meta' => [
                'version' => 'v2',
                'contract' => 'admin.payload-inspector',
                'profile' => $inputs['profile'],
                'shell' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sportPayload(SportContext $context, string $date): array
    {
        return [
            'slug' => $context->slug,
            'label' => $context->label,
            'namespace' => $context->namespace,
            'capabilities' => $context->capabilities,
            'web' => [
                'pages' => (array) ($context->web['pages'] ?? []),
                'details' => (array) ($context->web['details'] ?? []),
                'player_props' => (bool) ($context->web['player_props'] ?? false),
            ],
            'games' => $this->gameDiagnostics($context, $date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gameDiagnostics(SportContext $context, string $date): array
    {
        $gameModel = $context->models['game'] ?? null;

        if (! is_string($gameModel) || ! is_subclass_of($gameModel, Model::class)) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }

        try {
            $table = (new $gameModel)->getTable();

            if (! Schema::hasTable($table)) {
                return [
                    'available' => false,
                    'for_date' => 0,
                    'total' => 0,
                    'latest_updated_at' => null,
                ];
            }

            return [
                'available' => true,
                'for_date' => $this->countForDate($gameModel, $table, $date),
                'total' => $gameModel::query()->count(),
                'latest_updated_at' => $this->latestUpdatedAt($gameModel, $table),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'for_date' => 0,
                'total' => 0,
                'latest_updated_at' => null,
            ];
        }
    }

    /**
     * @param  class-string<Model>  $gameModel
     */
    private function countForDate(string $gameModel, string $table, string $date): int
    {
        if (! Schema::hasColumn($table, 'game_date')) {
            return 0;
        }

        return $gameModel::query()
            ->whereDate('game_date', $date)
            ->count();
    }

    /**
     * @param  class-string<Model>  $gameModel
     */
    private function latestUpdatedAt(string $gameModel, string $table): ?string
    {
        if (! Schema::hasColumn($table, 'updated_at')) {
            return null;
        }

        $updatedAt = $gameModel::query()->max('updated_at');

        return $updatedAt ? CarbonImmutable::parse($updatedAt)->toIso8601String() : null;
    }

    /**
     * @param  array{profile: string, date: string, sports: array<int, string>, include_payload: bool, include_warnings: bool}  $inputs
     * @param  array<int, SportContext>  $contexts
     * @return array<int, array<string, string>>
     */
    private function warnings(array $inputs, array $contexts): array
    {
        $resolved = collect($contexts)->map(fn (SportContext $context): string => $context->slug)->all();
        $missing = array_values(array_diff($inputs['sports'], $resolved));

        if ($missing === []) {
            return [];
        }

        return collect($missing)
            ->map(fn (string $sport): array => [
                'code' => 'sport_unresolved',
                'message' => "Sport [{$sport}] could not be resolved for payload inspection.",
            ])
            ->values()
            ->all();
    }
}
