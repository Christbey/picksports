<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Use the archived official scoring ledger; raw play score snapshots remain unchanged. */
class CfbScoringLedger
{
    public function normalize(Game $game, Collection $plays): Collection
    {
        $check = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')
            ->where('state', 'complete')->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
        if (! $check) {
            return $plays;
        }
        $json = Storage::disk(config('cfb.data.source_disk', 'local'))->get($check->source_path);
        if (! is_string($json) || ! hash_equals($check->source_hash, hash('sha256', $json))) {
            throw new RuntimeException('Scoring ledger source hash mismatch.');
        }
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        // Older archives without a ledger still undergo the original drive checks.
        if (! array_key_exists('scoringPlays', $payload)) {
            return $plays;
        }
        $competition = data_get($payload, 'header.competitions.0');
        if ((string) data_get($payload, 'header.id') !== (string) $game->espn_event_id
            || data_get($competition, 'status.type.name') !== 'STATUS_FINAL' || $game->status !== 'STATUS_FINAL') {
            throw new RuntimeException('Scoring ledger requires the matching final event.');
        }
        foreach (['home', 'away'] as $side) {
            $competitor = collect($competition['competitors'] ?? [])->firstWhere('homeAway', $side);
            if ((string) data_get($competitor, 'team.id') !== (string) $game->{$side.'Team'}->espn_id
                || ! is_numeric($competitor['score'] ?? null) || (int) $competitor['score'] !== (int) $game->{$side.'_score'}) {
                throw new RuntimeException('Scoring ledger final teams or scores disagree.');
            }
        }
        $ledger = $payload['scoringPlays'];
        if (! is_array($ledger)) {
            throw new RuntimeException('Invalid scoring ledger.');
        }
        $positions = $plays->values()->pluck('espn_play_id')->flip();
        $scores = [];
        $home = $away = 0;
        $previousPosition = -1;
        foreach ($ledger as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $position = $positions->get($id);
            $nextHome = $entry['homeScore'] ?? null;
            $nextAway = $entry['awayScore'] ?? null;
            if ($id === '' || isset($scores[$id]) || $position === null || $position <= $previousPosition
                || ! is_numeric($nextHome) || ! is_numeric($nextAway)) {
                throw new RuntimeException('Scoring ledger is incomplete or out of order.');
            }
            $deltaHome = (int) $nextHome - $home;
            $deltaAway = (int) $nextAway - $away;
            $team = (string) data_get($entry, 'team.id');
            if (! (($deltaHome > 0 && $deltaAway === 0 && $team === (string) $game->homeTeam->espn_id)
                || ($deltaAway > 0 && $deltaHome === 0 && $team === (string) $game->awayTeam->espn_id))
                || ! in_array($deltaHome + $deltaAway, [1, 2, 3, 6, 7, 8], true)) {
                throw new RuntimeException('Scoring ledger contains an unsupported scoring transition.');
            }
            $home = (int) $nextHome;
            $away = (int) $nextAway;
            $scores[$id] = [$home, $away];
            $previousPosition = $position;
        }
        if ($home !== (int) $game->home_score || $away !== (int) $game->away_score) {
            throw new RuntimeException('Scoring ledger does not reconcile both final scores.');
        }
        $home = $away = 0;

        return $plays->map(function ($play) use ($scores, &$home, &$away) {
            $normalized = clone $play;
            if (isset($scores[(string) $play->espn_play_id])) {
                [$home, $away] = $scores[(string) $play->espn_play_id];
                $normalized->is_scoring_play = true;
                $normalized->scoring_ledger_verified = true;
            }
            $normalized->home_score = $home;
            $normalized->away_score = $away;

            return $normalized;
        });
    }
}
