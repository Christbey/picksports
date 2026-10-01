<?php

namespace App\Services\NFL\Matchups;

use App\Models\NFL\DepthChartSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\GameDepthChartLink;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Services\Sports\SportsDateWindowService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Timestamped projected personnel, never a claim of observed game participation. */
final class NflMatchupPersonnel
{
    private const LINE = ['LT', 'LG', 'C', 'RG', 'RT'];

    public function forGame(Game $target, ?CarbonImmutable $cutoff): array
    {
        if (! $cutoff) {
            return [];
        }
        $asOf = $cutoff->min(CarbonImmutable::now());
        $result = [];
        foreach (['home', 'away'] as $side) {
            $teamId = (int) $target->{$side.'_team_id'};
            $link = GameDepthChartLink::with('snapshot.entries')->where('game_id', $target->id)->where('team_id', $teamId)->where('side', $side)
                ->where('as_of', '<=', $asOf)->where('observed_at', '<=', $asOf)->latest('as_of')->latest('id')->first();
            $snapshot = $link?->snapshot;
            $current = $this->validSnapshot($snapshot, $teamId, (int) $target->season, $asOf) ? $snapshot : null;
            $evidence = ['mode' => 'projected_personnel', 'game_id' => $target->id, 'team_id' => $teamId,
                'depth_chart_link_id' => $link?->id, 'depth_chart_snapshot_id' => $current?->id, 'as_of' => $asOf->toIso8601String()];
            $rows = [
                'ol_changed' => $this->missing('Five identified projected linemen and the previous week’s pregame chart are required.', $evidence),
                'ol_changed_two' => $this->missing('Five identified projected linemen and the previous week’s pregame chart are required.', $evidence),
                'ol_same_four' => $this->missing('Four consecutive weekly pregame projected lineups are required.', $evidence),
                'wr1_out' => $this->missing('A unique game-linked depth-rank-one WR and current injury evidence are required; multiple starting WR slots are ambiguous.', $evidence),
                'rb1_out' => $this->missing('A game-linked lead running back and current injury evidence are required.', $evidence),
                'backup_center' => $this->missing('A prior backup center promoted into the projected lineup is required.', $evidence),
            ];
            if (! $current) {
                $result[$teamId] = $rows;

                continue;
            }
            $injuries = PlayerInjurySnapshot::with('entries')->where('team_id', $teamId)->where('observed_at', '<=', $asOf)
                ->where('observed_at', '>=', $asOf->subDays(7))->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $asOf))
                ->latest('observed_at')->latest('id')->first();
            foreach (['RB' => ['rb1_out', 'running back'], 'WR' => ['wr1_out', 'wide receiver']] as $position => [$metric, $label]) {
                if ($position === 'WR' && $current->entries->filter(fn ($entry) => strtoupper((string) $entry->position_code) === 'WR' && (int) $entry->depth_rank === 1)->count() !== 1) {
                    continue;
                }
                $player = $this->position($current->entries, $position);
                if (! $player || ! $player->observed_at || $player->observed_at->gt($current->observed_at)
                    || $player->source_updated_at?->gt($current->observed_at)) {
                    continue;
                }
                $status = $this->outStatus($injuries, $player, $asOf, $cutoff);
                if ($status !== null) {
                    $rows[$metric] = ['display_value' => $status ? 'Lead '.$label.' listed unavailable' : 'No unavailable designation for lead '.$label]
                        + $this->known($status ? 1 : 0, ['player_id' => $player->espn_athlete_id, 'player_name' => $player->player?->full_name,
                            'injury_snapshot_id' => $injuries?->id] + $evidence);
                }
            }
            $line = $this->line($current);
            if ($line === null) {
                $result[$teamId] = $rows;

                continue;
            }
            $previous = Game::where('season', $target->season)->whereIn('season_type', ['2', 'regular', 'REG'])
                ->where('status', 'STATUS_FINAL')->where('week', '<', $target->week)
                ->whereDate('game_date', '<', $cutoff->toDateString())
                ->where(fn ($q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId))
                ->orderByDesc('week')->limit(3)->get();
            $history = [];
            $priorSnapshots = [];
            $expectedWeek = (int) $target->week - 1;
            foreach ($previous as $game) {
                if ((int) $game->week !== $expectedWeek) {
                    break;
                }
                $kickoff = app(SportsDateWindowService::class)->gameDateTimeUtc($game->getRawOriginal('game_date'), $game->game_time);
                if (! $kickoff || $kickoff->gte($cutoff)) {
                    break;
                }
                $prior = DepthChartSnapshot::with('entries')->where('team_id', $teamId)->where('season', $target->season)
                    ->where('observed_at', '<', $kickoff)->where('observed_at', '>=', $kickoff->subDays(7))
                    ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<', $kickoff))
                    ->latest('observed_at')->latest('id')->first();
                $priorLine = $prior ? $this->line($prior) : null;
                if ($priorLine === null) {
                    break;
                }
                $history[] = ['game_id' => $game->id, 'snapshot_id' => $prior->id, 'observed_at' => $prior->observed_at->toIso8601String(), 'lineup' => $priorLine];
                $priorSnapshots[] = $prior;
                $expectedWeek--;
            }
            $lineEvidence = ['lineup' => $line, 'previous_lineups' => $history] + $evidence;
            if ($history !== []) {
                $changes = count(array_diff_assoc($line, $history[0]['lineup']));
                $rows['ol_changed'] = $this->known($changes, $lineEvidence);
                $rows['ol_changed_two'] = $this->known($changes, $lineEvidence);
                $priorCenter = $history[0]['lineup']['C'];
                $priorBackup = $priorSnapshots[0]->entries->contains(fn ($e) => strtoupper((string) $e->position_code) === 'C'
                    && $e->depth_rank > 1 && (string) $e->espn_athlete_id === $line['C']);
                $priorStarter = $this->position($priorSnapshots[0]->entries, 'C');
                if ($line['C'] === $priorCenter) {
                    $rows['backup_center'] = $this->known(0, $lineEvidence);
                } elseif ($priorBackup && $priorStarter) {
                    $out = $this->outStatus($injuries, $priorStarter, $asOf, $cutoff);
                    if ($out === true) {
                        $rows['backup_center'] = $this->known(1, ['injury_snapshot_id' => $injuries->id, 'replaced_player_id' => $priorCenter] + $lineEvidence);
                    }
                }
            }
            if (count($history) === 3) {
                $same = collect($history)->every(fn ($row) => $row['lineup'] === $line);
                $rows['ol_same_four'] = $this->known($same ? 4 : 0, $lineEvidence);
            }
            foreach ($rows as $metric => &$sample) {
                $sample['display_value'] = ! $sample['eligible'] ? null : match ($metric) {
                    'ol_changed', 'ol_changed_two' => $sample['value'].' of 5 projected line positions changed',
                    'ol_same_four' => $sample['value'] === 4 ? 'Same five projected linemen across four game charts' : 'Projected lineups differ across the four game charts',
                    'wr1_out' => $sample['value'] ? 'Lead wide receiver listed unavailable' : 'No unavailable designation for lead wide receiver',
                    'rb1_out' => $sample['value'] ? 'Lead running back listed unavailable' : 'No unavailable designation for lead running back',
                    'backup_center' => $sample['value'] ? 'Prior backup center projected to replace unavailable starter' : 'Same projected center as the previous week',
                };
            }
            unset($sample);
            $result[$teamId] = $rows;
        }

        return $result;
    }

    private function validSnapshot(?DepthChartSnapshot $snapshot, int $team, int $season, CarbonImmutable $asOf): bool
    {
        return $snapshot && (int) $snapshot->team_id === $team && (int) $snapshot->season === $season
            && $snapshot->observed_at?->lte($asOf) && $snapshot->observed_at->gte($asOf->subDays(7))
            && (! $snapshot->source_updated_at || $snapshot->source_updated_at->lte($asOf));
    }

    private function position(Collection $entries, string $position): ?object
    {
        $rows = $entries->filter(fn ($e) => strtoupper((string) $e->position_code) === $position && (int) $e->depth_rank === 1 && filled($e->espn_athlete_id));

        return $rows->count() === 1 ? $rows->first() : null;
    }

    private function line(DepthChartSnapshot $snapshot): ?array
    {
        $line = [];
        foreach (self::LINE as $position) {
            $entry = $this->position($snapshot->entries, $position);
            if (! $entry || ! $entry->observed_at || $entry->observed_at->gt($snapshot->observed_at)
                || $entry->source_updated_at?->gt($snapshot->observed_at)) {
                return null;
            }
            $line[$position] = (string) $entry->espn_athlete_id;
        }

        return count(array_unique($line)) === 5 ? $line : null;
    }

    private function outStatus(?PlayerInjurySnapshot $snapshot, object $player, CarbonImmutable $asOf, CarbonImmutable $kickoff): ?bool
    {
        $entries = $snapshot?->entries->filter(fn ($e) => (string) $e->espn_athlete_id === (string) $player->espn_athlete_id
            && $e->observed_at?->lte($asOf) && (! $e->source_updated_at || $e->source_updated_at->lte($asOf))) ?? collect();
        // This provider stores the complete injury report, not every healthy player.
        // Absence is only negative evidence for an out designation when the report
        // is demonstrably complete and recent; it is never proof of health.
        if ($entries->isEmpty() && $snapshot && $snapshot->provider === 'espn'
            && is_array($snapshot->raw_payload) && count($snapshot->raw_payload) === $snapshot->entry_count
            && $snapshot->entry_count === $snapshot->entries->count()
            && $snapshot->observed_at->copy()->addDays(7)->gte($kickoff)
            && $snapshot->entries->every(fn ($e) => filled($e->espn_athlete_id) && $e->observed_at?->lte($asOf)
                && (! $e->source_updated_at || $e->source_updated_at->lte($asOf)))) {
            return false;
        }
        // Missing/incomplete reports and conflicting player statuses stay unknown.
        if ($entries->count() !== 1) {
            return null;
        }
        $entry = $entries->first();
        if ($entry->injury_date?->gt($kickoff) || $entry->return_date?->lt($kickoff->startOfDay())) {
            return null;
        }

        $status = strtolower(trim((string) $entry->status));
        $reserve = in_array($status, ['ir', 'injured reserve', 'reserve/injured', 'pup', 'reserve/pup'], true);
        $published = $entry->source_updated_at ?? $snapshot->observed_at;
        if (! $reserve && $published->copy()->addDays(7)->lt($kickoff)) {
            return null;
        }

        return match ($status) {
            'out', 'inactive', 'ir', 'injured reserve', 'suspended', 'suspension', 'reserve/injured', 'pup', 'reserve/pup' => true,
            'active', 'available' => false,
            default => null,
        };
    }

    private function known(int $value, array $evidence): array
    {
        return ['value' => $value, 'eligible' => true, 'rank' => null, 'games' => count($evidence['previous_lineups'] ?? []) + 1, 'plays' => null, 'personnel' => $evidence];
    }

    private function missing(string $reason, array $evidence): array
    {
        return ['value' => null, 'eligible' => false, 'rank' => null, 'games' => 0, 'plays' => null, 'identity_reason' => $reason, 'personnel' => $evidence];
    }
}
