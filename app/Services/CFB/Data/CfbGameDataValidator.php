<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;

/** Validates source completeness independently of the existence of imported rows. */
class CfbGameDataValidator
{
    public const VERSION = '2';

    public const RECONCILED_PLAYS_VERSION = '3-ledger';

    public function boxscore(array $payload, Game $game): array
    {
        $errors = [];
        $expected = [(string) $game->homeTeam->espn_id, (string) $game->awayTeam->espn_id];
        $teams = $payload['boxscore']['teams'] ?? [];
        $players = $payload['boxscore']['players'] ?? [];
        foreach (['teams' => $teams, 'players' => $players] as $kind => $rows) {
            $ids = array_map(fn ($r) => (string) data_get($r, 'team.id'), $rows);
            sort($ids);
            $sorted = $expected;
            sort($sorted);
            if ($ids !== $sorted || count(array_unique($ids)) !== 2) {
                $errors[] = $kind.':expected_two_game_teams';
            }
            foreach ($rows as $row) {
                if (empty($row['statistics'])) {
                    $errors[] = $kind.':missing_statistics';
                }
            }
        }
        $teamBuckets = [];
        foreach ($teams as $team) {
            $id = (string) data_get($team, 'team.id');
            $stats = collect($team['statistics'] ?? [])->pluck('displayValue', 'name');
            foreach (['totalYards', 'netPassingYards', 'rushingYards'] as $key) {
                if (! is_numeric($stats[$key] ?? null)) {
                    $errors[] = $id.':missing_'.$key;
                }
            }
            if (is_numeric($stats['totalYards'] ?? null) && is_numeric($stats['netPassingYards'] ?? null) && is_numeric($stats['rushingYards'] ?? null)
                && (int) $stats['totalYards'] !== (int) $stats['netPassingYards'] + (int) $stats['rushingYards']) {
                $errors[] = $id.':team_yards_do_not_reconcile';
            }
            $categories = collect(collect($players)->first(fn ($r) => (string) data_get($r, 'team.id') === $id)['statistics'] ?? []);
            foreach (['passing' => 'netPassingYards', 'rushing' => 'rushingYards'] as $name => $field) {
                $category = $categories->first(fn ($r) => strtolower($r['name'] ?? '') === $name);
                // An explicit zero still needs a source category; absence is not observed zero.
                if (! $category || ! isset($category['athletes'])) {
                    $errors[] = $id.':missing_'.$name;

                    continue;
                }
                $sum = 0;
                foreach ($category['athletes'] as $athlete) {
                    $labels = $category['labels'] ?? [];
                    if (count($labels) !== count($athlete['stats'] ?? [])) {
                        $errors[] = $id.':truncated_player_stats';

                        continue;
                    }
                    $mapped = array_combine($labels, $athlete['stats']);
                    if (! is_numeric($mapped['YDS'] ?? null)) {
                        $errors[] = $id.':missing_player_yards';

                        continue;
                    }
                    $sum += (int) $mapped['YDS'];
                    $athleteId = (string) data_get($athlete, 'athlete.id');
                    $nameValue = data_get($athlete, 'athlete.displayName') ?? data_get($athlete, 'athlete.fullName');
                    if (! preg_match('/^-?\d+$/', $athleteId) || ! $nameValue) {
                        $errors[] = $id.':missing_athlete_identity';
                    }
                    if ((int) $athleteId <= 0) {
                        $teamBuckets[$id][$name][] = $mapped;
                    }
                }
                // NCAA sacks are charged to rushing. Provider categories share these definitions.
                if (is_numeric($stats[$field] ?? null) && $sum !== (int) $stats[$field]) {
                    $errors[] = $id.':'.$name.'_yards_do_not_reconcile';
                }
            }
        }

        return ['state' => $errors ? 'partial' : 'complete', 'discrepancies' => array_values(array_unique($errors)), 'evidence' => ['team_attributed_stats' => $teamBuckets]];
    }

    public function plays(array $payload, Game $game): array
    {
        $items = $payload['items'] ?? [];
        $errors = [];
        if (! $items) {
            $errors[] = 'empty_plays';
        }
        $ids = array_map(fn ($p) => (string) ($p['id'] ?? ''), $items);
        if (in_array('', $ids, true) || count(array_unique($ids)) !== count($ids)) {
            $errors[] = 'invalid_or_duplicate_play_ids';
        }
        if (! isset($payload['count']) || (int) $payload['count'] !== count($items)) {
            $errors[] = 'unproven_pagination_completeness';
        }
        if ($game->status === 'STATUS_FINAL' && $items) {
            $last = $items[array_key_last($items)];
            if ((int) ($last['homeScore'] ?? -1) !== (int) $game->home_score || (int) ($last['awayScore'] ?? -1) !== (int) $game->away_score) {
                $errors[] = 'final_score_mismatch';
            }
            if (! preg_match('/end of (game|4th|fourth|overtime)|game ended/i', (string) ($last['text'] ?? ''))
                && ! ((int) data_get($last, 'period.number') >= 4 && data_get($last, 'clock.displayValue') === '0:00')
                && (int) data_get($last, 'period.number') <= 4) {
                $errors[] = 'unverified_terminal_state';
            }
        }

        $evidence = ['plays' => count($items), 'declared_count' => $payload['count'] ?? null];
        if ($errors === ['final_score_mismatch']) {
            try {
                $source = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')
                    ->where('state', 'complete')->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
                if ($source) {
                    $plays = collect($items)->map(fn ($play) => new Play([
                        'espn_play_id' => (string) $play['id'], 'home_score' => $play['homeScore'] ?? null,
                        'away_score' => $play['awayScore'] ?? null,
                    ]));
                    $normalized = app(CfbScoringLedger::class)->normalize($game, $plays);
                    if ($normalized->contains(fn ($play) => (bool) $play->scoring_ledger_verified)
                        && (int) $normalized->last()->home_score === (int) $game->home_score
                        && (int) $normalized->last()->away_score === (int) $game->away_score) {
                        $errors = [];
                        $evidence['score_snapshot_reconciliation'] = ['boxscore_source_hash' => $source->source_hash,
                            'ledger_version' => CfbScoringLedger::VERSION, 'raw_score_snapshots_preserved' => true];
                    }
                }
            } catch (\Throwable) {
                // A contradictory ledger or missing score event cannot certify the play source.
            }
        }

        return ['state' => $errors ? 'partial' : 'complete', 'discrepancies' => $errors, 'evidence' => $evidence];
    }
}
