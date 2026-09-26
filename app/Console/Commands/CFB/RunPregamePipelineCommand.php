<?php

namespace App\Console\Commands\CFB;

use App\Actions\ESPN\CFB\SyncGameDetails;
use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Actions\ESPN\CFB\SyncPlayers;
use App\Models\CanonicalPrediction;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\TeamMetric;
use App\Models\MarketQuote;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbDriveBuilder;
use App\Services\CFB\Data\CfbPipelineStage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class RunPregamePipelineCommand extends Command
{
    protected $signature = 'cfb:run-pregame-pipeline {--season=} {--days-forward=2} {--game=} {--resume} {--dry-run}';

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
                ->when($this->option('game'), fn ($q, $v) => $q->whereKey($v))
                ->whereIn('status', ['STATUS_SCHEDULED', 'STATUS_DELAYED'])
                ->whereHas('sportEvent', fn ($q) => $q->where('starts_at', '>', now())->where('starts_at', '<=', now()->addDays((int) $days)))
                ->get();
            if ($games->isEmpty()) {
                $this->info('No upcoming CFB games in the pipeline horizon.');

                return self::SUCCESS;
            }
            if ($this->option('dry-run')) {
                $this->line(json_encode(['game_ids' => $games->modelKeys(), 'stages' => ['repair', 'ratings', 'metrics', 'forecast', 'price']]));

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
            $personnelKey = 'cfb:personnel:v3:'.$season.':'.now('America/Chicago')->toDateString();
            if (! Cache::has($personnelKey)) {
                if ($this->call('cfb:sync-preseason-team-signals', ['--season' => (int) $season, '--include-coaches' => true, '--include-quarterbacks' => true, '--require-data' => true]) !== self::SUCCESS) {
                    $this->error('Personnel source refresh failed; generation stopped.');

                    return self::FAILURE;
                }
                Cache::put($personnelKey, true, now()->addHours(24));
            }
            $teams = $games->flatMap(fn ($g) => [$g->homeTeam, $g->awayTeam])->filter()->unique('id');
            $teamFailures = [];
            foreach ($teams as $team) {
                try {
                    $rosterKey = 'cfb:prop-roster:'.$team->id.':'.now('America/Chicago')->toDateString();
                    if (! Cache::has($rosterKey)) {
                        if ($players->execute((string) $team->espn_id) === 0) {
                            $this->warn("Roster refresh failed for team {$team->id}; affected games remain pending.");
                            $teamFailures[] = $team->id;

                            continue;
                        }
                        Cache::put($rosterKey, true, now()->addHours(24));
                    }
                    // Direct action: do not generate before queued injury work finishes.
                    $injuries->execute((string) $team->espn_id);
                    if (! $injuries->lastSyncReliable()) {
                        $this->warn("Injury feed unavailable for team {$team->id}; affected games remain pending.");
                        $teamFailures[] = $team->id;
                    }
                } catch (\Throwable $error) {
                    report($error);
                    $teamFailures[] = $team->id;
                }
            }
            $historyFailures = [];
            $history = Game::where('season', $season)->where('status', 'STATUS_FINAL')
                ->where(fn ($q) => $q->whereIn('home_team_id', $teams->pluck('id')->all())->orWhereIn('away_team_id', $teams->pluck('id')->all()))->get();
            foreach ($history as $past) {
                $readiness = app(CfbDataReadiness::class);
                if (! $readiness->forGame($past)['ready']) {
                    app(CfbPipelineStage::class)->run('game:'.$past->id, 'repair', ['quality' => $readiness->forGame($past)], function () use ($past, $readiness) {
                        app(SyncGameDetails::class)->execute((string) $past->espn_event_id);
                        $quality = $readiness->forGame($past);

                        return ['state' => $quality['ready'] ? 'complete' : 'partial', 'quality' => $quality];
                    }, false);
                }
                if (! $readiness->forGame($past)['ready']) {
                    $historyFailures[] = $past->home_team_id;
                    $historyFailures[] = $past->away_team_id;
                }
            }
            foreach ($history as $past) {
                if (! in_array($past->home_team_id, $historyFailures, true) && ! in_array($past->away_team_id, $historyFailures, true)) {
                    $processed = app(CfbPipelineStage::class)->run('game:'.$past->id, 'normalize', app(CfbDataReadiness::class)->forGame($past), function () use ($past) {
                        $code = $this->call('cfb:calculate-play-epa', ['--game_id' => $past->id]);
                        $drives = app(CfbDriveBuilder::class)->build($past, true);

                        return ['state' => $code === self::SUCCESS && $drives['state'] === 'complete' ? 'complete' : 'failed', 'drives' => $drives];
                    }, false);
                    if ($processed['state'] !== 'complete') {
                        $historyFailures[] = $past->home_team_id;
                        $historyFailures[] = $past->away_team_id;
                    }
                }
            }
            if ($this->call('cfb:calculate-elo', ['--season' => (int) $season]) !== self::SUCCESS) {
                $this->error('Elo integrity/update failed; generation stopped.');

                return self::FAILURE;
            }
            // Recompute after Elo and injury updates, including games finalized since the daily refresh.
            if ($this->call('cfb:calculate-team-metrics', ['--season' => (int) $season]) !== self::SUCCESS) {
                $this->error('Team metric refresh failed; generation stopped.');

                return self::FAILURE;
            }
            if ($this->call('cfb:sync-odds', ['--days' => (int) $days]) !== self::SUCCESS) {
                $this->warn('Partial odds coverage; valid forecasts continue and unpriced markets remain unavailable.');
            }

            // Weather is optional evidence. Provider unavailability must not block a forecast.
            try {
                if ($this->call('cfb:sync-game-weather', ['--season' => (int) $season,
                    '--from-date' => now()->toDateString(), '--days-forward' => (int) $days, '--force' => true]) !== self::SUCCESS) {
                    $this->warn('Weather refresh unavailable; missing weather remains unknown.');
                }
            } catch (\Throwable $error) {
                report($error);
                $this->warn('Weather refresh unavailable; missing weather remains unknown.');
            }

            $results = [];
            foreach ($games as $game) {
                $inputs = ['game_updated_at' => $game->updated_at?->toISOString(),
                    'history' => GameDataCheck::whereIn('game_id', $history->modelKeys())->whereNotNull('accepted_at')->pluck('source_hash')->sort()->values()->all(),
                    // A new hourly source refresh needs a new immutable forecast; --resume only skips completed work within that refresh window.
                    'refresh_window' => now()->format('Y-m-d-H'),
                    'team_revisions' => TeamMetric::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at'),
                    'quote_revision' => MarketQuote::where('sport', 'cfb')->where('game_id', $game->id)->max('id'),
                    'injury_revision' => PlayerInjury::whereIn('team_id', [$game->home_team_id, $game->away_team_id])->max('updated_at')];
                $results[$game->id] = app(CfbPipelineStage::class)->run('game:'.$game->id, 'forecast', $inputs, function () use ($game, $historyFailures, $teamFailures) {
                    if (array_intersect([$game->home_team_id, $game->away_team_id], $teamFailures)) {
                        return ['state' => 'blocked', 'reason' => 'unreliable_team_personnel'];
                    }
                    if (array_intersect([$game->home_team_id, $game->away_team_id], $historyFailures)) {
                        return ['state' => 'blocked', 'reason' => 'incomplete_historical_sources'];
                    }
                    if (! $game->sportEvent?->starts_at?->isFuture()) {
                        return ['state' => 'blocked', 'reason' => 'kickoff_passed'];
                    }
                    $started = now();
                    $code = $this->call('cfb:generate-canonical-predictions', ['--game' => $game->id]);

                    $output = CanonicalPrediction::where('sport_event_id', $game->sport_event_id)->where('sport', 'cfb')->where('phase', 'pregame')
                        ->where('publication_state', 'published')->where('generated_at', '>=', $started)
                        ->where('published_at', '<', $game->sportEvent->starts_at)->whereHas('markets')->latest('revision')->first();
                    $game->unsetRelations();
                    gc_collect_cycles();

                    return ['state' => $code === self::SUCCESS && $output ? 'complete' : 'failed', 'code' => $code,
                        'prediction_id' => $output?->public_id, 'reason' => $output ? null : 'no_fresh_published_forecast'];
                }, (bool) $this->option('resume'));
            }
            $this->line(json_encode(['games' => $results], JSON_THROW_ON_ERROR));

            return collect($results)->every(fn ($r) => $r['state'] === 'complete') ? self::SUCCESS : 2;
        } finally {
            $lock->release();
        }
    }
}
