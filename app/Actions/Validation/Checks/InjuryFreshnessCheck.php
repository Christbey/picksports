<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Models\CommandHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InjuryFreshnessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $tables = $profile['tables'] ?? [];
        $injuriesTable = $tables['injuries'] ?? null;
        $command = $profile['injuries_command'] ?? "espn:sync-{$sport}-injuries";

        if (! is_string($injuriesTable) || ! Schema::hasTable($injuriesTable)) {
            return null;
        }

        $inSeason = in_array((int) now()->month, (array) ($profile['active_months'] ?? []), true);
        $warningHours = (int) config('validation.thresholds.injury_freshness.warning_after_hours', $inSeason ? 12 : 72);
        $failingHours = (int) config('validation.thresholds.injury_freshness.failing_after_hours', $inSeason ? 24 : 168);
        $activeInjuries = (int) DB::table($injuriesTable)->where('is_active', true)->count();
        $lastDataUpdate = DB::table($injuriesTable)->max('updated_at');
        $lastSync = CommandHeartbeat::query()
            ->where('sport', $sport)
            ->where('status', 'success')
            ->where('command', 'like', "espn:sync-{$sport}-injuries%")
            ->latest('ran_at')
            ->first();

        $freshAt = $lastSync?->ran_at ?? ($lastDataUpdate ? now()->parse($lastDataUpdate) : null);

        if (! $freshAt) {
            return [
                'check_type' => 'validation_injury_freshness',
                'status' => 'failing',
                'severity' => 'failing',
                'message' => 'No injury sync heartbeat or injury data has been recorded.',
                'recommended_action' => $command,
                'metadata' => [
                    'in_season' => $inSeason,
                    'active_injuries' => $activeInjuries,
                    'last_sync_at' => null,
                    'last_data_update_at' => null,
                    'warning_after_hours' => $warningHours,
                    'failing_after_hours' => $failingHours,
                ],
            ];
        }

        $now = now();
        $freshAtIsFuture = $freshAt->greaterThan($now);
        $ageHours = $freshAtIsFuture ? 0 : (int) $freshAt->diffInHours($now);
        $status = $freshAtIsFuture ? 'warning' : 'passing';

        if (! $freshAtIsFuture && $ageHours > $failingHours) {
            $status = 'failing';
        } elseif (! $freshAtIsFuture && $ageHours > $warningHours) {
            $status = 'warning';
        }

        $message = $freshAtIsFuture
            ? "Injury data refresh timestamp is in the future ({$freshAt->toDateTimeString()}); check server and provider clocks."
            : match ($status) {
                'passing' => "Injury data is fresh. Last refresh {$ageHours} hour(s) ago.",
                'warning' => "Injury data is getting stale. Last refresh {$ageHours} hour(s) ago.",
                'failing' => "Injury data is stale. Last refresh {$ageHours} hour(s) ago.",
                default => 'Injury data freshness is unknown.',
            };

        return [
            'check_type' => 'validation_injury_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => $command,
            'metadata' => [
                'in_season' => $inSeason,
                'active_injuries' => $activeInjuries,
                'last_sync_at' => $lastSync?->ran_at?->toDateTimeString(),
                'last_data_update_at' => $lastDataUpdate,
                'fresh_at' => $freshAt->toDateTimeString(),
                'age_hours' => $ageHours,
                'fresh_at_is_future' => $freshAtIsFuture,
                'warning_after_hours' => $warningHours,
                'failing_after_hours' => $failingHours,
            ],
        ];
    }
}
