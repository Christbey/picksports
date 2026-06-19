<?php

namespace App\Console\Commands\MLB;

use App\Actions\MLB\ReconcileGameScoreFromTeamStats;
use App\Models\MLB\Game;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReconcileFinalScoresCommand extends Command
{
    protected $signature = 'mlb:reconcile-final-scores
        {--season= : Season to reconcile}
        {--from= : Start game date in YYYY-MM-DD}
        {--to= : End game date in YYYY-MM-DD}
        {--dry-run : Report what would change without writing}
        {--force : Overwrite existing score conflicts with team stat runs}';

    protected $description = 'Reconcile final MLB game scores from home/away team stat runs.';

    public function handle(ReconcileGameScoreFromTeamStats $reconcile): int
    {
        $season = $this->option('season') !== null && $this->option('season') !== ''
            ? (int) $this->option('season')
            : null;
        $from = $this->parseDateOption('from');
        $to = $this->parseDateOption('to');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $this->info('Reconciling MLB final scores from team stats.');
        $this->line('Season: '.($season ?: 'all'));
        $this->line('Date range: '.($from ?: 'beginning').' through '.($to ?: 'today'));
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'apply').($force ? ' with force' : ''));

        $query = $this->baseQuery($season, $from, $to);
        $finalGamesScanned = (clone $query)->count();

        $counts = [
            'unchanged' => 0,
            'updated' => 0,
            'skipped' => 0,
            'skipped_missing_team_stats' => 0,
            'skipped_non_final' => 0,
            'conflict' => 0,
        ];

        $samples = [];

        $query->orderBy('game_date')->orderBy('id')->lazy()->each(function (Game $game) use ($reconcile, $force, $dryRun, &$counts, &$samples): void {
            $result = $reconcile->execute($game, force: $force, dryRun: $dryRun);
            $status = (string) $result['status'];
            $reason = (string) $result['reason'];

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }

            if ($status === 'skipped' && $reason === 'missing_team_stats_runs') {
                $counts['skipped_missing_team_stats']++;
            }

            if ($status === 'skipped' && $reason === 'game_not_final') {
                $counts['skipped_non_final']++;
            }

            if (in_array($status, ['updated', 'conflict', 'skipped'], true) && count($samples) < 8) {
                $samples[] = [
                    $game->id,
                    $game->espn_event_id,
                    $game->short_name ?: $game->name,
                    $game->game_date?->toDateString(),
                    $status,
                    $reason,
                    $result['home_score_before'].'-'.$result['away_score_before'],
                    $result['home_score_after'].'-'.$result['away_score_after'],
                ];
            }
        });

        $remainingMissing = $this->missingFinalScoresQuery($season, $from, $to)->count();
        $remainingReconstructable = $this->reconstructableMissingScoresQuery($season, $from, $to)->count();

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Final games scanned', $finalGamesScanned],
                ['Games already had matching scores', $counts['unchanged']],
                [$dryRun ? 'Games that would be updated' : 'Games updated', $counts['updated']],
                ['Games skipped', $counts['skipped']],
                ['Skipped due to missing team stat runs', $counts['skipped_missing_team_stats']],
                ['Skipped due to non-final status', $counts['skipped_non_final']],
                ['Conflicts', $counts['conflict']],
                ['Remaining final games with null scores', $remainingMissing],
                ['Remaining reconstructable final games with null scores', $remainingReconstructable],
            ]
        );

        if ($samples !== []) {
            $this->table(
                ['Game', 'ESPN', 'Matchup', 'Date', 'Status', 'Reason', 'Before', 'After'],
                $samples
            );
        }

        if ($counts['conflict'] > 0) {
            $this->warn("{$counts['conflict']} score conflict(s) found. Re-run with --force only if team stat runs are the intended source of truth.");
        }

        return Command::SUCCESS;
    }

    private function parseDateOption(string $option): ?string
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    /**
     * @return Builder<Game>
     */
    private function baseQuery(?int $season, ?string $from, ?string $to): Builder
    {
        return Game::query()
            ->where('status', 'STATUS_FINAL')
            ->when($season !== null, fn (Builder $query) => $query->where('season', $season))
            ->when($from !== null, fn (Builder $query) => $query->whereDate('game_date', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('game_date', '<=', $to));
    }

    /**
     * @return Builder<Game>
     */
    private function missingFinalScoresQuery(?int $season, ?string $from, ?string $to): Builder
    {
        return $this->baseQuery($season, $from, $to)
            ->where(fn (Builder $query) => $query
                ->whereNull('home_score')
                ->orWhereNull('away_score'));
    }

    /**
     * @return Builder<Game>
     */
    private function reconstructableMissingScoresQuery(?int $season, ?string $from, ?string $to): Builder
    {
        return $this->missingFinalScoresQuery($season, $from, $to)
            ->whereHas('teamStats', fn (Builder $query) => $query
                ->whereColumn('mlb_team_stats.team_id', 'mlb_games.home_team_id')
                ->where('team_type', 'home')
                ->whereNotNull('runs'))
            ->whereHas('teamStats', fn (Builder $query) => $query
                ->whereColumn('mlb_team_stats.team_id', 'mlb_games.away_team_id')
                ->where('team_type', 'away')
                ->whereNotNull('runs'));
    }
}
