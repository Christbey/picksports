<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\CBB\CalculateGamePlayEpa;
use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\CbbPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Services\CBB\PlayScoreReconciler;
use App\Services\ESPN\CBB\EspnService;
use App\Support\ScoreObservation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = CbbPlayData::class;

    protected const SKIP_UNCHANGED_REFRESH = true;

    public function execute(string $eventId): int
    {
        $count = parent::execute($eventId);
        $game = Game::query()->where('espn_event_id', $eventId)->first();
        if ($count > 0 && $game?->status === config('cbb.statuses.final')
            && Play::query()->where('game_id', $game->id)->whereNull('epa_calculated_at')->exists()) {
            $teams = Play::query()->where('game_id', $game->id)->whereNotNull('possession_team_id')
                ->distinct()->pluck('possession_team_id');
            if ($teams->contains($game->home_team_id) && $teams->contains($game->away_team_id)) {
                app(CalculateGamePlayEpa::class)->execute($game);
            }
        }

        return $count;
    }

    protected function fetchPlaysPayload(string $eventId): ?array
    {
        $plays = parent::fetchPlaysPayload($eventId);
        $game = Game::query()->with(['homeTeam', 'awayTeam'])->where('espn_event_id', $eventId)->first();
        if ($plays && $game) {
            $teamIds = [(string) $game->homeTeam?->espn_id, (string) $game->awayTeam?->espn_id];
            foreach ($plays as $index => $raw) {
                $teamId = CbbPlayData::fromEspnResponse($raw, $index)->possessionTeamEspnId;
                if ($teamId !== null && ! in_array($teamId, $teamIds, true)) {
                    throw new \RuntimeException('CBB play references a team outside the matchup; preserving stored plays.');
                }
            }
        }
        if ($plays && $game?->status === config('cbb.statuses.final')) {
            $last = $plays[array_key_last($plays)];
            if (! isset($last['homeScore'], $last['awayScore'])
                || (int) $last['homeScore'] !== (int) $game->home_score
                || (int) $last['awayScore'] !== (int) $game->away_score) {
                $reconciled = app(PlayScoreReconciler::class)->reconcile($plays, $game);
                if ($reconciled === null) {
                    throw new \RuntimeException('CBB play feed does not reach the stored final score; preserving stored plays.');
                }
                Log::notice('CBB play scoreboard reconciled from explicit scoring events.', [
                    'game_id' => $game->id,
                    'event_id' => $eventId,
                    'original_score_hash' => hash('sha256', json_encode(array_map(fn (array $play): array => [$play['id'], $play['homeScore'] ?? null, $play['awayScore'] ?? null], $plays), JSON_THROW_ON_ERROR)),
                    'final_home_score' => $game->home_score,
                    'final_away_score' => $game->away_score,
                ]);
                $plays = $reconciled;
            }
        }

        return $plays;
    }

    protected function validatePlayBatchForGame(Model $game, array $dtos): void
    {
        if ($game->status !== config('cbb.statuses.final')) {
            return;
        }
        $last = $dtos[array_key_last($dtos)];
        if ($last->homeScore === null || $last->awayScore === null
            || $last->homeScore !== ScoreObservation::known($game->home_score)
            || $last->awayScore !== ScoreObservation::known($game->away_score)) {
            throw new \RuntimeException('CBB play feed does not reach the stored final score; preserving stored plays.');
        }
    }
}
