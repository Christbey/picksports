<?php

namespace App\Actions\WNBA;

use App\Actions\Sports\AbstractEloCalculator;
use App\Models\WNBA\EloRating;
use App\Models\WNBA\Game;
use App\Models\WNBA\Team;
use App\Services\WNBA\WnbaEloPublicationGate;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CalculateElo extends AbstractEloCalculator
{
    protected const SPORT_KEY = 'wnba';

    protected const ELO_RATING_MODEL = EloRating::class;

    public function execute(Model $game, bool $skipIfExists = true): array
    {
        if (! $game instanceof Game || ! $game->exists) {
            throw new RuntimeException('WNBA Elo requires a persisted WNBA game.');
        }
        $connection = $game->getConnection();

        return $connection->transaction(function () use ($connection, $game, $skipIfExists): array {
            app(WnbaEloPublicationGate::class)->lock($connection);
            $current = Game::on($connection->getName())->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $teams = Team::on($connection->getName())->whereIn('id', [$current->home_team_id, $current->away_team_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($teams->count() !== 2 || $current->home_team_id === $current->away_team_id) {
                throw new RuntimeException('WNBA Elo requires distinct known teams.');
            }
            $current->setRelation('homeTeam', $teams->get($current->home_team_id));
            $current->setRelation('awayTeam', $teams->get($current->away_team_id));

            return parent::execute($current, $skipIfExists);
        }, 3);
    }

    protected function eloAlreadyCalculated(Model $game, Model $homeTeam, Model $awayTeam): bool
    {
        $rows = EloRating::on($game->getConnectionName())->where('game_id', $game->id)
            ->whereIn('team_id', [$homeTeam->id, $awayTeam->id])->orderBy('id')->lockForUpdate()->get(['id']);
        if ($rows->isNotEmpty() && $rows->count() !== 2) {
            throw new RuntimeException('Partial WNBA Elo history requires a reviewed scoped replay.');
        }

        return $rows->count() === 2;
    }

    protected function saveEloHistory(Model $team, Model $game, int $newElo, float $eloChange): void
    {
        EloRating::on($game->getConnectionName())->updateOrCreate(['team_id' => $team->id, 'game_id' => $game->id], [
            'season' => $game->season, 'week' => $game->week, 'date' => $game->game_date,
            'elo_rating' => $newElo, 'elo_change' => $eloChange,
        ]);
    }

    protected function calculateKFactor(Model $game): float
    {
        return $this->calculateStandardKFactor($game);
    }

    protected function isPlayoffGame(Model $game): bool
    {
        return $this->gameMatchesSeasonType($game, config('wnba.season.types.postseason'));
    }

    protected function calculateMarginMultiplier(Model $game): float
    {
        $margin = abs($game->home_score - $game->away_score);
        $multipliers = config('wnba.elo.margin_multipliers', []);

        return $this->resolveMarginMultiplier($margin, $multipliers);
    }
}
