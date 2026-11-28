<?php

namespace App\Console\Commands\Sports;

use App\Console\Commands\Concerns\ResolvesRequiredConfig;
use App\DataTransferObjects\ESPN\GameData;
use App\Exceptions\EloAdmissionRefused;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloEligibilityPolicy;
use App\Services\Sports\Elo\EloSeasonRollover;
use App\Services\Sports\Elo\EloSourceContext;
use App\Services\Sports\Elo\EloWriterProtocol;
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
            '{--reset : Retired destructive full reset; use a reviewed isolated rebuild}',
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
        if ($this->option('reset')) {
            $this->error('Destructive Elo reset is disabled. Use a separately reviewed sport-specific isolated rebuild.');

            return Command::FAILURE;
        }

        $coordinatedSport = strtolower($this->getSportName());
        try {
            $teamModel = $this->getTeamModel();
            $coordinated = app(EloAdmissionMode::class)->managed($coordinatedSport, (new $teamModel)->getConnection());
        } catch (\RuntimeException) {
            $this->error('Durably admitted Elo cannot fall back to disabled legacy writers.');

            return Command::FAILURE;
        }
        if ($coordinated) {
            try {
                app(EloWriterProtocol::class)->requireCapability($coordinatedSport);
            } catch (\RuntimeException) {
                $this->error('A complete coordinated Elo adapter is required; no legacy fallback is allowed.');

                return Command::FAILURE;
            }
        }

        $calculateEloClass = $this->getCalculateEloAction();
        $calculateElo = new $calculateEloClass;
        $targetSeason = $this->option('season');

        if ($this->hasOption('regress') && $this->option('regress') && ! $this->option('reset')) {
            if ($targetSeason === null || $targetSeason === '') {
                $this->error('The --regress option requires --season for an idempotent offseason rollover.');

                return Command::FAILURE;
            }

            if ($coordinated) {
                $validatedSeason = filter_var($targetSeason, FILTER_VALIDATE_INT);
                if ($validatedSeason === false) {
                    $this->error('A valid explicit rollover season is required.');

                    return Command::FAILURE;
                }
                try {
                    app(EloSeasonRollover::class)->execute($coordinatedSport, $validatedSeason,
                        ['team' => config($coordinatedSport.'.elo.offseason_regression_factor', 0.3)]);
                } catch (\RuntimeException|\InvalidArgumentException|\DomainException) {
                    $this->error('Elo rollover was refused; inspect baseline, version, target and season evidence.');

                    return Command::FAILURE;
                }
            } elseif ($this->seasonAlreadyInitialized((int) $targetSeason)) {
                $this->info("Skipping regression because Elo history already exists for season {$targetSeason}.");
            } else {
                $this->applyRegressionTowardMean((int) $targetSeason);
            }
        }

        // Build query for completed games
        $gameModel = $this->getGameModel();
        $query = $gameModel::query()
            ->when($coordinated, fn (Builder $finals) => $finals->whereIn('status', GameData::finalStatuses()),
                fn (Builder $finals) => $finals->where('status', 'STATUS_FINAL'))
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('game_date')
            ->when($coordinated, fn (Builder $ordered) => $ordered->orderBy('game_time'))
            ->orderBy('id');

        // Filter to analytics-eligible season types (e.g., exclude spring training)
        $analyticsTypes = $this->getAnalyticsSeasonTypes();
        if (! $coordinated && $analyticsTypes) {
            $query->whereIn('season_type', $analyticsTypes);
        }

        if (! $coordinated) {
            $this->applyAdditionalAnalyticsFilters($query);
        }

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

        if ($coordinated) {
            $gameIds = (clone $query)->limit(EloEligibilityPolicy::LIMITS['games'] + 1)->toBase()->pluck('id');
            if ($gameIds->count() > EloEligibilityPolicy::LIMITS['games']) {
                $this->error('Guarded Elo command game budget exceeded: considered '.$gameIds->count().'; no games applied.');

                return Command::FAILURE;
            }
            $query->whereIn('id', $gameIds->all());
        }
        $games = $query->get();
        $excluded = 0;
        if ($coordinated) {
            if ($games->count() > EloEligibilityPolicy::LIMITS['games']) {
                $this->error('Guarded Elo command game budget exceeded: considered '.$games->count().'; no games applied.');

                return Command::FAILURE;
            }
            $eligibility = app(EloEligibilityPolicy::class);
            foreach ($games as $key => $game) {
                $classification = $eligibility->classify($coordinatedSport, $game->getRawOriginal('season_type'));
                if ($classification === 'excluded') {
                    $games->forget($key);
                    $excluded++;

                    continue;
                }
                if ($classification !== 'competitive') {
                    $this->error('Guarded Elo source reconciliation required: unknown final season type; considered '.$games->count().' games; no games applied.');

                    return Command::FAILURE;
                }
                try {
                    app(EloSourceContext::class)->source($coordinatedSport, $game, []);
                } catch (\Throwable) {
                    $this->error('Guarded Elo source reconciliation required: incomplete observed final facts; considered '.$games->count().' games; no games applied.');

                    return Command::FAILURE;
                }
            }
        }

        if ($games->isEmpty()) {
            $this->warn('No completed games found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->info("Calculating Elo for {$games->count()} completed games...");

        $bar = $this->output->createProgressBar($games->count());
        $bar->start();

        $processed = 0;
        $skipped = $excluded;

        foreach ($games as $game) {
            try {
                $result = $calculateElo->execute($game, skipIfExists: ! $this->option('reset'));
            } catch (EloAdmissionRefused $exception) {
                $this->newLine();
                $this->error(json_encode($exception->context(), JSON_THROW_ON_ERROR));

                return Command::FAILURE;
            }

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
        $this->comment($coordinated
            ? "Skipped {$skipped} games (stored receipts, excluded types or explicit reviewed baseline cutoffs; no new history)."
            : "Skipped {$skipped} games (already calculated).");

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
