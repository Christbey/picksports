<?php

namespace App\Console\Commands\MLB;

use App\Models\MLB\BullpenRating;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Services\MLB\BullpenRatingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CalculateBullpenRatingsCommand extends Command
{
    protected $signature = 'mlb:calculate-bullpen-ratings
        {--season= : Calculate bullpen ratings for a specific season}
        {--season-type= : Limit ratings to a specific season type}
        {--date= : Calculate a single as-of date snapshot (YYYY-MM-DD)}
        {--from-date= : Start date for snapshot generation (YYYY-MM-DD)}
        {--to-date= : End date for snapshot generation (YYYY-MM-DD)}
        {--team= : Calculate for a specific team ID}';

    protected $description = 'Calculate MLB bullpen rating snapshots and ranks.';

    public function handle(BullpenRatingService $service): int
    {
        $season = (int) ($this->option('season') ?? date('Y'));
        $seasonTypes = $this->seasonTypesForRun($season);
        $dates = $this->datesForRun($season, $seasonTypes);

        if ($seasonTypes === [] || $dates === []) {
            $this->warn('No qualifying MLB season types or snapshot dates found for bullpen ratings.');

            return self::SUCCESS;
        }

        $teamQuery = Team::query()->orderBy('abbreviation');
        if ($this->option('team')) {
            $teamQuery->whereKey((int) $this->option('team'));
        }

        $teams = $teamQuery->get();
        if ($teams->isEmpty()) {
            $this->warn('No MLB teams matched the requested bullpen rating scope.');

            return self::SUCCESS;
        }

        $attempted = 0;
        $generated = 0;
        $bar = $this->output->createProgressBar(count($dates) * count($seasonTypes) * $teams->count());
        $bar->start();

        foreach ($seasonTypes as $seasonType) {
            foreach ($dates as $date) {
                foreach ($teams as $team) {
                    $attempted++;
                    if ($service->persistForTeam($team, $season, $seasonType, $date)) {
                        $generated++;
                    }
                    $bar->advance();
                }

                $service->updateRanks($season, $seasonType, $date);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated {$generated} bullpen rating snapshots across {$attempted} team/date combinations.");

        $latestDate = end($dates);
        if ($latestDate !== false) {
            $this->newLine();
            $this->info("Top bullpen ratings for {$latestDate}:");
            $rows = BullpenRating::query()
                ->with('team')
                ->where('season', $season)
                ->where('season_type', (string) $seasonTypes[0])
                ->whereDate('as_of_date', $latestDate)
                ->orderBy('rating_rank')
                ->limit(10)
                ->get()
                ->map(fn ($rating) => [
                    $rating->rating_rank,
                    $rating->team?->abbreviation,
                    round((float) $rating->rating_score, 2),
                    round((float) $rating->weighted_era, 2),
                    round((float) $rating->weighted_whip, 3),
                    $rating->games_sampled,
                ])
                ->all();

            if ($rows !== []) {
                $this->table(['Rank', 'Team', 'Rating', 'ERA', 'WHIP', 'Games'], $rows);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function seasonTypesForRun(int $season): array
    {
        $explicit = $this->option('season-type');
        if (is_string($explicit) && trim($explicit) !== '') {
            return [trim($explicit)];
        }

        return Game::query()
            ->where('season', $season)
            ->where('status', (string) config('mlb.statuses.final', 'STATUS_FINAL'))
            ->distinct()
            ->orderBy('season_type')
            ->pluck('season_type')
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $seasonTypes
     * @return array<int, string>
     */
    private function datesForRun(int $season, array $seasonTypes): array
    {
        $explicitDate = $this->option('date');
        if (is_string($explicitDate) && trim($explicitDate) !== '') {
            return [Carbon::parse($explicitDate)->toDateString()];
        }

        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');

        $query = Game::query()
            ->where('season', $season)
            ->where('status', (string) config('mlb.statuses.final', 'STATUS_FINAL'))
            ->when($seasonTypes !== [], fn ($builder) => $builder->whereIn('season_type', $seasonTypes));

        if (is_string($fromDate) && trim($fromDate) !== '') {
            $query->whereDate('game_date', '>=', Carbon::parse($fromDate)->toDateString());
        }

        if (is_string($toDate) && trim($toDate) !== '') {
            $query->whereDate('game_date', '<=', Carbon::parse($toDate)->toDateString());
        }

        $dates = $query
            ->orderBy('game_date')
            ->get()
            ->map(fn (Game $game) => $game->game_date?->toDateString())
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $dates !== [] ? $dates : [now()->toDateString()];
    }
}
