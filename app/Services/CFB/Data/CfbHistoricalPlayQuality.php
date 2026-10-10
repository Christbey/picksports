<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CfbHistoricalPlayQuality
{
    public function forTeam(Game $target, int $teamId, CarbonImmutable $capturedAt): array
    {
        $games = Game::where('season', $target->season)->where('status', 'STATUS_FINAL')
            ->where('id', '!=', $target->id)->where('updated_at', '<=', $capturedAt)
            ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
            ->get(['id', 'espn_event_id', 'season', 'status', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $held = [];
        $coreSources = [];
        foreach ($games as $game) {
            $quality = app(CfbDataReadiness::class)->forGame($game);
            if (! data_get($quality, 'components.boxscore.accepted', false)
                && app(CfbDataReadiness::class)->coreBoxscoreReady($game)) {
                $source = GameDataCheck::where('game_id', $game->id)
                    ->where('component', CfbCfbdTeamBoxscoreSync::COMPONENT)->whereNotNull('accepted_at')
                    ->where('accepted_at', '<=', $capturedAt)->latest('accepted_at')->latest('id')->first();
                if ($source) {
                    $coreSources[] = ['game_id' => $game->id, 'provider' => 'cfbd', 'source_hash' => $source->source_hash,
                        'source_path' => $source->source_path, 'validator_version' => $source->validator_version];
                }
            }
            $step = DB::table('cfb_pipeline_steps')->where('scope', 'game:'.$game->id)->where('stage', 'normalize')
                ->where('updated_at', '<=', $capturedAt)->orderByDesc('updated_at')->orderByDesc('id')->first();
            $result = $step ? json_decode($step->result ?? '{}', true) : [];
            if (! $quality['ready'] || $step?->state !== 'complete'
                || data_get($result, 'drives.normalizer_version') !== CfbDriveBuilder::VERSION
                || data_get($result, 'drives.source_revision') !== data_get($quality, 'components.plays.source_hash')
                || data_get($result, 'drives.score_ledger_revision') !== data_get($quality, 'components.boxscore.source_hash')) {
                $held[] = $game->id;
            }
        }

        return ['state' => $held === [] ? 'complete' : 'incomplete', 'held_game_ids' => $held,
            'core_boxscore_sources' => $coreSources,
            'games_checked' => $games->count(), 'normalizer_version' => CfbDriveBuilder::VERSION,
            'policy' => 'incomplete_play_history_excluded_from_epa_and_possession_challenger'];
    }
}
