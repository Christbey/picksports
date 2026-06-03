<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FuturesOddsFreshnessCheck implements ValidationCheck
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $enabled = (bool) ($profile['futures_enabled'] ?? false);

        if (! $enabled || ! Schema::hasTable('sports_futures_odds')) {
            return null;
        }

        $season = (int) now()->year;
        $staleHours = (int) config('validation.thresholds.futures_odds_freshness.stale_after_hours', 12);
        $minimumRows = (int) config('validation.thresholds.futures_odds_freshness.minimum_rows', 1);
        $rows = DB::table('sports_futures_odds')
            ->where('sport', $sport)
            ->where(function ($query) use ($season) {
                $query->where('season', $season)
                    ->orWhereNull('season');
            });

        $rowCount = (int) (clone $rows)->count();
        $latestFetchedAt = (clone $rows)->max('fetched_at');
        $missingRows = $rowCount < $minimumRows;
        $stale = ! $latestFetchedAt || now()->parse($latestFetchedAt)->lt(now()->subHours($staleHours));
        $status = $missingRows || $stale ? 'failing' : 'passing';
        $message = $status === 'passing'
            ? "Futures odds are fresh with {$rowCount} row(s)."
            : "Futures odds are missing or stale with {$rowCount} row(s).";

        return [
            'check_type' => 'validation_futures_odds_freshness',
            'status' => $status,
            'severity' => $status,
            'message' => $message,
            'recommended_action' => "sports:sync-futures-odds --sport={$sport} --season={$season}",
            'metadata' => [
                'season' => $season,
                'rows' => $rowCount,
                'minimum_rows' => $minimumRows,
                'latest_fetched_at' => $latestFetchedAt,
                'stale_after_hours' => $staleHours,
                'missing_rows' => $missingRows,
                'stale' => $stale,
            ],
        ];
    }
}
