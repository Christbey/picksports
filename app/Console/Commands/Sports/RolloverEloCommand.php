<?php

namespace App\Console\Commands\Sports;

use App\Exceptions\EloAdmissionRefused;
use App\Services\Sports\Elo\EloSeasonRollover;
use Illuminate\Console\Command;
use Throwable;

class RolloverEloCommand extends Command
{
    protected $signature = 'sports:rollover-elo
        {sport : Explicit nfl, nba, mlb, cbb or wcbb}
        {--season= : Explicit immediately following admitted season}
        {--team-factor= : Explicit finite factor from zero to one; zero carries ratings forward}
        {--pitcher-factor= : Explicit MLB pitcher factor; MLB requires both entity factors}
        {--apply : Apply the exact reviewed current plan; default is dry run}
        {--fingerprint= : Exact current dry-run fingerprint required for apply}';

    protected $description = 'Inspect or atomically apply a bounded admitted Elo season transition';

    public function handle(EloSeasonRollover $rollover): int
    {
        $sport = (string) $this->argument('sport');
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        if ($season === false || $this->option('team-factor') === null
            || ($sport === 'mlb' && $this->option('pitcher-factor') === null)
            || ($sport !== 'mlb' && $this->option('pitcher-factor') !== null)
            || ($this->option('apply') && preg_match('/\A[a-f0-9]{64}\z/', (string) $this->option('fingerprint')) !== 1)) {
            $this->error('Select an explicit supported season and factor set; apply also requires the exact current dry-run fingerprint.');

            return self::FAILURE;
        }
        $factors = ['team' => $this->option('team-factor')];
        if ($sport === 'mlb') {
            $factors['pitcher'] = $this->option('pitcher-factor');
        }
        try {
            $result = $this->option('apply')
                ? $rollover->execute($sport, $season, $factors, (string) $this->option('fingerprint'))
                : $rollover->inspect($sport, $season, $factors);
        } catch (EloAdmissionRefused $exception) {
            $this->error(json_encode($exception->context(), JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Elo rollover refused. Inspect current admission, source, target, factor and season evidence; no partial transition was published.');

            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
