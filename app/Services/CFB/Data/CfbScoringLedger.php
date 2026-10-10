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
    public const VERSION = '2';

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
        $eventScores = [];
        $home = $away = 0;
        $eventHome = $eventAway = 0;
        $snapshotsValid = $eventsProven = true;
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
            if (! in_array($team, [(string) $game->homeTeam->espn_id, (string) $game->awayTeam->espn_id], true)) {
                throw new RuntimeException('Scoring ledger contains an unknown scoring team.');
            }
            if (! (($deltaHome > 0 && $deltaAway === 0 && $team === (string) $game->homeTeam->espn_id)
                || ($deltaAway > 0 && $deltaHome === 0 && $team === (string) $game->awayTeam->espn_id))
                || ! in_array($deltaHome + $deltaAway, [1, 2, 3, 6, 7, 8], true)) {
                $snapshotsValid = false;
            }
            $points = $this->explicitEventPoints($entry);
            $eventsProven = $eventsProven && $points !== null;
            if ($team === (string) $game->homeTeam->espn_id) {
                $eventHome += $points ?? 0;
            } else {
                $eventAway += $points ?? 0;
            }
            $eventScores[$id] = [$eventHome, $eventAway];
            $home = (int) $nextHome;
            $away = (int) $nextAway;
            $scores[$id] = [$home, $away];
            $previousPosition = $position;
        }
        if (! $snapshotsValid || $home !== (int) $game->home_score || $away !== (int) $game->away_score) {
            if (! $eventsProven || $eventHome !== (int) $game->home_score || $eventAway !== (int) $game->away_score) {
                throw new RuntimeException('Scoring ledger does not reconcile both final scores from verified transitions or explicit scoring events.');
            }
            $scores = $eventScores;
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

    /** Only explicit successful scoring events and observed conversion outcomes qualify. */
    private function explicitEventPoints(array $entry): ?int
    {
        $type = strtolower((string) data_get($entry, 'type.text', ''));
        $kind = strtolower((string) data_get($entry, 'scoringType.name', ''));
        $text = strtolower((string) ($entry['text'] ?? ''));
        if ($kind === 'touchdown' || str_contains($type, 'touchdown')) {
            if (preg_match('/two[- ]point.*conversion.*failed|kick failed|kick no good|extra point.*(?:failed|no good|blocked)/', $text)) {
                return 6;
            }
            if (preg_match('/\([^()]*\bkick\)|extra point good/', $text)) {
                return 7;
            }
            if (preg_match('/two[- ]point.*conversion.*(?:good|successful)/', $text)) {
                return 8;
            }

            return null;
        }
        if ($type === 'field goal good') {
            return 3;
        }
        if ($type === 'safety') {
            return 2;
        }
        if ($type === 'extra point good') {
            return 1;
        }
        if (in_array($type, ['two point pass', 'two point rush', 'two point run'], true)
            && str_contains($text, 'conversion') && ! preg_match('/failed|no good|blocked|no play/', $text)) {
            return 2;
        }

        return null;
    }
}
