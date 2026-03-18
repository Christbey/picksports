<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\RecalculateTournamentOutlook;
use Illuminate\Console\Command;

class RecalculateTournamentOutlookCommand extends Command
{
    protected $signature = 'cbb:recalculate-tournament-outlook
        {season? : Season to recalculate}
        {--source=manual : Snapshot source label}
        {--trigger-game-id= : Optional triggering cbb_games id}';

    protected $description = 'Recalculate live CBB tournament outlook probabilities into snapshot tables';

    public function handle(RecalculateTournamentOutlook $action): int
    {
        $season = (int) ($this->argument('season') ?: config('cbb.season.default'));
        $source = (string) $this->option('source');
        $triggerGameId = $this->option('trigger-game-id');
        $triggerGameId = $triggerGameId !== null ? (int) $triggerGameId : null;

        $this->info("Recalculating live CBB tournament outlook for {$season}...");

        $snapshot = $action->execute($season, $source, $triggerGameId);

        $this->table(
            ['Snapshot', 'Status', 'As Of', 'Field', 'Final Games', 'Remaining', 'Rows'],
            [[
                $snapshot->id,
                $snapshot->status,
                optional($snapshot->as_of)?->toDateTimeString(),
                $snapshot->field_size,
                $snapshot->games_final_count,
                $snapshot->games_remaining_count,
                $snapshot->forecasts()->count(),
            ]]
        );

        return self::SUCCESS;
    }
}
