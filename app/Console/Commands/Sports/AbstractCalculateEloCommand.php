<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

abstract class AbstractCalculateEloCommand extends Command
{
    use ResolvesRequiredConfig;

    protected const COMMAND_NAME = '';

    protected const COMMAND_DESCRIPTION = '';

    /**
     * @var array<int, string>
     */
    protected const EXTRA_SIGNATURE_OPTIONS = [];

    protected const SPORT_NAME = '';

    protected const GAME_MODEL = Model::class;

    protected const TEAM_MODEL = Model::class;

    protected const ELO_RATING_MODEL = Model::class;

    protected const CALCULATE_ELO_ACTION = '';

    public function __construct()
    {
        $this->signature = $this->buildSignature();
        $this->description = $this->commandDescription();

        parent::__construct();
    }

    /**
     * Get the sport name for display
     */
    protected function getSportName(): string
    {
        return $this->requiredString(static::SPORT_NAME, 'SPORT_NAME must be defined on calculate-elo command.');
    }

    /**
     * Get the Game model class
     */
    protected function getGameModel(): string
    {
        return $this->requiredNonDefaultString(static::GAME_MODEL, Model::class, 'GAME_MODEL must be defined on calculate-elo command.');
    }

    /**
     * Get the Team model class
     */
    protected function getTeamModel(): string
    {
        return $this->requiredNonDefaultString(static::TEAM_MODEL, Model::class, 'TEAM_MODEL must be defined on calculate-elo command.');
    }

    /**
     * Get the EloRating model class
     */
    protected function getEloRatingModel(): string
    {
        return $this->requiredNonDefaultString(static::ELO_RATING_MODEL, Model::class, 'ELO_RATING_MODEL must be defined on calculate-elo command.');
    }

    /**
     * Get the CalculateElo action class
     */
    protected function getCalculateEloAction(): string
    {
        return $this->requiredString(static::CALCULATE_ELO_ACTION, 'CALCULATE_ELO_ACTION must be defined on calculate-elo command.');
    }

    /**
     * Get the default Elo rating
     */
    protected function getDefaultElo(): int
    {
        return 1500;
    }

    /**
     * Get season types eligible for analytics (e.g., exclude spring training).
     * Return null to include all season types.
     *
     * @return array<int, string>|null
     */
    protected function getAnalyticsSeasonTypes(): ?array
    {
        return null;
    }

    protected function buildSignature(): string
    {
        $segments = [
            $this->commandName(),
            '{--season= : Calculate Elo for a specific season}',
            '{--from-date= : Calculate Elo starting from this date (YYYY-MM-DD)}',
            '{--to-date= : Calculate Elo up to this date (YYYY-MM-DD)}',
            '{--reset : Reset all Elo ratings to default (1500) before calculating}',
        ];

        return implode("\n ", array_merge($segments, static::EXTRA_SIGNATURE_OPTIONS));
    }

    protected function commandName(): string
    {
        return $this->requiredString(static::COMMAND_NAME, 'COMMAND_NAME must be defined.');
    }

    protected function commandDescription(): string
    {
        return $this->requiredString(static::COMMAND_DESCRIPTION, 'COMMAND_DESCRIPTION must be defined.');
    }

    public function handle(): int
    {
        $calculateEloClass = $this->getCalculateEloAction();
        $calculateElo = new $calculateEloClass;
        $targetSeason = $this->option('season');

        // Reset Elo ratings if requested
        if ($this->option('reset')) {
            $this->info('Resetting all Elo ratings to '.$this->getDefaultElo().'...');
            $teamModel = $this->getTeamModel();
            $eloRatingModel = $this->getEloRatingModel();

            $teamModel::query()->update(['elo_rating' => $this->getDefaultElo()]);
            $eloRatingModel::query()->truncate();
            $this->info('Elo ratings reset successfully.');
        }

        if ($this->hasOption('regress') && $this->option('regress') && ! $this->option('reset')) {
            if ($targetSeason === null || $targetSeason === '') {
                $this->error('The --regress option requires --season for an idempotent offseason rollover.');

                return Command::FAILURE;
            }

            if ($this->seasonAlreadyInitialized((int) $targetSeason)) {
                $this->info("Skipping regression because Elo history already exists for season {$targetSeason}.");
            } else {
                $this->applyRegressionTowardMean((int) $targetSeason);
            }
        }

        // Build query for completed games
        $gameModel = $this->getGameModel();
        $query = $gameModel::query()
            ->where('status', 'STATUS_FINAL')
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->orderBy('id');

        // Filter to analytics-eligible season types (e.g., exclude spring training)
        $analyticsTypes = $this->getAnalyticsSeasonTypes();
        if ($analyticsTypes) {
            $query->whereIn('season_type', $analyticsTypes);
        }

        $this->applyAdditionalAnalyticsFilters($query);

        // Apply filters
        if ($season = $this->option('season')) {
            $query->where('season', $season);
        }

        if ($this->hasOption('week') && ($week = $this->option('week'))) {
            $query->where('week', $week);
        }

        if ($fromDate = $this->option('from-date')) {
            $query->where('game_date', '>=', $fromDate);
        }

        if ($toDate = $this->option('to-date')) {
            $query->where('game_date', '<=', $toDate);
        }

        $games = $query->get();

        if ($games->isEmpty()) {
            $this->warn('No completed games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Calculating Elo for {$games->count()} completed games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $processed = 0;
        $skipped = 0;

        foreach ($games as $game) {
            $result = $calculateElo->execute($game, skipIfExists: ! $this->option('reset'));

            if ($result['skipped']) {
                $skipped++;
            } else {
                $processed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Processed {$processed} games.");
        $this->comment("Skipped {$skipped} games (already calculated).");

        return Command::SUCCESS;
    }

    protected function applyAdditionalAnalyticsFilters(Builder $query): void {}

    protected function seasonAlreadyInitialized(int $season): bool
    {
        $eloRatingModel = $this->getEloRatingModel();

        return $eloRatingModel::query()
            ->where('season', $season)
            ->exists();
    }

    protected function applyRegressionTowardMean(?int $targetSeason = null): void
    {
        $teamModel = $this->getTeamModel();
        $eloRatingModel = $this->getEloRatingModel();
        $defaultElo = $this->getDefaultElo();
        $sportKey = strtolower($this->getSportName());
        $factor = (float) config("{$sportKey}.elo.offseason_regression_factor", 0.3);
        $retainFactor = 1 - $factor;

        $this->info(sprintf(
            'Applying %.0f%% regression toward mean Elo before calculation...',
            $factor * 100
        ));

        $teamModel::query()->each(function (Model $team) use ($eloRatingModel, $targetSeason, $defaultElo, $factor, $retainFactor) {
            $baseline = (int) ($team->elo_rating ?? $defaultElo);

            if ($targetSeason !== null) {
                $priorRating = $eloRatingModel::query()
                    ->where('team_id', $team->getKey())
                    ->where('season', '<', $targetSeason)
                    ->orderByDesc('season')
                    ->orderByDesc('date')
                    ->orderByDesc('id')
                    ->value('elo_rating');

                if ($priorRating !== null) {
                    $baseline = (int) round((float) $priorRating);
                }
            }

            $regressed = (int) round(($baseline * $retainFactor) + ($defaultElo * $factor));
            $team->update(['elo_rating' => $regressed]);
        });
    }
}
