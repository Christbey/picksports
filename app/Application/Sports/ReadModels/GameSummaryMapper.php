<?php

namespace App\Application\Sports\ReadModels;

use App\Models\MLB\Game as MlbGame;
use App\Services\Api\V2\SportContext;
use App\Support\Sports\GameDateTimePresenter;
use Illuminate\Database\Eloquent\Model;

final class GameSummaryMapper
{
    public function fromModel(SportContext $context, Model $game, ?Model $prediction = null): GameSummary
    {
        $dateTime = GameDateTimePresenter::forSport(
            $context->slug,
            $game->getAttribute('game_date'),
            $game->getAttribute('game_time'),
        );

        if ($prediction === null && $game->relationLoaded('prediction')) {
            $loadedPrediction = $game->getRelation('prediction');
            $prediction = $loadedPrediction instanceof Model ? $loadedPrediction : null;
        }

        return new GameSummary(
            id: $game->getKey(),
            sportEventId: $this->sportEventId($context, $game),
            sport: $context->slug,
            espnId: $this->string($game->getAttribute('espn_id') ?? $game->getAttribute('espn_event_id')),
            espnEventId: $this->string($game->getAttribute('espn_event_id') ?? $game->getAttribute('espn_id')),
            espnUid: $this->string($game->getAttribute('espn_uid')),
            season: $this->int($game->getAttribute('season')),
            seasonType: $this->intOrString($game->getAttribute('season_type')),
            week: $this->int($game->getAttribute('week')),
            postseasonRound: $this->intOrString($game->getAttribute('postseason_round')),
            name: $this->string($game->getAttribute('name')),
            shortName: $this->string($game->getAttribute('short_name')),
            gameDate: $dateTime['game_date'],
            gameTime: $dateTime['game_time'],
            venue: $this->string($game->getAttribute('venue_name') ?? $game->getAttribute('venue')),
            venueCity: $this->string($game->getAttribute('venue_city')),
            venueState: $this->string($game->getAttribute('venue_state')),
            attendance: $this->int($game->getAttribute('attendance')),
            status: $this->string($game->getAttribute('status')),
            period: $this->int($game->getAttribute('period')),
            gameClock: $this->string($game->getAttribute('game_clock') ?? $game->getAttribute('clock')),
            homeTeamId: $this->int($game->getAttribute('home_team_id')),
            awayTeamId: $this->int($game->getAttribute('away_team_id')),
            homeScore: $this->number($game->getAttribute('home_score')),
            awayScore: $this->number($game->getAttribute('away_score')),
            homeLinescores: $this->array($game->getAttribute('home_linescores')),
            awayLinescores: $this->array($game->getAttribute('away_linescores')),
            broadcastNetworks: $this->array($game->getAttribute('broadcast_networks')),
            inning: $this->int($game->getAttribute('inning')),
            inningHalf: $this->string($game->getAttribute('inning_half')),
            balls: $this->int($game->getAttribute('balls')),
            strikes: $this->int($game->getAttribute('strikes')),
            outs: $this->int($game->getAttribute('outs')),
            probableHomePitcherEspnId: $this->string($game->getAttribute('probable_home_pitcher_espn_id')),
            probableAwayPitcherEspnId: $this->string($game->getAttribute('probable_away_pitcher_espn_id')),
            actualHomePitcherEspnId: $this->string($game->getAttribute('actual_home_pitcher_espn_id')),
            actualAwayPitcherEspnId: $this->string($game->getAttribute('actual_away_pitcher_espn_id')),
            projectedHomePitcherEspnId: $this->string($game->getAttribute('projected_home_pitcher_espn_id')),
            projectedAwayPitcherEspnId: $this->string($game->getAttribute('projected_away_pitcher_espn_id')),
            homeStartingPitcherSource: $game instanceof MlbGame ? $game->startingPitcherSource('home') : null,
            awayStartingPitcherSource: $game instanceof MlbGame ? $game->startingPitcherSource('away') : null,
            homeStartingPitcherConfidence: $game instanceof MlbGame ? $game->startingPitcherConfidence('home') : null,
            awayStartingPitcherConfidence: $game instanceof MlbGame ? $game->startingPitcherConfidence('away') : null,
            homeStartingPitcherCandidates: $game instanceof MlbGame ? $game->startingPitcherCandidates('home') : [],
            awayStartingPitcherCandidates: $game instanceof MlbGame ? $game->startingPitcherCandidates('away') : [],
            homeExpectedStartingPitcherRating: $game instanceof MlbGame ? $game->expectedStartingPitcherRating('home') : null,
            awayExpectedStartingPitcherRating: $game instanceof MlbGame ? $game->expectedStartingPitcherRating('away') : null,
            homeStartingPitcherUncertainty: $game instanceof MlbGame ? $game->startingPitcherUncertainty('home') : null,
            awayStartingPitcherUncertainty: $game instanceof MlbGame ? $game->startingPitcherUncertainty('away') : null,
            pitcherProjectionMetadata: $this->array($game->getAttribute('pitcher_projection_metadata')),
            pitcherProjectionGeneratedAt: GameDateTimePresenter::serializeDateValue($game->getAttribute('pitcher_projection_generated_at')),
            startingPitcherConfirmationMetadata: $this->array($game->getAttribute('starting_pitcher_confirmation_metadata')),
            startingPitchersConfirmedAt: GameDateTimePresenter::serializeDateValue($game->getAttribute('starting_pitchers_confirmed_at')),
            isNcaaTournament: (bool) ($game->getAttribute('is_ncaa_tournament') ?? false),
            tournamentId: $this->intOrString($game->getAttribute('tournament_id')),
            tournamentNote: $this->string($game->getAttribute('tournament_note')),
            tournamentRound: $this->intOrString($game->getAttribute('tournament_round')),
            tournamentRegion: $this->string($game->getAttribute('tournament_region')),
            homeSeed: $this->int($game->getAttribute('home_seed')),
            awaySeed: $this->int($game->getAttribute('away_seed')),
            playInTargetSeed: $this->int($game->getAttribute('play_in_target_seed')),
            matchupContext: $this->array($game->getAttribute('matchup_context')),
            homeTeam: $this->team($this->loadedRelation($game, 'homeTeam')),
            awayTeam: $this->team($this->loadedRelation($game, 'awayTeam')),
            homeStartingPitcher: $this->resolvedPitcher($game, 'home'),
            awayStartingPitcher: $this->resolvedPitcher($game, 'away'),
            homeStartingPitcherForecast: $this->startingPitcherForecast($game, 'home'),
            awayStartingPitcherForecast: $this->startingPitcherForecast($game, 'away'),
            prediction: $prediction === null ? null : $this->prediction($prediction),
            completedAt: GameDateTimePresenter::serializeDateValue($game->getAttribute('completed_at')),
            updatedAt: GameDateTimePresenter::serializeDateValue($game->getAttribute('updated_at')),
        );
    }

