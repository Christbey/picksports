<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\PlayEpaDataService;
use App\Services\CFB\TrueEpaCalculator;
use App\Services\Epa\StateBaselineService;
use Illuminate\Console\Command;

class TrainEpaBaselineCommand extends Command
{
    protected $signature = 'cfb:train-epa-baseline {--season=} {--source-season=} {--minimum-state-samples=20}';

    protected $description = 'Fit CFB state values from a strictly earlier season; no same-game fallback';

    public function handle(TrueEpaCalculator $calculator, PlayEpaDataService $eligibility, StateBaselineService $baselines): int
    {
        $season = (int) $this->option('season');
        $source = (int) $this->option('source-season');
        if ($source < 2000 || $source >= $season || (int) $this->option('minimum-state-samples') < 1) {
            return self::FAILURE;
        }
        $buckets = [];
        foreach (Game::where('season', $source)->where('status', 'STATUS_FINAL')->lazyById(50) as $game) {
            if (! app(CfbDataReadiness::class)->forGame($game)['ready']) {
                continue;
            }
            $plays = $game->plays()->orderBy('sequence_number')->get();
            foreach ($plays as $i => $play) {
                if (! $eligibility->isEpaEligiblePlay($play) || ! $play->possession_team_id || $play->period > 4) {
                    continue;
                }
                $startHome = $i ? $plays[$i - 1]->home_score : 0;
                $startAway = $i ? $plays[$i - 1]->away_score : 0;
                $value = 0;
                for ($j = $i; $j < count($plays); $j++) {
                    if (($play->period <= 2) !== ($plays[$j]->period <= 2) || $plays[$j]->period > 4) {
                        break;
                    }
                    $hd = $plays[$j]->home_score - $startHome;
                    $ad = $plays[$j]->away_score - $startAway;
                    if ($hd || $ad) {
                        $value = $play->possession_team_id === $game->home_team_id ? $hd - $ad : $ad - $hd;
                        break;
                    }
                }
                $key = $calculator->stateKeyForPlay($play);
                $buckets[$key][] = $value;
            }
        }
        $rows = [];
        foreach ($buckets as $key => $values) {
            if (count($values) >= (int) $this->option('minimum-state-samples')) {
                $rows[] = ['state_key' => $key, 'expected_points' => array_sum($values) / count($values), 'sample_size' => count($values)];
            }
        }
        if (! $rows) {
            $this->error('No eligible past-season states; existing artifact retained');

            return self::FAILURE;
        }
        $baselines->replaceSeasonBaseline('cfb', $season, $source, $rows);
        $this->line(json_encode(['states' => count($rows), 'source_season' => $source, 'season' => $season]));

        return self::SUCCESS;
    }
}
