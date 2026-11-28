<?php

namespace App\Console\Commands\CFB;

use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbDataReadiness;
use App\Services\CFB\Data\CfbScoringLedger;
use App\Services\CFB\PlayEpaDataService;
use App\Services\CFB\TrueEpaCalculator;
use App\Services\Epa\StateBaselineService;
use App\Support\CFB\ObservedTerminalBoundary;
use App\Support\ScoreObservation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

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
        $acceptedGames = $excludedGames = 0;
        foreach (Game::where('season', $source)->where('status', 'STATUS_FINAL')->lazyById(50) as $game) {
            if (! app(CfbDataReadiness::class)->forGame($game)['ready']) {
                $excludedGames++;

                continue;
            }
            $revision = app(CfbDataReadiness::class)->acceptedCheck($game, 'plays');
            if (! $revision) {
                $excludedGames++;

                continue;
            }
            try {
                $plays = $game->plays()->where('source_revision', $revision->source_hash)->orderBy('sequence_number')->orderBy('id')->get();
                $plays = app(CfbScoringLedger::class)->normalize($game, $plays);
            } catch (\Throwable $error) {
                $excludedGames++;
                $this->warn("Game {$game->id} excluded: {$error->getMessage()}");

                continue;
            }
            $acceptedGames++;
            foreach ($plays as $i => $play) {
                if (! $eligibility->isEpaEligiblePlay($play) || ! $play->possession_team_id || $play->period > 4) {
                    continue;
                }
                $value = $this->observedFutureValue($plays, $i, (int) $play->possession_team_id, (int) $game->home_team_id, (int) $game->away_team_id);
                if ($value === null) {
                    continue;
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
        $this->line(json_encode(['states' => count($rows), 'source_season' => $source, 'season' => $season, 'accepted_games' => $acceptedGames, 'excluded_games' => $excludedGames]));

        return self::SUCCESS;
    }

    /** Pure observation admission; no training or artifact write occurs here. */
    private function observedFutureValue(Collection $plays, int $index, int $offense, int $homeId, int $awayId): ?float
    {
        if ($homeId <= 0 || $awayId <= 0 || $homeId === $awayId || $index <= 0 || ! in_array($offense, [$homeId, $awayId], true)) {
            return null;
        }
        $home = ScoreObservation::known($plays[$index - 1]->home_score ?? null);
        $away = ScoreObservation::known($plays[$index - 1]->away_score ?? null);
        if ($home === null || $away === null) {
            return null;
        }
        $period = $plays[$index]->period;
        for ($next = $index; $next < count($plays); $next++) {
            if (($period <= 2) !== ($plays[$next]->period <= 2) || $plays[$next]->period > 4) {
                return null;
            }
            $nextHome = ScoreObservation::known($plays[$next]->home_score ?? null);
            $nextAway = ScoreObservation::known($plays[$next]->away_score ?? null);
            if ($nextHome === null || $nextAway === null) {
                return null;
            }
            $hd = $nextHome - $home;
            $ad = $nextAway - $away;
            if ($hd || $ad) {
                return (float) ($offense === $homeId ? $hd - $ad : $ad - $hd);
            }
            if (ObservedTerminalBoundary::forHalf($plays[$next], (int) $period, $homeId, $awayId)) {
                return 0.0;
            }
        }

        return null;
    }
}
