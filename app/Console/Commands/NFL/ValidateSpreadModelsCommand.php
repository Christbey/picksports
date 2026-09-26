<?php

namespace App\Console\Commands\NFL;

use App\Services\NFL\NflSpreadValidationDataset;
use App\Services\NFL\NflSpreadWalkForward;
use Illuminate\Console\Command;

class ValidateSpreadModelsCommand extends Command
{
    protected $signature = 'nfl:validate-spread-models
        {--from-season=2021 : First regular season}
        {--to-season= : Last regular season, defaults to current year}
        {--json : Print summary as JSON}
        {--detailed : Include per-game shadow estimates in JSON}';

    protected $description = 'Read-only chronological NFL spread audit using frozen pregame forecasts; never tunes or promotes models';

    public function handle(NflSpreadValidationDataset $dataset, NflSpreadWalkForward $validator): int
    {
        $from = filter_var($this->option('from-season'), FILTER_VALIDATE_INT);
        $to = filter_var($this->option('to-season') ?? now()->year, FILTER_VALIDATE_INT);
        if ($from === false || $to === false || $from < 2000 || $from > $to || $to - $from > 30) {
            $this->error('Use an ordered season range from 2000 onward, at most 30 years apart.');

            return self::INVALID;
        }
        $data = $dataset->load($from, $to);
        $report = $validator->evaluate($data['rows']);
        $report['scope'] = ['from_season' => $from, 'to_season' => $to, 'games_seen' => $data['games_seen']];
        $report['dataset_exclusions'] = $data['excluded'];
        $report['line_policy'] = 'Frozen observed line, not necessarily closing or freshly fetched; not wager ROI.';
        if (! $this->option('detailed')) {
            unset($report['predictions']);
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('NFL spread models: '.$report['accepted_games'].' eligible games');
            $this->table(['Variant', 'Games', 'W-L-P', 'MAE', 'Early W-L-P', 'Probability sample'], collect($report['reports'])->map(function ($buckets, $variant) {
                $all = $buckets['all'] ?? [];
                $early = $buckets['weeks_1_4'] ?? [];
                $record = fn ($r) => isset($r['wins']) ? "{$r['wins']}-{$r['losses']}-{$r['pushes']}" : 'N/A';

                return [$variant, $all['games'] ?? 0, $record($all), isset($all['mae']) ? round($all['mae'], 3) : 'N/A', $record($early), $all['probability_games'] ?? 0];
            })->values()->all());
            $this->warn($report['note']);
            $this->line('Exclusions: '.json_encode([$data['excluded'], $report['excluded']]));
            $this->line($report['line_policy']);
        }

        return $report['accepted_games'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}
