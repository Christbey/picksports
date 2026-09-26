<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflFrozenSpreadReplay;
use App\Services\NFL\NflFrozenSpreadReplayAudit;
use Illuminate\Console\Command;

class ReplayFrozenSpreadsCommand extends Command
{
    protected $signature = 'nfl:replay-frozen-spreads {--season=2026} {--through-week=2} {--variant=safeguards_v1 : safeguards_v1, rushing_direction_only, injury_precision_only, history_context_off_only, or batch_v2}';

    protected $description = 'Read-only spread-policy counterfactual from frozen signals; emits matched results and exclusions as JSON';

    public function handle(NflFrozenSpreadReplayAudit $audit): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $week = filter_var($this->option('through-week'), FILTER_VALIDATE_INT);
        if ($season === false || $season < 2000 || $season > 2100 || $week === false || $week < 1 || $week > 18) {
            $this->error('Use a season from 2000 to 2100 and a regular-season week from 1 to 18.');

            return self::INVALID;
        }
        $variant = (string) $this->option('variant');
        if (! in_array($variant, NflFrozenSpreadReplay::VARIANTS, true)) {
            $this->error('Unknown replay variant. Use: '.implode(', ', NflFrozenSpreadReplay::VARIANTS));

            return self::INVALID;
        }
        $report = $audit->run($season, $week, $variant);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return collect($report['games'])->contains('status', 'replayed') ? self::SUCCESS : self::FAILURE;
    }
}
