<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\CFB\UpdateLivePrediction;
use App\Actions\ESPN\AbstractSummaryUpdatingSyncGameDetails;
use App\Actions\GradePlayerProps;
use App\Models\CFB\Game;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\ESPN\CFB\EspnService;
use App\Services\GameFinalizationDispatcher;
use App\Services\Sports\SportEventIdentitySynchronizer;
use App\Support\CFB\CfbGameStart;
use App\Support\SportsViewCache;
use Illuminate\Support\Facades\DB;

class SyncGameDetails extends AbstractSummaryUpdatingSyncGameDetails
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService, SyncPlayerStats $syncPlayerStats, SyncTeamStats $syncTeamStats, SyncPlays $syncPlays)
    {
        parent::__construct($espnService, $syncPlayerStats, $syncTeamStats, $syncPlays);
    }

    public function execute(string $eventId): array
    {
        $game = Game::where('espn_event_id', $eventId)->first();
        $empty = ['plays' => 0, 'player_stats' => 0, 'team_stats' => 0, 'game_updated' => false];
        if (! $game || ! ($payload = $this->espnService->getGame($eventId))) {
            return $empty;
        }
        $result = $empty;
        $previousStatus = null;
        app(SportEventIdentitySynchronizer::class)->mutateAndSync('cfb', $game, function (Game $locked) use ($payload, &$result, &$previousStatus): void {
            $previousStatus = (string) $locked->status;
            $result['game_updated'] = $this->updateGame($payload, $locked);
            if ((string) data_get($payload, 'header.id') === (string) $locked->espn_event_id
                && (int) data_get($payload, 'header.season.year') === (int) $locked->season) {
                $locked->fill(CfbGameStart::attributes(data_get($payload, 'header.competitions.0.date'),
                    data_get($payload, 'header.competitions.0', []), 'espn_verified_summary', (string) $locked->espn_event_id))->saveQuietly();
            }
        });
        $game = $game->fresh();
        $game->getConnection()->afterCommit(fn () => app(GameFinalizationDispatcher::class)->dispatchIfFinalizedTransition($game, $previousStatus));
        $validation = app(CfbGameDataValidator::class)->boxscore($payload, $game);
        $check = app(CfbSourceRevision::class)->record($game, 'boxscore', $payload, $validation);
        if ($validation['state'] === 'complete' && $check->state === 'complete' && ! $check->isCurrentAccepted()) {
            $counts = DB::transaction(function () use ($payload, $game, $check) {
                $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
                $game->load(['homeTeam', 'awayTeam']);
                $validation = app(CfbGameDataValidator::class)->boxscore($payload, $game);
                $check = app(CfbSourceRevision::class)->record($game, 'boxscore', $payload, $validation);
                if ($validation['state'] !== 'complete' || $check->state !== 'complete') {
                    return ['player_stats' => 0, 'team_stats' => 0];
                }
                if ($check->isCurrentAccepted()) {
                    return ['player_stats' => 0, 'team_stats' => 0];
                }
                $counts = ['player_stats' => $this->syncPlayerStats->execute($payload, $game), 'team_stats' => $this->syncTeamStats->execute($payload, $game)];
                if ($counts['player_stats'] < 1 || $counts['team_stats'] !== 2) {
                    throw new \DomainException('Complete CFB replacement was not admitted.');
                }
                $check->update(['accepted_at' => now()]);

                return $counts;
            });
            $result = [...$result, ...$counts];
        }
        $result['plays'] = $this->syncPlays->execute($eventId);
        $game = Game::where('espn_event_id', $eventId)->first();
        if ($game?->status === 'STATUS_FINAL' && $result['player_stats'] > 0) {
            $grading = app(GradePlayerProps::class)->executeForGame('americanfootball_ncaaf', $game->id);
            if ($grading['graded'] > 0) {
                app(SportsViewCache::class)->bustSegment(SportsViewCache::SEGMENT_PLAYER_PROPS_PAGE);
            }
        }

        if ($game && ($game->liveSnapshots()->exists() || in_array($game->status, ['STATUS_IN_PROGRESS', 'STATUS_HALFTIME', 'STATUS_END_PERIOD'], true))) {
            app(UpdateLivePrediction::class)->execute($game);
        }

        return $result;
    }
}
