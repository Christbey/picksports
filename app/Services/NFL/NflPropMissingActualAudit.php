<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\PredictionFeatureSnapshot;
use App\Models\ProviderSourceFile;
use Illuminate\Support\Facades\DB;

/** Aggregate source coverage only; never converts absence into a result. */
class NflPropMissingActualAudit
{
    public function __construct(private NflPropProspectiveDataset $validator) {}

    public function report(int $season): array
    {
        $games = Game::with('sportEvent')->where('season', $season)->where('status', 'STATUS_FINAL')
            ->whereIn('season_type', ['2', 'regular'])->get()->keyBy('id');
        $snapshots = PredictionFeatureSnapshot::where('sport', 'nfl')->where('blend_version', NflPropProbabilityExperiment::POLICY)
            ->whereIn('game_id', $games->keys())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prediction_evaluations')
            ->where('prediction_table', 'prediction_feature_snapshots')->where('blend_version', NflPropProbabilityExperiment::POLICY)
            ->whereColumn('prediction_id', 'prediction_feature_snapshots.id'))->get();
        $players = Player::whereIn('id', $snapshots->map(fn ($s) => $s->model_metadata['player_id'] ?? null)->filter())->get()->keyBy('id');
        $stats = PlayerStat::with('player')->whereIn('game_id', $snapshots->pluck('game_id')->unique())->get()->groupBy('game_id');
        $rows = $excluded = $unique = [];
        foreach ($snapshots as $s) {
            $game = $games[$s->game_id];
            if ($reason = $this->validator->invalidReason($s, $game->sportEvent?->starts_at?->toIso8601String())) {
                $excluded[$reason] = ($excluded[$reason] ?? 0) + 1;

                continue;
            }
            $player = $players->get($s->model_metadata['player_id']);
            if (! $player) {
                $excluded['player_missing'] = ($excluded['player_missing'] ?? 0) + 1;

                continue;
            }
            $market = $s->market_context['market'];
            $gameStats = $stats->get($s->game_id, collect());
            $exact = $gameStats->where('player_id', $player->id);
            $stat = $exact->count() === 1 ? $exact->first() : null;
            $reason = $exact->count() > 1 ? 'ambiguous_stat_rows' : ($stat ? 'market_actual_or_participation_missing' : 'player_stat_row_missing');
            $rows[$market]['pending'][$reason] = ($rows[$market]['pending'][$reason] ?? 0) + 1;
            $unique[$s->game_id.':'.$player->id] = true;
            $normalize = fn ($name) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $name));
            $alternate = $gameStats->filter(fn ($x) => $x->player_id !== $player->id && $x->player
                && (int) $x->team_id === (int) $s->model_metadata['team_id']
                && ((! empty($player->espn_id) && (string) $x->player->espn_id === (string) $player->espn_id)
                    || $normalize($x->player->full_name) === $normalize($player->full_name)))->count();
            $identity = $alternate ? 'alternate_identity_requires_review' : 'no_alternate_identity';
            $rows[$market]['identity'][$identity] = ($rows[$market]['identity'][$identity] ?? 0) + 1;
            $ids = DB::table('nflverse_rosters')->where('season', $season)->where('team_id', $s->model_metadata['team_id'])
                ->where('espn_id', $player->espn_id)->pluck('gsis_id')->filter()->unique();
            $weekly = $ids->count() === 1 ? DB::table('nflverse_weekly_player_stats')->where('season', $season)
                ->where('nfl_game_id', $game->id)->where('team_id', $s->model_metadata['team_id'])->where('player_id', $ids->first())->count() : null;
            $source = $weekly === null ? 'roster_identity_unresolved' : ($weekly === 0 ? 'weekly_stat_missing' : ($weekly === 1 ? 'weekly_stat_candidate' : 'weekly_stat_ambiguous'));
            $rows[$market]['weekly_source'][$source] = ($rows[$market]['weekly_source'][$source] ?? 0) + 1;
            $archive = ProviderSourceFile::where('provider', 'espn')->where('dataset', 'nfl-boxscore')->where('metadata->game_id', $game->id)->exists();
            $source = $archive ? 'boxscore_archive_candidate' : 'boxscore_archive_missing';
            $rows[$market]['boxscore_source'][$source] = ($rows[$market]['boxscore_source'][$source] ?? 0) + 1;
        }

        return ['format' => 'nfl-prop-missing-actual-audit-v1', 'checked_at' => now()->toIso8601String(), 'season' => $season,
            'unique_player_games' => count($unique), 'excluded' => $excluded, 'markets' => $rows,
            'production_modified' => false, 'limitations' => ['Source candidates require content/identity/checksum validation before any repair.',
                'Missing categories, player rows, depth charts and play counts do not establish zero or DNP.']];
    }
}
