<?php

namespace App\Actions\ESPN\MLB;

use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Actions\ESPN\MLB\Concerns\ResolvesMlbGameDateParts;
use App\Actions\MLB\UpdateLivePrediction;
use App\DataTransferObjects\ESPN\MLBGameData;
use App\Models\MLB\Game;
use App\Models\MLB\Team;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    use ResolvesMlbGameDateParts;

    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;

    protected function gameDtoFromResponse(array $eventData): MLBGameData
    {
        return MLBGameData::fromEspnResponse($eventData);
    }

    protected function buildGameAttributes(object $dto, array $eventData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        $attributes = parent::buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam);
        [$homeCompetitor, $awayCompetitor] = $this->resolveCompetitors($eventData);
        $dateParts = $this->resolveMlbGameDateParts($eventData);

        $homeProbablePitcherId = $this->probablePitcherEspnId($homeCompetitor);
        $awayProbablePitcherId = $this->probablePitcherEspnId($awayCompetitor);

        // ESPN frequently omits probables from later scoreboard payloads. An
        // omission is not evidence that a previously supplied starter changed.
        if ($homeProbablePitcherId !== null) {
            $attributes['probable_home_pitcher_espn_id'] = $homeProbablePitcherId;
        }
        if ($awayProbablePitcherId !== null) {
            $attributes['probable_away_pitcher_espn_id'] = $awayProbablePitcherId;
        }
        // The DTO supplies now() when date is absent; omission must not overwrite a known schedule.
        unset($attributes['game_date'], $attributes['game_time']);
        if ($dateParts['game_date'] !== null) {
            $attributes['game_date'] = $dateParts['game_date'];
        }
        if ($dateParts['game_time'] !== null) {
            $attributes['game_time'] = $dateParts['game_time'];
        }

        return $attributes + array_intersect_key($dateParts, array_flip(MlbGameStart::FIELDS));
    }

    protected function updateGameFromSummary(array $gameData, Model $game): void
    {
        $competition = data_get($gameData, 'header.competitions.0', []);
        $header = $gameData['header'] ?? [];
        foreach ([$competition, $header] as $part) {
            if (array_key_exists('date', $part) && $part['date'] !== null) {
                $this->resolveMlbGameDateParts(['date' => $part['date'], 'competitions' => [$competition]]);
                break;
            }
        }
        parent::updateGameFromSummary($gameData, $game);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $competition
     * @return array{game_date:?string,game_time:?string}
     */
    protected function summaryDateParts(string $summaryDate, array $header, array $competition): array
    {
        return $this->resolveMlbGameDateParts([
            'date' => $summaryDate,
            'header' => $header,
            'competitions' => [$competition],
        ]);
    }

    /**
     * @param  array<string, mixed>  $competitor
     */
    private function probablePitcherEspnId(array $competitor): ?string
    {
        $probables = $competitor['probables'] ?? null;

        if (! is_array($probables)) {
            return null;
        }

        foreach ($probables as $probable) {
            $playerId = data_get($probable, 'playerId');
            if (is_scalar($playerId) && (string) $playerId !== '') {
                return (string) $playerId;
            }

            $athleteId = data_get($probable, 'athlete.id');
            if (is_scalar($athleteId) && (string) $athleteId !== '') {
                return (string) $athleteId;
            }
        }

        return null;
    }
}
