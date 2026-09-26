<?php

namespace App\Console\Commands\CFB;

use App\Actions\OddsApi\CFB\SyncPlayerPropsForGames;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;

class SyncPlayerPropsCommand extends Command
{
    protected $signature = 'cfb:sync-player-props {--date= : Game date in America/Chicago; defaults to today} {--game= : Limit to an internal game ID} {--no-analyze : Import quotes without scoring} {--prepare : Refresh rosters and backfill recent stats before scoring}';

    protected $description = 'Fetch college football and playoff yardage props from FanDuel and DraftKings, then analyze eligible lines';

    public function handle(SyncPlayerPropsForGames $sync): int
    {
        $input = ['date' => $this->option('date'), 'game' => $this->option('game')];
        $validator = Validator::make($input, ['date' => 'nullable|date_format:Y-m-d', 'game' => 'nullable|integer|min:1']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
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
