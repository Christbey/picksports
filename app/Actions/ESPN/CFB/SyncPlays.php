<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\ESPN\CFB\EspnService;
use Illuminate\Support\Facades\DB;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = FootballPlayData::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    public function execute(string $eventId): int
    {
        $game = Game::where('espn_event_id', $eventId)->first();
        if (! $game) {
            return 0;
        }
        $response = $this->espnService->getPlays($eventId, $eventId);
        if (! is_array($response)) {
            return 0;
        }
        $validation = app(CfbGameDataValidator::class)->plays($response, $game);
        $check = app(CfbSourceRevision::class)->record($game, 'plays', $response, $validation);
        if ($validation['state'] !== 'complete' || $check->isCurrentAccepted()) {
            return 0;
        }

        return DB::transaction(function () use ($game, $response, $check) {
            Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $check->refresh();
            if ($check->isCurrentAccepted()) {
                return 0;
            }
            $ids = [];
            foreach ($response['items'] as $index => $raw) {
                $dto = FootballPlayData::fromEspnResponse($raw, $index);
                $attrs = $dto->toArray();
                $this->applyTeamRelations($attrs, $dto);
                $attrs['source_revision'] = $check->source_hash;
                $attrs['source_state'] = $raw;
                // Corrected plays must not retain an EPA value computed from an older state.
                $attrs += ['true_epa' => null, 'expected_points_before' => null, 'expected_points_after' => null, 'epa_calculated_at' => null];
                Play::updateOrCreate(['game_id' => $game->id, 'espn_play_id' => $dto->espnPlayId], $attrs);
                $ids[] = $dto->espnPlayId;
            }
            Play::where('game_id', $game->id)->whereNotIn('espn_play_id', $ids)->delete();
            $check->update(['accepted_at' => now()]);

            return count($ids);
        });
    }
}
