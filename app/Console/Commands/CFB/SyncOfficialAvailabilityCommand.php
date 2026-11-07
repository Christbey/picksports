<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Availability\AvailabilityEvidence;
use App\Services\CFB\Availability\OfficialAvailabilityIngestor;
use Illuminate\Console\Command;

class SyncOfficialAvailabilityCommand extends Command
{
    protected $signature = 'cfb:sync-official-availability {--season=} {--days-forward=8} {--game=}';

    protected $description = 'Supplement ESPN with official conference reports and disclose missing availability coverage';

    public function handle(OfficialAvailabilityIngestor $ingestor, AvailabilityEvidence $evidence): int
    {
        $days = filter_var($this->option('days-forward'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 8) {
            $this->error('days-forward must be between 1 and 8.');

            return self::FAILURE;
        }
        $games = Game::query()->with(['sportEvent', 'homeTeam', 'awayTeam'])->where('season', (int) ($this->option('season') ?: now('America/Chicago')->year))
            ->when($this->option('game'), fn ($q, $v) => $q->whereKey((int) $v))
            ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays($days)))
            ->get();
        if ($games->isEmpty()) {
            $this->line(json_encode(['games' => 0, 'reports' => 0]));

            return self::SUCCESS;
        }
        $summary = $ingestor->sync($games);
        $summary['games'] = $games->count();
        $summary['coverage'] = $games->map(fn ($g) => ['game_id' => $g->id,
            'home' => $evidence->forGame($g, $g->home_team_id)['coverage'],
            'away' => $evidence->forGame($g, $g->away_team_id)['coverage']])->all();
        $summary['uncovered_teams'] = collect($summary['coverage'])->sum(fn ($row) => (int) ! $row['home']['complete'] + (int) ! $row['away']['complete']);
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return $summary['source_failures'] === [] && $summary['partial_reports'] === 0 && $summary['uncovered_teams'] === 0 ? self::SUCCESS : 2;
    }
}
