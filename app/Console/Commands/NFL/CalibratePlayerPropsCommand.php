<?php

namespace App\Console\Commands\NFL;

use App\Services\BettingRecommendations\NflPropProbabilityCalibration;
use App\Services\BettingRecommendations\NflPropProjectionBacktest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CalibratePlayerPropsCommand extends Command
{
    protected $signature = 'nfl:calibrate-player-props {--season= : Required season} {--through= : Last completed game date} {--validation-from= : Required fixed first evaluation date} {--market= : Optional market}';

    protected $description = 'Read-only matchup replay, chronological logistic calibration and price evaluation; never promotes a model';

    public function handle(NflPropProjectionBacktest $replay, NflPropProbabilityCalibration $calibration): int
    {
        $season = filter_var($this->option('season'), FILTER_VALIDATE_INT);
        $through = $this->option('through') ?: now('America/Chicago')->toDateString();
        $validation = $this->option('validation-from');
        try {
            foreach ([$through, $validation] as $date) {
                if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
                    throw new \InvalidArgumentException;
                }
            }
            if (! $season || $season < 2000 || $season > now()->year || $through > now('America/Chicago')->toDateString() || $validation > $through) {
                throw new \InvalidArgumentException;
            }
        } catch (Throwable) {
            $this->error('Supply a valid season, through date no later than today, and fixed validation-from date no later than through.');

            return self::FAILURE;
        }
        $data = $replay->run($season, $through, 3, null, $this->option('market'), true, true);
        $result = $calibration->report($data['samples'], $validation);
        $this->line(json_encode(['season' => $season, 'through' => $through, 'coverage' => $data['coverage'], ...$result], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
