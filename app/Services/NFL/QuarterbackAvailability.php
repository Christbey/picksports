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
    /** Game-scoped evidence excluding a QB from the projected starting lineup. */
    public function forGame(Game $game, int $teamId): array
    {
        return array_values(array_filter(
            $this->designationsForGame($game, $teamId),
            fn (array $row): bool => $this->isUnavailable((string) $row['status']),
        ));
    }

    /** Latest game-scoped status per QB, including explicit clearances. */
    public function designationsForGame(Game $game, int $teamId, ?array $verifiedAvailability = null): array
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
            if (! $this->gameDesignation((string) $entry->status)) {
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
        $seasonTypes = match (strtolower((string) $game->season_type)) {
            '1', 'pre', 'preseason' => ['PRE', 'preseason', '1'],
            '2', 'reg', 'regular', 'regular_season' => ['REG', 'regular', 'regular_season', '2'],
            '3', 'post', 'postseason' => ['POST', 'postseason', '3'],
            default => [(string) $game->season_type],
        };
        foreach (DB::table('nflverse_injuries')->where('season', $game->season)->where('week', $game->week)
            ->whereIn('season_type', $seasonTypes)
            ->whereIn('team', in_array($team, ['WAS', 'WSH'], true) ? ['WAS', 'WSH'] : [$team])->where('position', 'QB')
            ->whereNotNull('source_updated_at')->where('source_updated_at', '<=', $cutoff)
            ->where('updated_at', '<=', $cutoff)->get() as $row) {
            if (! $this->gameDesignation((string) $row->report_status)) {
                continue;
            }
            if (! $this->reserveStatus((string) $row->report_status)
                && Carbon::parse($row->source_updated_at)->lt($cutoff->copy()->subDays(7))) {
                continue;
            }
            $records[] = ['player_id' => null, 'player_name' => $row->full_name, 'status' => $row->report_status,
                'source' => 'nflverse_injuries', 'snapshot_uuid' => null,
                'published_at' => Carbon::parse($row->source_updated_at)->toIso8601String()];
        }

        foreach ($verifiedAvailability ?? (array) $game->getAttribute('research_availability') as $fact) {
            if ((int) ($fact['team_id'] ?? 0) !== $teamId || empty($fact['observed_at']) || empty($fact['published_at'])
                || Carbon::parse($fact['observed_at'])->gt($cutoff) || Carbon::parse($fact['published_at'])->gt($cutoff)) {
                continue;
            }
            $records[] = [...$fact, 'source' => 'verified_research'];
        }

        // Latest effective status wins (including an explicit Available update).
        return collect($records)->sortByDesc(fn ($row) => Carbon::parse($row['published_at'])->getTimestamp())
            ->unique(fn ($row) => filled($row['player_name'] ?? null) ? $this->name($row['player_name']) : 'id:'.$row['player_id'])
            ->values()->all();
    }

    public function forecastDesignations(Game $game, array $metadata, ?array $availability = null): array
    {
        return collect(['home' => $game->home_team_id, 'away' => $game->away_team_id])->map(function ($teamId, $side) use ($game, $metadata, $availability) {
            $qb = data_get($metadata, 'qb_form.'.$side) ?? data_get($metadata, 'game_depth_charts.'.$side, []);
            $playerId = $qb['qb_id'] ?? null;
            $player = $qb['qb_name'] ?? null;
            $designation = $playerId || $player
                ? collect($this->designationsForGame($game, (int) $teamId, $availability))
                    ->first(fn ($row) => $this->excludes([$row], $playerId, $player))
                : null;

            return [
                'player_id' => $playerId,
                'projected_player' => $player,
                'identity_status' => $qb['identity_status'] ?? null,
                'designation' => $designation === null ? null : strtolower(trim((string) $designation['status'])),
                'designation_source' => $designation['source'] ?? null,
                'designation_effective_at' => $designation['published_at'] ?? null,
                'unavailable' => $designation !== null && $this->isUnavailable((string) $designation['status']),
            ];
        })->all();
    }

    public function forecastHolds(array $quarterbacks): array
    {
        return collect($quarterbacks)->map(fn ($qb, $side) => match (true) {
            $qb['unavailable'] => $side.'_starting_qb_unavailable_in_forecast',
            default => null,
        })->filter()->values()->all();
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

    private function gameDesignation(string $status): bool
    {
        return $this->isUnavailable($status)
            || in_array(strtolower(trim($status)), ['questionable', 'doubtful', 'available', 'active', 'healthy'], true);
    }

    private function isUnavailable(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['doubtful', 'out', 'inactive', 'suspended'], true) || $this->reserveStatus($status);
    }

    private function reserveStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['ir', 'injured reserve', 'reserve/injured', 'pup', 'physically unable to perform', 'reserve/pup'], true);
    }
}
