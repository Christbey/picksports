<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\PlayerInjurySnapshot;
use App\Services\Sports\SportsDateWindowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuarterbackAvailability
{
    /** Unavailability evidence known at prediction time, scoped to this game. */
    public function forGame(Game $game, int $teamId): array
    {
        $dates = app(SportsDateWindowService::class);
        $kickoff = $dates->gameDateTimeUtc($game->game_date, $game->game_time);
        $cutoff = $kickoff && $kickoff->lt(now()) ? $kickoff : now();
        $gameDate = $dates->gameDateForDisplay($game->game_date, $game->game_time) ?? Carbon::parse($game->game_date)->toDateString();
        $snapshot = PlayerInjurySnapshot::with('entries.player')->where('team_id', $teamId)
            ->where('observed_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('source_updated_at')->orWhere('source_updated_at', '<=', $cutoff))
            ->latest('observed_at')->latest('id')->first();
        $entries = $snapshot?->entries ?? PlayerInjury::with('player')->where('team_id', $teamId)
            ->where('is_active', true)->whereNotNull('source_updated_at')
            ->where('source_updated_at', '<=', $cutoff)->where('updated_at', '<=', $cutoff)->get();
        $records = [];
        foreach ($entries as $entry) {
            if ($entry->player && strtoupper((string) $entry->player->position) !== 'QB') {
                continue;
            }
            $published = $entry->source_updated_at ?? $snapshot?->observed_at;
            if (! $published || $published->gt($cutoff)
                || ($entry->observed_at && $entry->observed_at->gt($cutoff))
                || ($entry->injury_date && $entry->injury_date->toDateString() > $gameDate)
                || ($entry->return_date && $entry->return_date->toDateString() < $gameDate)) {
                continue;
            }
            // A previous week's short-term designation cannot silently persist
            // all season. Reserve statuses remain meaningful until superseded.
            if (! $this->reserveStatus((string) $entry->status) && $published->lt($cutoff->copy()->subDays(7))) {
                continue;
            }
            if (! $entry->return_date && ! $this->reserveStatus((string) $entry->status)
                && $gameDate > $published->copy()->addDays(7)->toDateString()) {
                continue;
            }
            $records[] = ['player_id' => $entry->player_id, 'player_name' => $entry->player?->full_name,
                'status' => $entry->status, 'source' => $snapshot ? 'injury_snapshot' : 'timestamped_injury',
                'snapshot_uuid' => $snapshot?->snapshot_uuid, 'published_at' => $published->toIso8601String()];
        }

        // Same-week secondary evidence also protects nflverse-only QB identities.
        $team = strtoupper((string) ($teamId === (int) $game->home_team_id ? $game->homeTeam?->abbreviation : $game->awayTeam?->abbreviation));
        foreach (DB::table('nflverse_injuries')->where('season', $game->season)->where('week', $game->week)
            ->whereIn('team', in_array($team, ['WAS', 'WSH'], true) ? ['WAS', 'WSH'] : [$team])->where('position', 'QB')
            ->whereNotNull('source_updated_at')->where('source_updated_at', '<=', $cutoff)
            ->where('updated_at', '<=', $cutoff)->get() as $row) {
            $records[] = ['player_id' => null, 'player_name' => $row->full_name, 'status' => $row->report_status,
                'source' => 'nflverse_injuries', 'snapshot_uuid' => null,
                'published_at' => Carbon::parse($row->source_updated_at)->toIso8601String()];
        }

        foreach ((array) $game->getAttribute('research_availability') as $fact) {
            if ((int) ($fact['team_id'] ?? 0) !== $teamId || empty($fact['observed_at']) || empty($fact['published_at'])
                || Carbon::parse($fact['observed_at'])->gt($cutoff) || Carbon::parse($fact['published_at'])->gt($cutoff)) {
                continue;
            }
            $records[] = [...$fact, 'source' => 'verified_research'];
        }

        // Latest effective status wins (including an explicit Available update).
        return collect($records)->sortByDesc(fn ($row) => Carbon::parse($row['published_at'])->getTimestamp())
            ->unique(fn ($row) => filled($row['player_name'] ?? null) ? $this->name($row['player_name']) : 'id:'.$row['player_id'])
            ->filter(fn ($row) => $this->unavailable((string) $row['status']))->values()->all();
    }

    public function excludes(array $evidence, mixed $playerId, ?string $name): bool
    {
        return collect($evidence)->contains(fn ($row) => ($playerId !== null && ($row['player_id'] ?? null) !== null && (string) $row['player_id'] === (string) $playerId)
            || ($name && ! empty($row['player_name']) && $this->name($name) === $this->name($row['player_name'])));
    }

    private function name(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($name)));
    }

    private function unavailable(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['out', 'inactive', 'suspended'], true) || $this->reserveStatus($status);
    }

    private function reserveStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['ir', 'injured reserve', 'reserve/injured', 'pup', 'physically unable to perform', 'reserve/pup'], true);
    }
}
