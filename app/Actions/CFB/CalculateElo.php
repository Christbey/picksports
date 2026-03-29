<?php

namespace App\Actions\CFB;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\CFB\EloRating;
use App\Support\CfbSeasonAffiliationResolver;
use Illuminate\Database\Eloquent\Model;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'cfb';

    protected const ELO_RATING_MODEL = EloRating::class;

    public function __construct(
        private readonly CfbSeasonAffiliationResolver $seasonAffiliationResolver = new CfbSeasonAffiliationResolver,
    ) {}

    protected function calculateKFactor(Model $game): float
    {
        $kFactor = config('cfb.elo.base_k_factor');

        $kFactor = $this->applyRecencyWeekMultiplier(
            $game,
            (float) $kFactor,
            config('cfb.season.types.regular')
        );

        $kFactor = $this->applyPlayoffMultiplier($game, (float) $kFactor);

        // Apply margin of victory multiplier
        $marginMultiplier = $this->calculateMarginMultiplier($game);
        $kFactor *= $marginMultiplier;

        return $kFactor;
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $this->gameMatchesSeasonType($game, config('cfb.season.types.postseason'));
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $coefficient = config('cfb.elo.mov_coefficient');
        $maxMultiplier = config('cfb.elo.max_mov_multiplier');

        return $this->resolveLogMarginMultiplier($margin, (float) $coefficient, (float) $maxMultiplier);
    }

    protected function saveEloHistory(Model $team, Model $game, int $newElo, float $eloChange): void
    {
        $eloRatingClass = $this->getEloRatingModel();

        $eloRatingClass::create([
            'team_id' => $team->id,
            'game_id' => $game->id,
            'season' => $game->season,
            'week' => $game->week,
            'season_type' => $game->season_type,
            'date' => $game->game_date,
            'elo_rating' => $newElo,
            'elo_change' => $eloChange,
        ]);
    }

    public function execute(Model $game, bool $skipIfExists = true): array
    {
        $homeTeam = $game->homeTeam;
        $awayTeam = $game->awayTeam;

        if (! $homeTeam || ! $awayTeam) {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => false];
        }

        if (! $this->seasonAffiliationResolver->isFbs($homeTeam, (int) $game->season)
            || ! $this->seasonAffiliationResolver->isFbs($awayTeam, (int) $game->season)) {
            return ['home_change' => 0, 'away_change' => 0, 'skipped' => true];
        }

        return parent::execute($game, $skipIfExists);
    }
}
