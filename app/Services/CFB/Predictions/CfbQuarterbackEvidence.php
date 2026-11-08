<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerStat;
use App\Services\CFB\Data\CfbDataReadiness;
use Carbon\CarbonImmutable;

/** Participant-linked EPA is a provisional personnel estimate, not a calibrated QB rating. */
class CfbQuarterbackEvidence
{
    public const VERSION = 'cfb-qb-participant-epa-v1';

    public function capture(Game $target, int $teamId, array $availability, array $injuries, CarbonImmutable $captured, CarbonImmutable $cutoff): array
    {
        $result = ['version' => self::VERSION, 'status' => 'not_required', 'confirmed_starter' => false,
            'replacement_player_id' => null, 'offense_points_adjustment' => null,
            'limitations' => ['Historical EPA includes teammates and opponent effects.',
                'External team ratings may already reflect some injury news.',
                'Participation does not confirm the next starter; estimates are not prospectively validated.']];
        $starter = $availability['last_observed_player_id'] ?? null;
        $injury = collect($injuries)->first(fn ($i) => ($i['player_id'] ?? null) === $starter);
        if (! $starter || ! $injury || ! in_array(strtolower($injury['status']), ['out', 'doubtful'], true)) {
            return $result;
        }
        $result += ['starter_player_id' => $starter, 'absence_status' => strtolower($injury['status'])];
        if (strtolower($injury['status']) !== 'out') {
            return [...$result, 'status' => 'absence_unconfirmed'];
        }
        $result['status'] = 'replacement_unresolved';
        $players = Player::where('team_id', $teamId)->where('position', 'QB')
            ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)->get()->keyBy('id');
        $rows = PlayerStat::with('game')->where('team_id', $teamId)->whereIn('player_id', $players->keys())
            ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)->where('passing_attempts', '>', 0)
            ->whereHas('game', fn ($q) => $q->where('status', 'STATUS_FINAL')->where('id', '!=', $target->id)
                ->whereIn('season_type', ['2', '3', 'regular', 'postseason'])->whereBetween('season', [$target->season - 1, $target->season])
                ->whereDate('game_date', '<', $cutoff->setTimezone('America/Chicago')->toDateString())
                ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff))->get();
        $unavailable = collect($injuries)->pluck('player_id');
        $candidates = $rows->whereNotIn('player_id', $unavailable)->groupBy('player_id')
            ->map(fn ($r) => ['player_id' => (int) $r->first()->player_id, 'name' => $players[$r->first()->player_id]->full_name,
                'passing_attempts' => $r->sum('passing_attempts'), 'current_attempts' => $r->filter(fn ($s) => $s->game->season === $target->season)->sum('passing_attempts')])
            ->sortByDesc('current_attempts')->values();
        $result['candidates'] = $candidates->all();
        // Never pick between multiple plausible backups using efficiency or a jersey number.
        if ($candidates->count() !== 1) {
            return $result;
        }
        $replacement = $candidates->first();
        $result['replacement_player_id'] = $replacement['player_id'];
        $result['replacement_name'] = $replacement['name'];
        $result['identity_basis'] = 'only_available_observed_backup_not_confirmed_starter';
        $result['status'] = 'insufficient_play_evidence';
        $profiles = [];
        $baseline = ['plays' => 0, 'epa' => 0.0, 'games' => []];
        $expectedCurrentGames = $rows->filter(fn ($s) => $s->game->season === $target->season)->pluck('game_id')->unique()->count();
        $expectedAttempts = $rows->groupBy('player_id')->map(fn ($r) => $r->sum('passing_attempts'));
        $attributedPasses = [];
        $rejectedGames = [];
        $latest = collect([$players->max('updated_at'), $rows->max('updated_at')])->filter()->max();
        foreach ($rows->pluck('game')->unique('id') as $game) {
            $quality = app(CfbDataReadiness::class)->forGame($game);
            $revision = data_get($quality, 'components.plays.source_hash');
            $proofs = GameDataCheck::where('game_id', $game->id)->whereIn('component', ['boxscore', 'plays'])
                ->whereNotNull('accepted_at')->where('accepted_at', '<=', $captured)->where('accepted_at', '<', $cutoff)
                ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)->get();
            if (! $quality['ready'] || ! $revision || ! $proofs->contains(fn ($p) => $p->component === 'plays' && $p->source_hash === $revision)
                || ! $proofs->contains(fn ($p) => $p->component === 'boxscore' && $p->source_hash === data_get($quality, 'components.boxscore.source_hash'))) {
                $rejectedGames[] = $game->id;

                continue;
            }
            foreach ($proofs as $proof) {
                if ($latest === null || $proof->updated_at->gt($latest)) {
                    $latest = $proof->updated_at;
                }
            }
            $plays = Play::where('game_id', $game->id)->where('possession_team_id', $teamId)->where('source_revision', $revision)
                ->where('updated_at', '<=', $captured)->where('updated_at', '<', $cutoff)
                ->where('epa_calculated_at', '<=', $captured)->where('epa_calculated_at', '<', $cutoff)
                ->where('is_epa_eligible', true)->whereNotNull('true_epa')->get()
                ->unique(fn ($p) => $p->espn_play_id ?: 'row:'.$p->id);
            foreach ($plays as $play) {
                if ($play->is_penalty || preg_match('/kneel|spike/i', $play->play_type.' '.$play->play_text)
                    || ($play->period >= 3 && abs($play->home_score - $play->away_score) >= 21)) {
                    continue;
                }
                $ids = collect(data_get($play->source_state, 'participants', []))->filter(fn ($p) => in_array($p['type'] ?? '', ['passer', 'rusher'], true))
                    ->map(function ($p) {
                        $id = data_get($p, 'athlete.id');
                        if (! $id && preg_match('~/athletes/(\d+)(?:[/?]|$)~', (string) data_get($p, 'athlete.$ref'), $m)) {
                            $id = $m[1];
                        }

                        return $id === null ? null : (string) $id;
                    })->filter()->unique();
                $qbs = $players->filter(fn ($p) => $ids->contains((string) $p->espn_id));
                if ($qbs->count() !== 1 || ! is_finite((float) $play->true_epa)) {
                    continue;
                }
                $id = $qbs->first()->id;
                if (collect(data_get($play->source_state, 'participants', []))->contains(fn ($p) => ($p['type'] ?? null) === 'passer')) {
                    $attributedPasses[$id] = ($attributedPasses[$id] ?? 0) + 1;
                }
                $weight = $game->season === $target->season ? 1.0 : 0.5;
                $profiles[$id] ??= ['player_id' => $id, 'plays' => 0, 'weighted_plays' => 0.0, 'epa' => 0.0, 'games' => [], 'play_ids' => []];
                $profiles[$id]['plays']++;
                $profiles[$id]['weighted_plays'] += $weight;
                $profiles[$id]['epa'] += (float) $play->true_epa * $weight;
                $profiles[$id]['games'][$game->id] = true;
                $profiles[$id]['play_ids'][] = $play->id;
                if ($game->season === $target->season) {
                    $baseline['plays']++;
                    $baseline['epa'] += (float) $play->true_epa;
                    $baseline['games'][$game->id] = true;
                }
                $at = $play->updated_at->max($play->epa_calculated_at);
                $latest = $latest === null || $at->gt($latest) ? $at : $latest;
            }
        }
        foreach ($profiles as &$profile) {
            $profile['games'] = array_keys($profile['games']);
            $expected = $expectedAttempts->get($profile['player_id'], 0);
            $profile['passing_identity_coverage'] = $expected > 0 ? min(1, ($attributedPasses[$profile['player_id']] ?? 0) / $expected) : 0;
            $profile['epa_per_play'] = $profile['epa'] / $profile['weighted_plays'];
        }
        unset($profile);
        $result['profiles'] = array_values($profiles);
        $result['baseline'] = ['plays' => $baseline['plays'], 'games' => array_keys($baseline['games']),
            'current_game_coverage' => $expectedCurrentGames ? count($baseline['games']) / $expectedCurrentGames : 0,
            'epa_per_play' => $baseline['plays'] ? $baseline['epa'] / $baseline['plays'] : null,
            'plays_per_game' => count($baseline['games']) ? $baseline['plays'] / count($baseline['games']) : null];
        $result['rejected_game_ids'] = $rejectedGames;
        $result['source_available_at'] = $latest?->toIso8601String();
        $result['status'] = 'captured';

        return $result;
    }
}
