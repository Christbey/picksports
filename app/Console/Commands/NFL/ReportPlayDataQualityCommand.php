<?php

namespace App\Console\Commands\NFL;

use App\Models\NFL\Play;
use App\Services\NFL\PlayEpaDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportPlayDataQualityCommand extends Command
{
    protected $signature = 'nfl:report-play-data-quality
        {--season= : Limit to season (e.g. 2025)}
        {--game_id= : Limit to a single nfl_games.id}';

    protected $description = 'Report nfl_plays data quality for true play-by-play EPA readiness';

    public function handle(PlayEpaDataService $playDataService): int
    {
        $season = $this->option('season');
        $gameId = $this->option('game_id');

        $baseQuery = Play::query()
            ->join('nfl_games', 'nfl_games.id', '=', 'nfl_plays.game_id');

        if ($season !== null && $season !== '') {
            $baseQuery->where('nfl_games.season', (int) $season);
        }

        if ($gameId !== null && $gameId !== '') {
            $baseQuery->where('nfl_plays.game_id', (int) $gameId);
        }

        $total = (clone $baseQuery)->count('nfl_plays.id');
        if ($total === 0) {
            $this->warn('No plays found for the selected scope.');

            return self::SUCCESS;
        }

        $nullDown = (clone $baseQuery)->whereNull('nfl_plays.down')->count('nfl_plays.id');
        $nullDistance = (clone $baseQuery)->whereNull('nfl_plays.distance')->count('nfl_plays.id');
        $nullYte = (clone $baseQuery)->whereNull('nfl_plays.yards_to_endzone')->count('nfl_plays.id');
        $nullPossession = (clone $baseQuery)->whereNull('nfl_plays.possession_team_id')->count('nfl_plays.id');

        $plays = (clone $baseQuery)->select([
            'nfl_plays.id',
            'nfl_plays.play_type',
            'nfl_plays.play_text',
            'nfl_plays.down',
            'nfl_plays.distance',
            'nfl_plays.yards_to_endzone',
            'nfl_plays.possession_team_id',
            'nfl_plays.true_epa',
        ])->get();

        $epaEligible = 0;
        $epaEligibleMissingPoss = 0;
        $epaEligibleWithValue = 0;
        $epaSum = 0.0;
        $excludedPlayTypes = [];

        foreach ($plays as $play) {
            if ($playDataService->isEpaEligiblePlay($play)) {
                $epaEligible++;
                if ($play->possession_team_id === null) {
                    $epaEligibleMissingPoss++;
                }
                if ($play->true_epa !== null) {
                    $epaEligibleWithValue++;
                    $epaSum += (float) $play->true_epa;
                }

                continue;
            }

            $playType = trim((string) ($play->play_type ?? ''));
            if ($playType === '') {
                $playType = '[blank]';
            }

            $excludedPlayTypes[$playType] = ($excludedPlayTypes[$playType] ?? 0) + 1;
        }

        arsort($excludedPlayTypes);

        $coveragePct = $total > 0 ? round((($total - $nullPossession) / $total) * 100, 2) : 0.0;
        $eligibleCoveragePct = $epaEligible > 0
            ? round((($epaEligible - $epaEligibleMissingPoss) / $epaEligible) * 100, 2)
            : 0.0;
        $trueEpaCoveragePct = $epaEligible > 0
            ? round(($epaEligibleWithValue / $epaEligible) * 100, 2)
            : 0.0;
        $avgTrueEpa = $epaEligibleWithValue > 0 ? round($epaSum / $epaEligibleWithValue, 4) : 0.0;

        $this->line('NFL Play Data Quality');
        $this->line('---------------------');
        $this->line("Total plays: {$total}");
        $this->line('Games: '.(clone $baseQuery)->distinct('nfl_plays.game_id')->count('nfl_plays.game_id'));
        $this->line("Null down: {$nullDown}");
        $this->line("Null distance: {$nullDistance}");
        $this->line("Null yards_to_endzone: {$nullYte}");
        $this->line("Null possession_team_id: {$nullPossession} ({$coveragePct}% filled)");
        $this->line("EPA-eligible plays: {$epaEligible}");
        $this->line("EPA-eligible missing possession: {$epaEligibleMissingPoss} ({$eligibleCoveragePct}% filled)");
        $this->line("EPA-eligible with true_epa: {$epaEligibleWithValue} ({$trueEpaCoveragePct}% scored)");
        $this->line("Avg true_epa (eligible scored plays): {$avgTrueEpa}");
        $this->newLine();

        $topExcluded = array_slice($excludedPlayTypes, 0, 12, true);
        $rows = [];
        foreach ($topExcluded as $playType => $count) {
            $rows[] = [$playType, $count];
        }

        $this->table(['Top Excluded Play Types', 'Count'], $rows);

        $qualitySnapshot = (clone $baseQuery)
            ->select(
                DB::raw('SUM(CASE WHEN nfl_plays.is_scoring_play = 1 THEN 1 ELSE 0 END) as scoring_plays'),
                DB::raw('SUM(CASE WHEN nfl_plays.is_turnover = 1 THEN 1 ELSE 0 END) as turnover_plays'),
                DB::raw('SUM(CASE WHEN nfl_plays.is_penalty = 1 THEN 1 ELSE 0 END) as penalty_plays')
            )
            ->first();

        $this->line('Scoring plays: '.(int) ($qualitySnapshot->scoring_plays ?? 0));
        $this->line('Turnover plays: '.(int) ($qualitySnapshot->turnover_plays ?? 0));
        $this->line('Penalty plays: '.(int) ($qualitySnapshot->penalty_plays ?? 0));

        return self::SUCCESS;
    }
}
