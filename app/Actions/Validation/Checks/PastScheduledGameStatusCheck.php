<?php

namespace App\Actions\Validation\Checks;

use App\Actions\Validation\Contracts\ValidationCheck;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PastScheduledGameStatusCheck implements ValidationCheck
{
    private const STALE_STATUSES = [
        'STATUS_SCHEDULED',
        'STATUS_DELAYED',
        'scheduled',
        'delayed',
    ];

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>|null
     */
    public function run(string $sport, array $profile): ?array
    {
        $gamesTable = $profile['tables']['games'] ?? null;
        $gameModel = $profile['models']['game'] ?? null;
        if (! is_string($gamesTable) || ! Schema::hasTable($gamesTable)) {
            return null;
        }

        $lookbackDays = (int) config('validation.thresholds.past_scheduled_game_status.lookback_days', 7);
        $graceHours = (int) config('validation.thresholds.past_scheduled_game_status.grace_hours', 8);
        $dates = app(SportsDateWindowService::class);
        $cutoff = CarbonImmutable::now($dates->timezone())->subHours($graceHours);
        $window = $dates->forRange($cutoff->subDays($lookbackDays), $cutoff);

        $staleQuery = DB::table($gamesTable)
            ->whereIn('status', self::STALE_STATUSES)
            ->whereDate('game_date', '>=', $window->localStartDate())
            ->whereDate('game_date', '<=', $window->localEndDate());

        if (is_string($gameModel) && is_subclass_of($gameModel, Model::class) && method_exists($gameModel, 'scopeWithoutCompletedPlayoffSeriesPlaceholders')) {
            /** @var class-string<Model> $gameModel */
            $visibleGameIds = $gameModel::query()
                ->withoutCompletedPlayoffSeriesPlaceholders()
                ->whereIn('status', self::STALE_STATUSES)
                ->whereDate('game_date', '>=', $window->localStartDate())
                ->whereDate('game_date', '<=', $window->localEndDate())
                ->pluck('id');

            $staleQuery->whereIn('id', $visibleGameIds);
        }

        $staleGames = $staleQuery
            ->orderBy('game_date')
            ->get(['id', 'status', 'game_date', 'game_time', 'short_name', 'name', 'updated_at'])
            ->filter(fn (object $game): bool => $this->isPastGraceCutoff($dates, $game, $cutoff))
            ->values();

        $staleCount = $staleGames->count();
        $status = $staleCount > 0 ? 'failing' : 'passing';
        $oldestDate = $staleGames->min('game_date');
        $newestDate = $staleGames->max('game_date');

        return [
            'check_type' => 'validation_past_scheduled_game_status',
            'status' => $status,
            'severity' => $status,
            'message' => $staleCount > 0
                ? "{$staleCount} past {$sport} game(s) are still marked scheduled or delayed."
                : "No past {$sport} games are stuck in scheduled or delayed status.",
            'recommended_action' => $staleCount > 0
                ? "espn:sync-{$sport}-games-scoreboard --from-date={$oldestDate} --to-date={$newestDate}"
                : "espn:sync-{$sport}-games-scoreboard",
            'metadata' => [
                'lookback_days' => $lookbackDays,
                'grace_hours' => $graceHours,
                'cutoff' => CarbonImmutable::parse($cutoff)->toIso8601String(),
                'date_window' => $window->toArray(),
                'stale_games' => $staleCount,
                'oldest_game_date' => $oldestDate,
                'newest_game_date' => $newestDate,
                'sample_games' => $staleGames
                    ->take(8)
                    ->map(fn ($game): array => [
                        'id' => (int) $game->id,
                        'status' => (string) $game->status,
                        'game_date' => (string) $game->game_date,
                        'game_time' => $game->game_time,
                        'matchup' => $game->short_name ?: $game->name,
                        'updated_at' => $game->updated_at,
                    ])
                    ->values()
                    ->all(),
                'sample_game_ids' => $staleGames->pluck('id')->take(8)->map(fn ($id): int => (int) $id)->values()->all(),
            ],
        ];
    }

    private function isPastGraceCutoff(SportsDateWindowService $dates, object $game, CarbonImmutable $cutoff): bool
    {
        $gameDateTime = $dates->gameDateTimeUtc($game->game_date ?? null, $game->game_time ?? null);

        if (! $gameDateTime) {
            return true;
        }

        return $gameDateTime->lte($cutoff->utc());
    }
}