    private function team(mixed $team): ?TeamSummary
    {
        if (! $team instanceof Model) {
            return null;
        }

        $location = $this->string($team->getAttribute('location') ?? $team->getAttribute('school'));
        $name = $this->string($team->getAttribute('name') ?? $team->getAttribute('mascot'));
        $displayName = $this->string($team->getAttribute('display_name'));

        if ($displayName === null) {
            $displayName = trim(implode(' ', array_filter([$location, $name]))) ?: null;
        }

        return new TeamSummary(
            id: $team->getKey(),
            espnId: $this->string($team->getAttribute('espn_id')),
            abbreviation: $this->string($team->getAttribute('abbreviation')),
            location: $location,
            name: $name,
            nickname: $this->string($team->getAttribute('nickname')),
            displayName: $displayName,
            shortDisplayName: $this->string($team->getAttribute('short_display_name') ?? $team->getAttribute('abbreviation')),
            conference: $this->string($team->getAttribute('conference')),
            league: $this->string($team->getAttribute('league')),
            division: $this->string($team->getAttribute('division')),
            color: $this->string($team->getAttribute('color')),
            alternateColor: $this->string($team->getAttribute('alternate_color')),
            logoUrl: $this->string($team->getAttribute('logo_url')),
        );
    }

    private function prediction(Model $prediction): PredictionSummary
    {
        $probability = $this->float($prediction->getAttribute('win_probability')) ?? 0.5;
        $probability = $probability > 1 ? $probability / 100 : $probability;
        $predictedSpread = $this->float($prediction->getAttribute('predicted_spread'));
        $predictedTotal = $this->float($prediction->getAttribute('predicted_total'));
        $confidenceScore = $this->float($prediction->getAttribute('confidence_score'));

        return new PredictionSummary(
            id: $prediction->getKey(),
            predictedSpread: $predictedSpread,
            predictedTotal: $predictedTotal,
            confidenceScore: $confidenceScore,
            homeWinProbability: $probability,
            markets: array_values(array_filter([
                new MarketSummary(
                    type: 'moneyline',
                    selection: 'home',
                    projectedLine: null,
                    probability: $probability,
                    confidenceScore: $confidenceScore,
                ),
                new MarketSummary(
                    type: 'moneyline',
                    selection: 'away',
                    projectedLine: null,
                    probability: round(1 - $probability, 6),
                    confidenceScore: $confidenceScore,
                ),
                $predictedSpread === null ? null : new MarketSummary(
                    type: 'spread',
                    selection: 'home',
                    projectedLine: $predictedSpread,
                    probability: null,
                    confidenceScore: $confidenceScore,
                ),
                $predictedTotal === null ? null : new MarketSummary(
                    type: 'total',
                    selection: 'combined',
                    projectedLine: $predictedTotal,
                    probability: null,
                    confidenceScore: $confidenceScore,
                ),
            ])),
        );
    }

