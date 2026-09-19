<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Models\CFB\Game;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'cfb:run-pregame-pipeline {--season=} {--days-forward=2}';

    protected $description = 'Refresh upcoming CFB injuries and odds before generating immutable forecasts';

    public function handle(SyncPlayerInjuries $injuries, SyncPlayers $players): int
    {
        $season = $this->option('season') ?? config('cfb.season.default');
        $days = $this->option('days-forward');
        if (Validator::make(['season' => $season, 'days' => $days], [
            'season' => 'required|integer|min:2000|max:2100', 'days' => 'required|integer|min:1|max:8',
        ])->fails() || ! config('prediction_lifecycle.canonical_pipeline.cfb', false)) {
            $this->error('CFB pipeline requires valid season/horizon and enabled canonical generation.');

            return self::FAILURE;
        }
        $lock = Cache::lock('cfb:pregame-pipeline', 3600);
        if (! $lock->get()) {
            $this->warn('CFB pregame pipeline is already running.');

            return self::FAILURE;
        }
        try {
            $games = Game::with(['homeTeam', 'awayTeam'])->where('season', $season)
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
                ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays((int) $days)))
                ->get();
            if ($games->isEmpty()) {
                $this->info('No upcoming CFB games in the pipeline horizon.');

                return self::SUCCESS;
            }
            $ratingsKey = 'cfb:pregame-ratings:v2:'.$season.':'.now('America/Chicago')->toDateString();
            if (! Cache::has($ratingsKey)) {
                if ($this->call('cfb:sync-season-affiliations', ['--season' => (int) $season]) !== self::SUCCESS) {
                    $this->error('Season membership refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                if ($this->call('cfb:import-fpi', ['--season' => (int) $season,
                    '--week' => (int) $games->min('week'), '--require-data' => true,
                    '--require-complete' => true]) !== self::SUCCESS) {
                    $this->error('Rating/metric refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                Cache::put($ratingsKey, true, now()->addHours(24));
            }
            if ($this->call('cfb:calculate-elo', ['--season' => (int) $season]) !== self::SUCCESS) {
                $this->error('Elo integrity/update failed; generation stopped.');

                return self::FAILURE;
            }
            $teams = $games->flatMap(fn ($g) => [$g->homeTeam, $g->awayTeam])->filter()->unique('id');
            foreach ($teams as $team) {
                $rosterKey = 'cfb:prop-roster:'.$team->id.':'.now('America/Chicago')->toDateString();
                if (! Cache::has($rosterKey)) {
                    if ($players->execute((string) $team->espn_id) === 0) {
                        $this->error("Roster refresh failed for team {$team->id}; generation stopped.");

                        return self::FAILURE;
                    }
                    Cache::put($rosterKey, true, now()->addHours(24));
                }
                // Direct action: do not generate before queued injury work finishes.
                $injuries->execute((string) $team->espn_id);
                if (! $injuries->lastSyncReliable()) {
                    $this->error("Injury feed was unavailable or incomplete for team {$team->id}; generation stopped.");

                    return self::FAILURE;
                }
            }
            // Recompute after Elo and injury updates, including games finalized since the daily refresh.
            if ($this->call('cfb:calculate-team-metrics', ['--season' => (int) $season]) !== self::SUCCESS) {
                $this->error('Team metric refresh failed; generation stopped.');

                return self::FAILURE;
            }
            if ($this->call('cfb:sync-odds', ['--days' => (int) $days]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            return $this->call('cfb:generate-canonical-predictions', ['--season' => (int) $season, '--days-forward' => (int) $days]);
        } finally {
            $lock->release();
        }
    }
}
