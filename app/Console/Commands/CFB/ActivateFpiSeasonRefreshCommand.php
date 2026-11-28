<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\FpiRating;
use App\Services\CollegeFootballData\Fpi\FpiSeasonCoordinator;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\Sports\ExplicitOffsetInstant;
use Illuminate\Console\Command;
use Throwable;

class ActivateFpiSeasonRefreshCommand extends Command
{
    protected $signature = 'cfb:activate-fpi-refresh
        {--season= : Explicit season}
        {--target= : Independently reviewed write-target fingerprint}
        {--operator= : Responsible operator}
        {--reason= : Activation reason}
        {--drained-at= : Explicit-offset instant when prior writers drained}
        {--drain-reference= : Reviewed drain evidence reference}
        {--source-review-reference= : Reviewed provider-size, mapping and affiliation contract reference}
        {--apply : Persist one empty season head after explicit review}';

    protected $description = 'Preview or explicitly record reviewed CFBD FPI season activation without provider IO';

    public function handle(FpiSeasonCoordinator $coordinator, EloWriteTargetIdentity $targets): int
    {
        $connection = (new FpiRating)->getConnection();
        try {
            $season = filter_var($this->option('season'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($season === false) {
                $this->error('An explicit supported FPI season is required.');

                return self::FAILURE;
            }
            $actual = $targets->describe($connection)['fingerprint'];
            $this->line('FPI season '.$season.' current write target: '.$actual);
            if (! $this->option('apply')) {
                $this->warn('Preview only; no activation, provider fetch or metrics work performed.');

                return self::SUCCESS;
            }
            $drained = ExplicitOffsetInstant::parse($this->option('drained-at'));
            if ($drained === null) {
                $this->error('A real explicit-offset drain instant is required.');

                return self::FAILURE;
            }
            $coordinator->activate($connection, $season, (string) $this->option('target'),
                (string) $this->option('operator'), (string) $this->option('reason'),
                (string) $this->option('drain-reference'), (string) $this->option('source-review-reference'), $drained);
            $this->info('Recorded the reviewed empty FPI season head; this records statements, not independent proof of drain or provider compatibility.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('FPI activation refused: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
