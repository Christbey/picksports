<?php

namespace App\Console\Commands\Sports;

use App\Services\NBA\TrueEpaCalculator;
use Illuminate\Console\Command;

abstract class AbstractBasketballCalculatePlayEpaCommand extends Command
{
    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected function gameModelClass(): string;

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected function playModelClass(): string;

    /**
     * @return array<int,string>
     */
    protected function playSelectColumns(): array
    {
        return [
            'id',
            'game_id',
            'period',
            'clock',
            'play_type',
            'play_text',
            'home_score',
            'away_score',
            'possession_team_id',
            'true_epa',
        ];
    }

    public function handle(TrueEpaCalculator $calculator): int
    {
        $season = $this->option('season');
        $gameId = $this->option('game_id');
        $limit = max(0, (int) $this->option('limit'));
        $rebuild = (bool) $this->option('rebuild');
        $dryRun = (bool) $this->option('dry-run');

        $gameModel = $this->gameModelClass();
        $playModel = $this->playModelClass();

        $query = $gameModel::query()
            ->whereHas('plays')
            ->orderByDesc('game_date');

        if ($season !== null && $season !== '') {
            $query->where('season', (int) $season);
        }

        if ($gameId !== null && $gameId !== '') {
            $query->where('id', (int) $gameId);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $games = $query->get(['id', 'home_team_id', 'away_team_id']);
        if ($games->isEmpty()) {
            $this->warn('No matching games found.');

            return self::SUCCESS;
        }

        $this->info("Calculating true EPA for {$games->count()} game(s)...".($dryRun ? ' [dry-run]' : ''));
        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $playsUpdated = 0;
        $eligibleUpdated = 0;

        foreach ($games as $game) {
            $plays = $playModel::query()
                ->where('game_id', $game->id)
                ->orderBy('sequence_number')
                ->orderBy('id')
                ->get($this->playSelectColumns());

            if ($plays->isEmpty()) {
                $bar->advance();
                continue;
            }

            $results = $calculator->calculateForGame($plays, (int) $game->home_team_id, (int) $game->away_team_id);

            foreach ($plays as $play) {
                $result = $results[(int) $play->id] ?? null;
                if ($result === null) {
                    continue;
                }

                $payload = [
                    'is_epa_eligible' => $result['eligible'],
                    'expected_points_before' => $result['ep_before'],
                    'expected_points_after' => $result['ep_after'],
                    'true_epa' => $result['epa'],
                ];

                if (! $rebuild && $play->true_epa !== null) {
                    continue;
                }

                $playsUpdated++;
                if ($result['eligible']) {
                    $eligibleUpdated++;
                }

                if (! $dryRun) {
                    $playModel::query()->where('id', $play->id)->update($payload);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Dry run complete. Plays to update: {$playsUpdated}; eligible: {$eligibleUpdated}");
        } else {
            $this->info("EPA calculation complete. Plays updated: {$playsUpdated}; eligible: {$eligibleUpdated}");
        }

        return self::SUCCESS;
    }
}
