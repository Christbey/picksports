<?php

namespace App\Services\CBB;

use App\Models\CBB\Game;
use App\Models\CBB\PlayerStat;
use App\Models\CBB\RosterSnapshot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CbbRosterStrength
{
    private array $samples = [];

    private array $rosters = [];

    private ?CarbonImmutable $asOf = null;

    public function observationTime(): CarbonImmutable
    {
        return $this->asOf ??= CarbonImmutable::now(config('app.timezone'));
    }

    public function forTeam(int $teamId, int $season, array $priorRatings, CarbonInterface $asOf): array
    {
        $key = $teamId.':'.$season.':'.$asOf->toISOString();
        if (! array_key_exists($key, $this->rosters)) {
            $this->rosters[$key] = RosterSnapshot::knownAt($teamId, $season, $asOf)?->load('entries');
        }
        $roster = $this->rosters[$key];
        $base = ['available' => false, 'season' => $season, 'as_of' => $asOf->toISOString(), 'snapshot_id' => $roster?->id,
            'observed_at' => $roster?->observed_at->toISOString(), 'roster_hash' => $roster?->content_hash,
            'method' => 'returning_minutes_and_incoming_program_strength_v1', 'validation' => 'experimental'];
        if (! $roster) {
            return [...$base, 'reason' => 'season_roster_unavailable'];
        }
        if ($roster->observed_at->lt($asOf->copy()->subHours((int) config('cbb.prediction.roster.maximum_age_hours', 72)))) {
            return [...$base, 'reason' => 'season_roster_stale'];
        }
        $data = $this->sample($season - 1, $asOf);
        $own = $data['teams'][$teamId] ?? null;
        $members = $roster->entries->pluck('player_id')->map(fn ($id): int => (int) $id)->all();
        $previous = RosterSnapshot::knownAt($teamId, $season - 1, $asOf)?->load('entries');
        $oldMembers = $previous?->entries->pluck('player_id')->all() ?? [];
        $returningMembers = count(array_intersect($members, $oldMembers));
        $summary = [...$base, 'member_ids' => $members, 'prior_source_hash' => $data['hash'], 'prior_games' => $own['games'] ?? 0,
            'prior_roster_snapshot_id' => $previous?->id, 'prior_roster_hash' => $previous?->content_hash,
            'returning_roster_players' => $previous ? $returningMembers : null,
            'incoming_roster_players' => $previous ? count($members) - $returningMembers : null,
            'membership_classification' => $previous ? 'verified_season_rosters' : 'prior_season_roster_unavailable'];
        $minimum = max(1, (int) config('cbb.prediction.season_inputs.transition_games', 8));
        if (! $own || $own['games'] < $minimum || ! isset($priorRatings[$teamId])) {
            return [...$summary, 'reason' => 'insufficient_prior_player_box_scores'];
        }
        $returning = $incoming = $unmeasured = 0;
        $minutes = $points = $incomingWeight = $incomingValue = 0.0;
        foreach ($members as $playerId) {
            $sources = $data['players'][$playerId] ?? [];
            if (isset($sources[$teamId])) {
                $returning++;
                $minutes += $sources[$teamId]['minutes'];
                $points += $sources[$teamId]['points'];

                continue;
            }
            $playerWeight = $playerValue = 0.0;
            foreach ($sources as $sourceTeam => $stats) {
                if (($data['teams'][$sourceTeam]['games'] ?? 0) < $minimum || ! isset($priorRatings[$sourceTeam])) {
                    continue;
                }
                $weight = $stats['minutes'] / max(1, $stats['games']);
                $playerWeight += $weight;
                $playerValue += $weight * $priorRatings[$sourceTeam];
            }
            if ($playerWeight > 0) {
                $incoming++;
                $incomingWeight += $playerWeight;
                $incomingValue += $playerValue;
            } else {
                $unmeasured++;
            }
        }
        $coverage = ($returning + $incoming) / max(1, count($members));
        $retained = min(1.0, $minutes / max(1, $own['minutes']));
        $incomingRating = $incomingWeight > 0 ? $incomingValue / $incomingWeight : null;
        $summary = [...$summary, 'returning_measured_players' => $returning, 'incoming_measured_players' => $incoming, 'unmeasured_players' => $unmeasured,
            'returning_minutes_share' => round($retained, 6), 'returning_points_share' => round($points / max(1, $own['points']), 6),
            'historical_player_coverage' => round($coverage, 6), 'incoming_program_rating' => $incomingRating === null ? null : round($incomingRating, 6),
            'baseline_prior_rating' => round($priorRatings[$teamId], 6)];
        if ($returning + $incoming < 5 || $coverage < 0.5 || ($retained < 1 && $incomingRating === null)) {
            return [...$summary, 'reason' => 'insufficient_incoming_player_measurements'];
        }
        $replacementWeight = (1 - $retained) * $coverage;
        $candidate = (1 - $replacementWeight) * $priorRatings[$teamId] + $replacementWeight * ($incomingRating ?? $priorRatings[$teamId]);

        return [...$summary, 'available' => true, 'reason' => null, 'candidate_prior_rating' => round($candidate, 6), 'prior_adjustment' => round($candidate - $priorRatings[$teamId], 6)];
    }

    private function sample(int $season, CarbonInterface $asOf): array
    {
        $asOf = CarbonImmutable::instance($asOf)->setTimezone(config('app.timezone'));
        $key = $season.':'.$asOf->toISOString();
        if (isset($this->samples[$key])) {
            return $this->samples[$key];
        }
        $teams = $players = [];
        $hash = hash_init('sha256');
        $quality = app(CbbDataQuality::class);
        $quality->reconciledGames(Game::query())->where('season', $season)->where('game_date', '<', $asOf->toDateString())
            ->whereColumn('home_score', '!=', 'away_score')->with('teamStats')->orderBy('id')->chunkById(250, function ($games) use (&$teams, &$players, $hash, $asOf, $quality): void {
                $games = $games->filter(fn (Game $g): bool => $quality->boxScoreIssues($g, $g->teamStats) === []);
                $rows = PlayerStat::query()->whereIn('game_id', $games->modelKeys())->where('created_at', '<=', $asOf)->where('updated_at', '<=', $asOf)->orderBy('id')->get(['id', 'game_id', 'team_id', 'player_id', 'points', 'minutes_played'])->groupBy(fn ($s): string => $s->game_id.':'.$s->team_id);
                foreach ($games as $game) {
                    foreach ([$game->home_team_id => $game->home_score, $game->away_team_id => $game->away_score] as $teamId => $score) {
                        $stats = $rows->get($game->id.':'.$teamId, collect());
                        $totalMinutes = $stats->sum(fn ($s): float => $this->minutes($s->minutes_played));
                        if ($stats->sum('points') !== (int) $score || $totalMinutes < 190 || $totalMinutes > 300 || $stats->pluck('player_id')->unique()->count() !== $stats->count()) {
                            continue;
                        }
                        $teams[$teamId] ??= ['games' => 0, 'minutes' => 0.0, 'points' => 0.0];
                        $teams[$teamId]['games']++;
                        $teams[$teamId]['minutes'] += $totalMinutes;
                        $teams[$teamId]['points'] += $score;
                        foreach ($stats as $stat) {
                            $minutes = $this->minutes($stat->minutes_played);
                            if ($minutes <= 0 || $stat->points < 0) {
                                continue;
                            }
                            $players[$stat->player_id][$teamId] ??= ['games' => 0, 'minutes' => 0.0, 'points' => 0.0];
                            $p = &$players[$stat->player_id][$teamId];
                            $p['games']++;
                            $p['minutes'] += $minutes;
                            $p['points'] += $stat->points;
                            unset($p);
                            hash_update($hash, json_encode([$stat->id, $game->id, $teamId, $stat->player_id, round($minutes, 6), (int) $stat->points]));
                        }
                    }
                }
            });

        return $this->samples[$key] = ['teams' => $teams, 'players' => $players, 'hash' => hash_final($hash)];
    }

    private function minutes(mixed $value): float
    {
        if (is_numeric($value)) {
            return max(0.0, min(60.0, (float) $value));
        }
        if (is_string($value) && preg_match('/^(\d{1,2}):([0-5]\d)$/', $value, $parts)) {
            return min(60.0, (float) $parts[1] + (float) $parts[2] / 60);
        }

        return 0.0;
    }
}
