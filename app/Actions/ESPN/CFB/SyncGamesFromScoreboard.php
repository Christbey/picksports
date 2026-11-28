<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSyncGamesFromScoreboard;
use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Services\ESPN\CFB\EspnService;
use App\Support\CFB\CfbGameStart;
use App\Support\CFB\CfbWeek;
use App\Support\CfbPostseasonRoundResolver;
use Illuminate\Database\Eloquent\Model;

class SyncGamesFromScoreboard extends AbstractSyncGamesFromScoreboard
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const UPDATE_LIVE_PREDICTION_ACTION_CLASS = UpdateLivePrediction::class;

    protected const SYNC_ORPHANED_IN_PROGRESS_GAMES = true;

    protected const SYNC_ORPHANED_SCHEDULED_GAMES = true;

    public function __construct(
        EspnService $espnService,
        ?object $updateLivePrediction = null,
        protected ?CfbPostseasonRoundResolver $postseasonRoundResolver = null,
    ) {
        $this->postseasonRoundResolver ??= app(CfbPostseasonRoundResolver::class);

        parent::__construct($espnService, $updateLivePrediction);
    }

    /**
     * @param  array<string, mixed>  $eventData
     * @return array<string, mixed>
     */
    protected function buildGameAttributes(object $dto, array $eventData, ?Model $homeTeam, ?Model $awayTeam): array
    {
        return array_merge(
            parent::buildGameAttributes($dto, $eventData, $homeTeam, $awayTeam),
            [
                ...CfbGameStart::attributes($eventData['date'] ?? null, array_replace_recursive(['status' => $eventData['status'] ?? []], $eventData['competitions'][0] ?? []), 'espn_scoreboard', $dto->espnEventId),
                'week' => CfbWeek::productWeekForGame(
                    $dto->season,
                    $dto->seasonType,
                    $dto->week,
                    $dto->gameDate,
                ),
                'postseason_round' => $this->postseasonRoundResolver?->resolveFromEspnEvent($eventData),
            ],
        );
    }

    protected function updateGameFromSummary(array $gameData, Model $game): void
    {
        if ((string) data_get($gameData, 'header.id') !== (string) $game->espn_event_id
            || (int) data_get($gameData, 'header.season.year') !== (int) $game->season) {
            throw new \RuntimeException('CFB summary identity or season could not be verified.');
        }
        parent::updateGameFromSummary($gameData, $game);
    }

    protected function summaryDateParts(string $summaryDate, array $header, array $competition): array
    {
        return ['game_date' => null, 'game_time' => null,
            ...CfbGameStart::attributes($summaryDate, $competition, 'espn_verified_summary', (string) ($header['id'] ?? ''))];
    }

    protected function currentGameAttributes(Model $game, array $attributes): array
    {
        if (data_get($attributes, 'kickoff_observation.status') !== 'known') {
            unset($attributes['game_date'], $attributes['game_time']);
        }

        return $attributes;
    }
}
