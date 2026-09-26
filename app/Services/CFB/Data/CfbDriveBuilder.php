<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CfbDriveBuilder
{
    public const VERSION = '1';

    public function build(Game $game, bool $onlyChanged = false): array
    {
        $check = GameDataCheck::where('game_id', $game->id)->where('component', 'plays')->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
        if (! $check) {
            return ['state' => 'blocked', 'reason' => 'no_accepted_play_revision'];
        }
        $plays = Play::where('game_id', $game->id)->where('source_revision', $check->source_hash)->orderBy('sequence_number')->orderBy('id')->get();
        $previous = DB::table('cfb_drives')->where('game_id', $game->id)->where('source_revision', $check->source_hash)->get();
        $epaUpdated = $plays->max('epa_calculated_at');
        if ($onlyChanged && $previous->isNotEmpty() && $previous->every(fn ($d) => $d->quality === 'complete' && data_get(json_decode($d->data, true), 'normalizer_version') === self::VERSION)
            && (! $epaUpdated || $epaUpdated->lte(CarbonImmutable::parse($previous->min('updated_at'))))) {
            return ['state' => 'complete', 'drives' => $previous->count(), 'source_revision' => $check->source_hash, 'reused' => true];
        }
        $drives = [];
        $current = null;
        $beforeHome = 0;
        $beforeAway = 0;
        $errors = [];
        foreach ($plays as $play) {
            $raw = $play->source_state ?? [];
            $period = (int) $play->period;
            $half = $period <= 2 ? 1 : ($period <= 4 ? 2 : $period);
            $provider = data_get($raw, 'drive.id') ?? data_get($raw, 'drive.$ref');
            $offense = $play->possession_team_id;
            $type = strtolower((string) $play->play_type.' '.(string) $play->play_text);
            $try = (bool) preg_match('/extra point|two.point|2.point|conversion|pat good|pat failed/', $type);
            $kickoff = str_contains($type, 'kickoff') || str_contains($type, 'kick off');
            $noPlay = str_contains($type, 'no play');
            $deltaHome = (int) $play->home_score - $beforeHome;
            $deltaAway = (int) $play->away_score - $beforeAway;
            if ($deltaHome < 0 || $deltaAway < 0) {
                $errors[] = 'nonmonotonic_score';
            }
            if (! $deltaHome && ! $deltaAway && preg_match('/end of (quarter|half|game|[1-4](st|nd|rd|th))|timeout|two.minute|coin toss/', $type)) {
                if ($current && in_array($period, [2, 4], true) && $play->clock === '0:00') {
                    $current['ended'] = true;
                }

                continue;
            }
            if ($kickoff && ($deltaHome || $deltaAway)) {
                // A return score is a real scoring possession even without a scrimmage play.
                $offense = $deltaHome > 0 ? $game->home_team_id : $game->away_team_id;
                if ($current) {
                    $drives[] = $current;
                    $current = null;
                }
                $kickoff = false;
            }
            if ($kickoff || ! $offense || $noPlay) {
                if ($deltaHome || $deltaAway) {
                    $errors[] = 'non_drive_score_requires_separate_model';
                }
                $beforeHome = (int) $play->home_score;
                $beforeAway = (int) $play->away_score;

                continue;
            }
            $new = $current === null || $current['half'] !== $half || (! $try && ($current['offense_team_id'] !== $offense || $current['ended'] || ($provider && $current['provider'] && $provider !== $current['provider'])));
            if ($new) {
                if ($current) {
                    $drives[] = $current;
                }
                $current = ['kind' => str_contains($type, 'kickoff') ? 'return' : 'scrimmage', 'offense_team_id' => $offense, 'half' => $half, 'period' => $period, 'provider' => $provider, 'start_play_id' => $play->espn_play_id,
                    'start_yards_to_endzone' => $play->yards_to_endzone,
                    'start_margin' => $offense === $game->home_team_id ? $beforeHome - $beforeAway : $beforeAway - $beforeHome, 'start_clock' => $play->clock, 'end_clock' => $play->clock,
                    'offense_points' => 0, 'opponent_points' => 0, 'plays' => 0, 'dropbacks' => 0, 'rushes' => 0, 'pass_yards' => 0, 'rush_yards' => 0,
                    'successes' => 0, 'explosives' => 0, 'epa_sum' => 0.0, 'epa_count' => 0, 'play_observations' => [], 'ended' => false];
            }
            $home = $current['offense_team_id'] === $game->home_team_id;
            $current['offense_points'] += $home ? $deltaHome : $deltaAway;
            $current['opponent_points'] += $home ? $deltaAway : $deltaHome;
            $current['end_play_id'] = $play->espn_play_id;
            $current['end_clock'] = $play->clock;
            $current['end_period'] = $period;
            if (! $try) {
                $pass = str_contains($type, 'pass') || str_contains($type, 'sack');
                $rush = ! $pass && preg_match('/rush|run |rushing/', $type) && ! preg_match('/kneel|spike/', $type);
                if ($pass || $rush) {
                    $current['play_observations'][] = ['kind' => $pass ? 'pass' : 'rush', 'down' => $play->down,
                        'distance' => $play->distance, 'yards_to_endzone' => $play->yards_to_endzone,
                        'yards' => $play->yards_gained, 'epa' => $play->true_epa === null ? null : (float) $play->true_epa,
                        'sack' => str_contains($type, 'sack'), 'period' => $period,
                        'margin' => $offense === $game->home_team_id ? $beforeHome - $beforeAway : $beforeAway - $beforeHome];
                    $current['plays']++;
                    $current[$pass ? 'dropbacks' : 'rushes']++;
                    $current[$pass ? 'pass_yards' : 'rush_yards'] += (int) $play->yards_gained;
                    if (is_numeric($play->distance) && is_numeric($play->yards_gained)) {
                        $required = (int) $play->down === 1 ? .5 : ((int) $play->down === 2 ? .7 : 1.0);
                        $current['successes'] += (int) ($play->yards_gained >= $required * $play->distance);
                    }
                    $current['explosives'] += (int) ($play->yards_gained >= ($pass ? 20 : 10));
                    if ($play->true_epa !== null) {
                        $current['epa_sum'] += (float) $play->true_epa;
                        $current['epa_count']++;
                    }
                }
            }
            $current['ended'] = $current['ended'] || (bool) $play->is_turnover || (bool) $play->is_scoring_play || (bool) preg_match('/punt|end of half|end of game|turnover on downs|field goal/', $type);
            $beforeHome = (int) $play->home_score;
            $beforeAway = (int) $play->away_score;
        }
        if ($current) {
            $drives[] = $current;
        }
        $sum = array_sum(array_column($drives, 'offense_points')) + array_sum(array_column($drives, 'opponent_points'));
        if ($game->status === 'STATUS_FINAL' && $sum !== (int) $game->home_score + (int) $game->away_score) {
            $errors[] = 'score_not_reconciled';
        }
        $rows = [];
        foreach ($drives as $index => $drive) {
            $drive['duration_seconds'] = max(0, $this->seconds($drive['start_clock']) - $this->seconds($drive['end_clock']) + 900 * (($drive['end_period'] ?? $drive['period']) - $drive['period']));
            $drive['normalizer_version'] = self::VERSION;
            $rows[] = ['game_id' => $game->id, 'source_revision' => $check->source_hash, 'drive_key' => (string) $index,
                'offense_team_id' => $drive['offense_team_id'], 'quality' => $errors ? 'partial' : 'complete', 'data' => json_encode($drive, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::transaction(function () use ($game, $check, $rows) {
            Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            DB::table('cfb_drives')->where('game_id', $game->id)->where('source_revision', $check->source_hash)->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('cfb_drives')->insert($chunk);
            }
        });

        return ['state' => $errors ? 'partial' : 'complete', 'drives' => count($rows), 'source_revision' => $check->source_hash, 'errors' => array_values(array_unique($errors))];
    }

    private function seconds(string $clock): int
    {
        $parts = explode(':', $clock);

        return (int) ($parts[0] ?? 0) * 60 + (int) ($parts[1] ?? 0);
    }
}
