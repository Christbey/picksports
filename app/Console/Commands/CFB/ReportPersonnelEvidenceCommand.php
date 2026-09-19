<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Predictions\CfbPersonnelEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReportPersonnelEvidenceCommand extends Command
{
    protected $signature = 'cfb:report-personnel-evidence {--season=} {--days-forward=8}';

    protected $description = 'Read current personnel provenance and missing coverage for upcoming CFB teams';

    public function handle(CfbPersonnelEvidence $evidence): int
    {
        $games = Game::with(['sportEvent', 'homeTeam', 'awayTeam'])->where('season', $this->option('season') ?: config('cfb.season.default'))
            ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())
                ->where('starts_at', '<=', now()->addDays(max(1, (int) $this->option('days-forward')))))->get();
        $rows = [];
        foreach ($games as $game) {
            foreach ([$game->homeTeam, $game->awayTeam] as $team) {
                if ($team && ! isset($rows[$team->id])) {
                    $value = $evidence->forTeam($game, $team->id, CarbonImmutable::now(), $game->sportEvent->starts_at->toImmutable());
                    $rows[$team->id] = ['team_id' => $team->id, 'team' => $team->school,
                        'coverage_complete' => $value['coverage_complete'], 'missing_components' => $value['missing_components'],
                        'components' => collect($value['components'])->map(fn ($c) => ['status' => $c['status'], 'observed_at' => $c['observed_at']])->all()];
                }
            }
        }
        $this->line(json_encode(['as_of' => now()->toIso8601String(), 'teams' => count($rows),
            'complete' => count(array_filter($rows, fn ($r) => $r['coverage_complete'])), 'rows' => array_values($rows)], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
