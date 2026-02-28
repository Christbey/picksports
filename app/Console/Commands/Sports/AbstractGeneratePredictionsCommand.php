<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

abstract class AbstractGeneratePredictionsCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    protected const GENERATE_ACTION_CLASS = '';

    protected const GAME_MODEL_CLASS = '';

    protected const PREDICTION_MODEL_CLASS = '';

    /**
     * @var array<int, string>
     */
    protected const TEAM_NAME_FIELDS = [];

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    public function handle(): int
    {
        $generatePrediction = app($this->generateActionClass());
        $gameModel = $this->gameModelClass();

        if ($this->supportsGameOption() && ($gameId = $this->option('game'))) {
            $game = $gameModel::find($gameId);

            if (! $game) {
                $this->error("Game with ID {$gameId} not found.");

                return self::FAILURE;
            }

            $this->info("Generating prediction for game {$gameId}...");

            $prediction = $generatePrediction->execute($game);

            if (! $prediction) {
                $this->warn('Could not generate prediction (game may be completed or missing teams).');

                return self::SUCCESS;
            }

            $this->displayPrediction($prediction);

            return self::SUCCESS;
        }

        $query = $gameModel::query()
            ->where('status', '!=', 'STATUS_FINAL')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('id');

        $this->applyFilters($query);

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No upcoming games found matching the criteria.');

            return self::SUCCESS;
        }

        $this->info("Generating predictions for {$games->count()} games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $generated = 0;
        foreach ($games as $game) {
            $prediction = $generatePrediction->execute($game);
            if ($prediction) {
                $generated++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Predictions generated for {$generated} games.");

        $this->newLine();
        $this->info('Top 10 Predictions by Confidence:');

        $predictionModel = $this->predictionModelClass();
        $topPredictions = $predictionModel::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->orderBy('confidence_score', 'desc')
            ->limit(10)
            ->get();

        if ($topPredictions->isNotEmpty()) {
            $this->table(
                ['Game', 'Spread', 'Total', 'Win %', 'Confidence'],
                $topPredictions->map(fn ($pred) => $this->topPredictionRow($pred))
            );
        }

        return self::SUCCESS;
    }

    protected function applyFilters(Builder $query): void
    {
        if ($this->supportsSeasonOption() && ($season = $this->option('season'))) {
            $query->where('season', $season);
        }

        if ($this->supportsWeekOption() && ($week = $this->option('week'))) {
            $query->where('week', $week);
        }

        if ($this->supportsDateOption() && ($date = $this->option('date'))) {
            $this->applyDateFilter($query, $date);
        }
    }

    protected function applyDateFilter(Builder $query, string $date): void
    {
        $query->whereDate('game_date', $date);
    }

    protected function supportsGameOption(): bool
    {
        return true;
    }

    protected function supportsSeasonOption(): bool
    {
        return true;
    }

    protected function supportsWeekOption(): bool
    {
        return false;
    }

    protected function supportsDateOption(): bool
    {
        return true;
    }

    protected function buildSignature(): string
    {
        $segments = [
            $this->commandName(),
        ];

        if ($this->supportsSeasonOption()) {
            $segments[] = '{--season= : Generate predictions for a specific season}';
        }

        if ($this->supportsWeekOption()) {
            $segments[] = '{--week= : Generate predictions for games in a specific week}';
        }

        if ($this->supportsDateOption()) {
            $segments[] = '{--date= : Generate predictions for games on a specific date (YYYY-MM-DD)}';
        }

        if ($this->supportsGameOption()) {
            $segments[] = '{--game= : Generate prediction for a specific game ID}';
        }

        return implode("\n ", $segments);
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }

    protected function topPredictionRow(mixed $prediction): array
    {
        $game = $prediction->game;
        $homeTeam = $this->formatTeamName($game->homeTeam);
        $awayTeam = $this->formatTeamName($game->awayTeam);

        return [
            "{$awayTeam} @ {$homeTeam}",
            $prediction->predicted_spread > 0
                ? "{$homeTeam} -{$prediction->predicted_spread}"
                : "{$awayTeam} -".abs($prediction->predicted_spread),
            round($prediction->predicted_total, 1),
            round($prediction->win_probability * 100, 1).'%',
            round($prediction->confidence_score, 1),
        ];
    }

    protected function displayPrediction(mixed $prediction): void
    {
        $game = $prediction->game;
        $homeTeam = $this->formatTeamName($game->homeTeam);
        $awayTeam = $this->formatTeamName($game->awayTeam);

        $this->newLine();
        $this->info("Game: {$awayTeam} @ {$homeTeam}");
        $this->info("Date: {$this->formatGameDate($game)}");
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Home Elo', $prediction->home_elo],
                ['Away Elo', $prediction->away_elo],
                [$this->homeOffLabel(), round($prediction->{$this->homeOffColumn()}, 1)],
                [$this->homeDefLabel(), round($prediction->{$this->homeDefColumn()}, 1)],
                [$this->awayOffLabel(), round($prediction->{$this->awayOffColumn()}, 1)],
                [$this->awayDefLabel(), round($prediction->{$this->awayDefColumn()}, 1)],
                ['Predicted Spread', $prediction->predicted_spread > 0
                    ? "{$homeTeam} -{$prediction->predicted_spread}"
                    : "{$awayTeam} -".abs($prediction->predicted_spread), ],
                ['Predicted Total', round($prediction->predicted_total, 1)],
                ['Win Probability', round($prediction->win_probability * 100, 1).'%'],
                ['Confidence Score', round($prediction->confidence_score, 1)],
            ]
        );
    }

    protected function formatGameDate(mixed $game): string
    {
        return $game->game_date->format('Y-m-d');
    }

    protected function formatTeamName(mixed $team): string
    {
        if (static::TEAM_NAME_FIELDS === []) {
            throw new \RuntimeException('TEAM_NAME_FIELDS must be defined.');
        }

        $parts = array_values(array_filter(
            array_map(fn (string $field) => isset($team->{$field}) ? (string) $team->{$field} : '', static::TEAM_NAME_FIELDS),
            fn (string $value) => $value !== ''
        ));

        return implode(' ', $parts);
    }

    protected function homeOffColumn(): string
    {
        return 'home_off_eff';
    }

    protected function homeDefColumn(): string
    {
        return 'home_def_eff';
    }

    protected function awayOffColumn(): string
    {
        return 'away_off_eff';
    }

    protected function awayDefColumn(): string
    {
        return 'away_def_eff';
    }

    protected function homeOffLabel(): string
    {
        return 'Home Off Eff';
    }

    protected function homeDefLabel(): string
    {
        return 'Home Def Eff';
    }

    protected function awayOffLabel(): string
    {
        return 'Away Off Eff';
    }

    protected function awayDefLabel(): string
    {
        return 'Away Def Eff';
    }

    /**
     * @return class-string
     */
    protected function generateActionClass(): string
    {
        return $this->requiredString(static::GENERATE_ACTION_CLASS, 'GENERATE_ACTION_CLASS must be defined.');
    }

    /**
     * @return class-string
     */
    protected function gameModelClass(): string
    {
        return $this->requiredString(static::GAME_MODEL_CLASS, 'GAME_MODEL_CLASS must be defined.');
    }

    /**
     * @return class-string
     */
    protected function predictionModelClass(): string
    {
        return $this->requiredString(static::PREDICTION_MODEL_CLASS, 'PREDICTION_MODEL_CLASS must be defined.');
    }
}
