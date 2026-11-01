<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\UpdateLivePrediction;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Services\Predictions\Basketball\BasketballBacktestForecastSource;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BacktestLiveFromPlaysCommand extends Command
{
    protected $signature = 'cbb:backtest-live-from-plays
        {--forecast-source= : canonical or legacy (defaults to the active CBB source)}
        {--season= : Limit to season}
        {--limit=50 : Limit number of final games}
        {--states-per-game=3 : Number of play snapshots per game to test}
        {--detailed : Show sample rows}';

    protected $description = 'Backtest CBB live predictions by replaying historical states from cbb_plays';

    public function handle(UpdateLivePrediction $updater): int
    {
        $season = $this->option('season') ? (int) $this->option('season') : null;
        $limit = max(1, (int) $this->option('limit'));
        $statesPerGame = max(1, (int) $this->option('states-per-game'));

        try {
            $source = app(BasketballBacktestForecastSource::class)->name('cbb', $this->option('forecast-source'));
        } catch (\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        if ($source === 'canonical') {
            $games = $this->canonicalGames($season, $limit);
            $this->line('Forecast source: canonical pregame inputs. Retrospective preview uses current live formulas and possession context; grants no calibration or betting approval.');
        } else {
            $games = Game::query()
                ->with(['prediction', 'homeTeam', 'awayTeam'])
                ->where('status', 'STATUS_FINAL')
                ->whereHas('plays')
                ->whereHas('prediction')
                ->when($season !== null, fn ($query) => $query->where('season', $season))
                ->orderByDesc('game_date')
                ->limit($limit)
                ->get()->map(fn ($game) => (object) ['game' => $game, 'baseline' => $game->prediction]);

        }

        if ($games->isEmpty()) {
            $this->warn('No final games with plays and predictions found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($games as $entry) {
            $game = $entry->game;
            $plays = Play::query()
                ->where('game_id', $game->id)
                ->whereNotNull('home_score')
                ->whereNotNull('away_score')
                ->orderBy('sequence_number')
                ->get();

            if ($plays->count() < $statesPerGame) {
                continue;
            }

            $indices = $this->sampleIndices($plays->count(), $statesPerGame);
            foreach ($indices as $index) {
                $play = $plays[$index];
                $snapshot = $updater->previewState(
                    $game,
                    $entry->baseline,
                    (int) ($play->period ?? 1),
                    $play->clock,
                    (int) ($play->home_score ?? 0),
                    (int) ($play->away_score ?? 0)
                );

                if ($snapshot === null) {
                    continue;
                }

                $actualMargin = (int) (($game->home_score ?? 0) - ($game->away_score ?? 0));
                $actualTotal = (int) (($game->home_score ?? 0) + ($game->away_score ?? 0));
                $actualWinner = $actualMargin > 0 ? 1.0 : 0.0;

                $rows[] = [
                    'game' => ($game->awayTeam?->abbreviation ?? '?').' @ '.($game->homeTeam?->abbreviation ?? '?'),
                    'seconds_remaining' => $snapshot['live_seconds_remaining'],
                    'win_probability' => $snapshot['live_win_probability'],
                    'actual_winner' => $actualWinner,
                    'spread_error' => abs($snapshot['live_predicted_spread'] - $actualMargin),
                    'total_error' => abs($snapshot['live_predicted_total'] - $actualTotal),
                ];
            }
        }

        if ($rows === []) {
            $this->warn('No playable snapshots found from the selected games.');

            return self::SUCCESS;
        }

        $count = count($rows);
        $winnerAccuracy = collect($rows)->filter(function (array $row) {
            return ($row['win_probability'] >= 0.5 ? 1.0 : 0.0) === $row['actual_winner'];
        })->count() / $count;
        $spreadMae = collect($rows)->avg('spread_error');
        $totalMae = collect($rows)->avg('total_error');

        $this->table(['Metric', 'Value'], [
            ['Snapshots', $count],
            ['Winner Accuracy', number_format($winnerAccuracy * 100, 1).'%'],
            ['Live Spread MAE', number_format((float) $spreadMae, 2)],
            ['Live Total MAE', number_format((float) $totalMae, 2)],
        ]);

        if ($this->option('detailed')) {
            $this->newLine();
            $this->table(
                ['Game', 'Secs Left', 'WP', 'Actual Winner', 'Spread Error', 'Total Error'],
                collect($rows)->take(20)->map(fn (array $row) => [
                    $row['game'],
                    $row['seconds_remaining'],
                    number_format($row['win_probability'] * 100, 1).'%',
                    $row['actual_winner'] > 0.5 ? 'HOME' : 'AWAY',
                    number_format($row['spread_error'], 1),
                    number_format($row['total_error'], 1),
                ])
            );
        }

        return self::SUCCESS;
    }

    private function canonicalGames(?int $season, int $limit): Collection
    {
        return app(BasketballBacktestForecastSource::class)->forCbbPlayReplay($season, $limit)
            ->map(fn ($row) => (object) ['game' => $row->game, 'baseline' => $row]);
    }

    /**
     * @return array<int, int>
     */
    private function sampleIndices(int $count, int $statesPerGame): array
    {
        if ($statesPerGame === 1) {
            return [max(0, $count - 1)];
        }

        $indices = [];
        for ($i = 1; $i <= $statesPerGame; $i++) {
            $indices[] = min($count - 1, max(0, (int) floor(($count * $i) / ($statesPerGame + 1))));
        }

        return array_values(array_unique($indices));
    }
}