    /** @return array<string, mixed>|null */
    private function resolvedPitcher(Model $game, string $side): ?array
    {
        if (! $game instanceof MlbGame) {
            return null;
        }

        foreach (['actual', 'probable', 'projected'] as $source) {
            $relation = $source.ucfirst($side).'Pitcher';
            $player = $this->loadedRelation($game, $relation);

            if ($player instanceof Model) {
                return $this->playerPayload($player);
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function startingPitcherForecast(Model $game, string $side): ?array
    {
        if (! $game instanceof MlbGame) {
            return null;
        }

        $forecast = $this->loadedRelation($game, $side.'StartingPitcherForecast');
        if (! $forecast instanceof Model) {
            return null;
        }

        return [
            'id' => $forecast->getKey(),
            'forecast_hash' => $forecast->getAttribute('forecast_hash'),
            'model_version' => $forecast->getAttribute('model_version'),
            'prediction_source' => $forecast->getAttribute('prediction_source'),
            'predicted_pitcher' => $this->playerPayload($this->loadedRelation($forecast, 'predictedPitcher')),
            'predicted_pitcher_rating' => $forecast->getAttribute('predicted_pitcher_rating'),
            'predicted_rating_source' => $forecast->getAttribute('predicted_rating_source'),
            'confidence' => $forecast->getAttribute('confidence'),
            'evidence' => $forecast->getAttribute('evidence'),
            'forecasted_at' => GameDateTimePresenter::serializeDateValue($forecast->getAttribute('forecasted_at')),
            'game_start_at' => GameDateTimePresenter::serializeDateValue($forecast->getAttribute('game_start_at')),
            'known_before_game_start' => $forecast->getAttribute('known_before_game_start'),
            'actual_pitcher' => $this->playerPayload($this->loadedRelation($forecast, 'actualPitcher')),
            'actual_pitcher_rating' => $forecast->getAttribute('actual_pitcher_rating'),
            'confirmation_source' => $forecast->getAttribute('confirmation_source'),
            'confirmed_at' => GameDateTimePresenter::serializeDateValue($forecast->getAttribute('confirmed_at')),
            'is_correct' => $forecast->getAttribute('is_correct'),
            'starter_changed' => $forecast->getAttribute('starter_changed'),
            'confidence_error' => $forecast->getAttribute('confidence_error'),
            'brier_score' => $forecast->getAttribute('brier_score'),
            'log_loss' => $forecast->getAttribute('log_loss'),
            'rating_difference' => $forecast->getAttribute('rating_difference'),
            'grade' => $forecast->getAttribute('grade'),
            'graded_at' => GameDateTimePresenter::serializeDateValue($forecast->getAttribute('graded_at')),
        ];
    }

    /** @return array<string, mixed>|null */
    private function playerPayload(mixed $player): ?array
    {
        if (! $player instanceof Model) {
            return null;
        }

        return [
            'id' => $player->getKey(),
            'espn_id' => $player->getAttribute('espn_id'),
            'full_name' => $player->getAttribute('full_name') ?? $player->getAttribute('display_name') ?? trim((string) $player->getAttribute('first_name').' '.(string) $player->getAttribute('last_name')),
            'display_name' => $player->getAttribute('display_name') ?? $player->getAttribute('full_name'),
            'headshot_url' => $player->getAttribute('headshot_url'),
            'position' => $player->getAttribute('position'),
            'elo_rating' => $player->getAttribute('elo_rating'),
        ];
    }

    private function loadedRelation(Model $model, string $relation): mixed
    {
        return $model->relationLoaded($relation) ? $model->getRelation($relation) : null;
    }

    private function sportEventId(SportContext $context, Model $game): ?string
    {
        $sportEvent = $this->loadedRelation($game, 'sportEvent');

        if (! $sportEvent instanceof Model
            || $sportEvent->getAttribute('sport') !== $context->slug) {
            return null;
        }

        return $this->string($sportEvent->getAttribute('public_id'));
    }

    private function string(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function number(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function intOrString(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_int($value) ? $value : (string) $value;
    }

    /** @return array<mixed>|null */
    private function array(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
