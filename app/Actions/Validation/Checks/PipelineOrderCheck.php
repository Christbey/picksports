<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Models\CommandHeartbeat;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PipelineOrderCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        if (! Schema::hasTable('command_heartbeats')) {
            return null;
        }

        $rules = Collection::make($profile['pipeline_order'] ?? [])
            ->filter(fn ($rule) => is_array($rule) && isset($rule['upstream'], $rule['downstream']))
            ->values();

        if ($rules->isEmpty()) {
            return null;
        }

        $violations = [];
        $missingHeartbeats = [];

        foreach ($rules as $rule) {
            $upstream = (array) $rule['upstream'];
            $downstream = (array) $rule['downstream'];
            $label = (string) ($rule['label'] ?? 'pipeline dependency');
            $ruleSeverity = in_array(($rule['severity'] ?? null), ['warning', 'failing'], true)
                ? (string) $rule['severity']
                : 'failing';
            $recommendedAction = (string) ($rule['recommended_action'] ?? ($downstream[0] ?? 'review manually'));
            $upstreamHeartbeat = $this->latestSuccess($sport, $upstream);
            $downstreamHeartbeat = $this->latestSuccess($sport, $downstream);

            if (! $upstreamHeartbeat || ! $downstreamHeartbeat) {
                $temporalScope = $this->activeWindowScope($profile);

                if (($temporalScope['blocking_active_games'] ?? null) === 0) {
                    continue;
                }

                $missingHeartbeats[] = [
                    'label' => $label,
                    'upstream' => $upstream,
                    'downstream' => $downstream,
                    'upstream_found' => $upstreamHeartbeat !== null,
                    'downstream_found' => $downstreamHeartbeat !== null,
                    'recommended_action' => $recommendedAction,
                    'temporal_scope' => $temporalScope,
                ];

                continue;
            }

            if ($upstreamHeartbeat->ran_at->gt($downstreamHeartbeat->ran_at)) {
                $temporalScope = $this->temporalScopeForRule($sport, $profile, $label, $upstreamHeartbeat->ran_at);

                if (($temporalScope['blocking_active_games'] ?? null) === 0) {
                    continue;
                }

                $violations[] = [
                    'label' => $label,
                    'upstream_command' => $upstreamHeartbeat->command,
                    'upstream_ran_at' => $upstreamHeartbeat->ran_at->toDateTimeString(),
                    'downstream_command' => $downstreamHeartbeat->command,
                    'downstream_ran_at' => $downstreamHeartbeat->ran_at->toDateTimeString(),
                    'recommended_action' => $recommendedAction,
                    'severity' => $ruleSeverity,
                    'temporal_scope' => $temporalScope,
                ];
            }
        }

        $blockingViolations = array_values(array_filter(
            $violations,
            fn (array $violation): bool => ($violation['severity'] ?? 'failing') === 'failing'
        ));
        $status = 'passing';
        $message = 'Pipeline order looks healthy for configured dependencies.';

        if ($blockingViolations !== []) {
            $status = 'failing';
            $message = count($blockingViolations).' pipeline dependency violation(s) need downstream reruns.';
        } elseif ($violations !== []) {
            $status = 'warning';
            $message = count($violations).' advisory pipeline dependency check(s) should be refreshed.';
        } elseif ($missingHeartbeats !== []) {
            $status = 'warning';
            $message = count($missingHeartbeats).' pipeline dependency check(s) are missing heartbeat history.';
        }

        $recommendedAction = (string) data_get($violations, '0.recommended_action', data_get($missingHeartbeats, '0.recommended_action'));

        return [
            'check_type' => 'validation_pipeline_order',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $recommendedAction !== '' ? $recommendedAction : null,
            'metadata' => [
                'rules_checked' => $rules->count(),
                'violations' => array_slice($violations, 0, 5),
                'missing_heartbeats' => array_slice($missingHeartbeats, 0, 5),
            ],
        ];
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function latestSuccess(string $sport, array $patterns): ?CommandHeartbeat
    {
        return CommandHeartbeat::query()
            ->where('sport', $sport)
            ->where('status', 'success')
            ->where(function (Builder $query) use ($patterns) {
                foreach ($patterns as $index => $pattern) {
                    if ($index === 0) {
                        $query->where('command', 'like', $pattern);
                    } else {
                        $query->orWhere('command', 'like', $pattern);
                    }
                }
            })
            ->latest('ran_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function temporalScopeForRule(string $sport, array $profile, string $label, CarbonInterface $upstreamRanAt): array
    {
        if (! str_contains(strtolower($label), 'details before predictions')) {
            return $this->activeWindowScope($profile);
        }

        $gamesTable = (string) data_get($profile, 'tables.games', '');
        $predictionsTable = "{$sport}_predictions";

        if (
            $gamesTable === ''
            || ! Schema::hasTable($gamesTable)
            || ! Schema::hasTable($predictionsTable)
            || ! Schema::hasColumn($gamesTable, 'game_date')
            || ! Schema::hasColumn($gamesTable, 'game_time')
            || ! Schema::hasColumn($gamesTable, 'status')
        ) {
            return [];
        }

        $dates = app(SportsDateWindowService::class);
        $windowDays = max(1, (int) ($profile['market_window_days'] ?? config('validation.market_window_days', 1)));
        $startDate = $dates->parseLocalDate();
        $endDate = $startDate->addDays($windowDays);
        $upstreamUtc = $upstreamRanAt->copy()->utc();
        $gameColumns = collect(['espn_event_id', 'short_name', 'name'])
            ->filter(fn (string $column): bool => Schema::hasColumn($gamesTable, $column))
            ->values();
        $predictionUpdatedAtColumn = Schema::hasColumn($predictionsTable, 'updated_at')
            ? "{$predictionsTable}.updated_at as prediction_updated_at"
            : DB::raw('NULL as prediction_updated_at');
        $selectColumns = collect([
            "{$gamesTable}.id",
            "{$gamesTable}.game_date",
            "{$gamesTable}.game_time",
            $predictionUpdatedAtColumn,
        ])
            ->merge($gameColumns->map(fn (string $column): string => "{$gamesTable}.{$column}"))
            ->all();

        $games = DB::table($gamesTable)
            ->leftJoin($predictionsTable, "{$predictionsTable}.game_id", '=', "{$gamesTable}.id")
            ->whereDate("{$gamesTable}.game_date", '>=', $startDate->toDateString())
            ->whereDate("{$gamesTable}.game_date", '<=', $endDate->toDateString())
            ->whereIn("{$gamesTable}.status", [
                'STATUS_SCHEDULED',
                'STATUS_PRE_GAME',
                'STATUS_DELAYED',
                'scheduled',
            ])
            ->get($selectColumns);

        $blockingGames = [];

        foreach ($games as $game) {
            $startsAt = $dates->gameDateTimeUtc($game->game_date ?? null, $game->game_time ?? null);

            if (! $startsAt || $startsAt->lte($upstreamUtc)) {
                continue;
            }

            $blockingGames[] = [
                'game_id' => (int) $game->id,
                'espn_event_id' => $game->espn_event_id ?? null,
                'matchup' => ($game->short_name ?? null) ?: ($game->name ?? null),
                'starts_at' => $startsAt->toIso8601String(),
                'prediction_updated_at' => $game->prediction_updated_at,
            ];
        }

        return [
            'rule' => 'pregame_only',
            'window_start' => $startDate->toDateString(),
            'window_end' => $endDate->toDateString(),
            'upstream_ran_at' => $upstreamUtc->toIso8601String(),
            'checked_active_games' => $games->count(),
            'blocking_active_games' => count($blockingGames),
            'sample_games' => array_slice($blockingGames, 0, 5),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function activeWindowScope(array $profile): array
    {
        $gamesTable = (string) data_get($profile, 'tables.games', '');

        if ($gamesTable === '' || ! Schema::hasTable($gamesTable) || ! Schema::hasColumn($gamesTable, 'game_date')) {
            return [];
        }

        $dates = app(SportsDateWindowService::class);
        $windowDays = max(1, (int) ($profile['market_window_days'] ?? config('validation.market_window_days', 1)));
        $startDate = $dates->parseLocalDate();
        $endDate = $startDate->addDays($windowDays);
        $query = DB::table($gamesTable)
            ->whereDate('game_date', '>=', $startDate->toDateString())
            ->whereDate('game_date', '<=', $endDate->toDateString());

        if (Schema::hasColumn($gamesTable, 'status')) {
            $query->whereIn('status', [
                'STATUS_SCHEDULED',
                'STATUS_PRE_GAME',
                'STATUS_DELAYED',
                'STATUS_IN_PROGRESS',
                'scheduled',
                'in_progress',
            ]);
        }

        $activeGames = (int) $query->count();

        return [
            'rule' => 'active_window',
            'window_start' => $startDate->toDateString(),
            'window_end' => $endDate->toDateString(),
            'checked_active_games' => $activeGames,
            'blocking_active_games' => $activeGames,
        ];
    }
}
