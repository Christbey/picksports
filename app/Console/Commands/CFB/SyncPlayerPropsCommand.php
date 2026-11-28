<?php

namespace App\Console\Commands\CFB;

use App\Actions\OddsApi\CFB\SyncPlayerPropsForGames;
use App\Jobs\RunScheduledCommand;
use App\Models\CFB\Game;
use App\Services\BettingRecommendations\CfbPropEligibility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;

class SyncPlayerPropsCommand extends Command
{
    protected $signature = 'cfb:sync-player-props {--date= : Game date in America/Chicago; defaults to today} {--game= : Limit to an internal game ID} {--no-analyze : Import quotes without scoring} {--prepare : Refresh rosters and backfill recent stats before scoring} {--queued : Dispatch one preparation job per upcoming game}';

    protected $description = 'Fetch college football and playoff yardage props from FanDuel and DraftKings, then analyze eligible lines';

    public function handle(SyncPlayerPropsForGames $sync): int
    {
        $input = ['date' => $this->option('date'), 'game' => $this->option('game')];
        $validator = Validator::make($input, ['date' => 'nullable|date_format:Y-m-d', 'game' => 'nullable|integer|min:1']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        if ($this->option('queued')) {
            $date = $input['date'] ?? now('America/Chicago')->toDateString();
            $games = CfbPropEligibility::onDate(
                Game::where('status', 'STATUS_SCHEDULED')->whereNotNull('odds_api_event_id'), $date,
            )->when($input['game'], fn ($query, $id) => $query->whereKey($id))
                ->orderBy('game_date')->orderBy('game_time')->orderBy('id')
                ->get(['id', 'espn_event_id', 'game_date', 'game_time', 'kickoff_observation'])
                ->filter(fn (Game $game): bool => CfbPropEligibility::kickoff($game)?->isFuture() === true);
            $flags = ($this->option('prepare') ? ' --prepare' : '').($this->option('no-analyze') ? ' --no-analyze' : '');
            foreach ($games as $game) {
                RunScheduledCommand::dispatch(
                    "cfb:sync-player-props --date={$date} --game={$game->id}{$flags}",
                    'CFB: Sync and Analyze Player Props Game',
                );
            }
            $this->info("Queued CFB player prop refreshes for {$games->count()} upcoming game(s).");

            return self::SUCCESS;
        }

        $counts = $sync->execute($input['date'], $input['game'] ? (int) $input['game'] : null, ! $this->option('no-analyze') && ! $this->option('prepare'));
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        if ($this->option('prepare') && ! $this->option('no-analyze')) {
            // Keep preparation memory separate from the quote-import state.
            // A separate process also gives the preparation stage a bounded runtime and visible errors.
            $command = [PHP_BINARY, '-d', 'memory_limit=512M', base_path('artisan'), 'cfb:prepare-player-props'];
            foreach (array_filter($input, fn ($value) => $value !== null) as $key => $value) {
                $command[] = '--'.$key.'='.$value;
            }
            $prepared = Process::path(base_path())->timeout(900)->run($command);
            $this->output->write($prepared->output());
            if ($prepared->failed()) {
                $this->error('CFB prop preparation failed (exit '.$prepared->exitCode().'); quotes were imported but the board is incomplete.');

                // Avoid emitting provider exception URLs which may contain credentials.
                return self::FAILURE;
            }
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
