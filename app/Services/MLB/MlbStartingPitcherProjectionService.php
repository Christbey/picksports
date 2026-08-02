<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\Team;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MlbStartingPitcherProjectionService
{
    public function __construct(
        private readonly MlbStartingPitcherForecastService $forecasts,
    ) {}

    /**
     * @return array{changed: bool, home: array<string, mixed>, away: array<string, mixed>}
     */
    public function project(Game $game): array
    {
        $game->loadMissing(['homeTeam', 'awayTeam']);

        $home = $this->projectSide($game, $game->homeTeam, 'home');
        $away = $this->projectSide($game, $game->awayTeam, 'away');
        $metadata = [
            'version' => (string) config('mlb.starter_projection.version', 'rotation-v1'),
            'home' => $home['evidence'],
            'away' => $away['evidence'],
        ];

        $attributes = [
            'projected_home_pitcher_espn_id' => $home['pitcher_espn_id'],
            'projected_away_pitcher_espn_id' => $away['pitcher_espn_id'],
            'projected_home_pitcher_confidence' => $home['confidence'],
            'projected_away_pitcher_confidence' => $away['confidence'],
            'pitcher_projection_metadata' => $metadata,
            'pitcher_projection_generated_at' => now(),
        ];

        $changed = collect($attributes)
            ->except(['pitcher_projection_generated_at'])
            ->contains(fn (mixed $value, string $key): bool => $this->changed($game->getAttribute($key), $value));

        $game->forceFill($attributes)->save();
        $this->forecasts->record($game, 'home', $home);
        $this->forecasts->record($game, 'away', $away);

        return [
            'changed' => $changed,
            'home' => $home,
            'away' => $away,
        ];
    }

    /**
     * @return array{pitcher_espn_id: ?string, confidence: ?float, evidence: array<string, mixed>}
     */
    private function projectSide(Game $target, ?Team $team, string $side): array
    {
        if (! $team || MlbGameStart::for($target) === null) {
            return $this->emptyProjection('missing_team_or_start');
        }

        $knownAssignments = $this->knownAssignments($target, $team);
        if ($knownAssignments->isEmpty()) {
            return $this->emptyProjection('no_known_starts');
        }

        $rotation = $this->rotationFrom($knownAssignments);
        $minimumSize = max(2, (int) config('mlb.starter_projection.minimum_rotation_size', 3));

        if ($rotation->count() < $minimumSize) {
            return $this->emptyProjection('insufficient_rotation_history', [
                'rotation_size' => $rotation->count(),
            ]);
        }

        $anchor = $knownAssignments->first();
        $anchorIndex = $rotation->search($anchor['pitcher_espn_id']);
        if ($anchorIndex === false) {
            return $this->emptyProjection('anchor_not_in_rotation');
        }

        $gamesAhead = $this->gamesAfterAnchorThroughTarget($target, $team, $anchor['game']);
        if ($gamesAhead < 1) {
            return $this->emptyProjection('target_not_after_anchor');
        }

        $projectedIndex = ($anchorIndex + $gamesAhead) % $rotation->count();
        $rawPitcherId = (string) $rotation->get($projectedIndex);
        $resolved = $this->resolveRosterPitcher($team, $rawPitcherId, $rotation, $target);
        $confidence = $this->confidence($rotation->count(), $gamesAhead, $resolved['substituted']);

        return [
            'pitcher_espn_id' => $resolved['pitcher_espn_id'],
            'confidence' => $confidence,
            'evidence' => [
                'status' => $resolved['pitcher_espn_id'] ? 'projected' : 'unresolved',
                'team_id' => $team->id,
                'side' => $side,
                'anchor_game_id' => $anchor['game']->id,
                'anchor_date' => $anchor['game']->game_date?->toDateString(),
                'anchor_pitcher_espn_id' => $anchor['pitcher_espn_id'],
                'anchor_source' => $anchor['source'],
                'games_ahead' => $gamesAhead,
                'rotation_size' => $rotation->count(),
                'rotation_pitcher_espn_ids' => $rotation->values()->all(),
                'raw_projected_pitcher_espn_id' => $rawPitcherId,
                'roster_substitution' => $resolved['substituted'],
                'reason' => $resolved['reason'],
            ],
        ];
    }

    /**
     * @return Collection<int, array{game: Game, pitcher_espn_id: string, source: string}>
     */
    private function knownAssignments(Game $target, Team $team): Collection
    {
        $targetStart = MlbGameStart::for($target);
        $limit = max(20, (int) config('mlb.starter_projection.history_games', 60));

        return Game::query()
            ->where('season', $target->season)
            ->whereKeyNot($target->id)
            ->where(function (Builder $query) use ($team): void {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->whereDate('game_date', '<=', $targetStart->toDateString())
            ->orderByDesc('game_date')
            ->orderByDesc('game_time')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->filter(function (Game $game) use ($targetStart): bool {
                $start = MlbGameStart::for($game);

                return $start !== null && $start->lt($targetStart);
            })
            ->map(function (Game $game) use ($team): ?array {
                $side = $game->home_team_id === $team->id ? 'home' : 'away';
                $actualPitcherId = $side === 'home'
                    ? $game->actual_home_pitcher_espn_id
                    : $game->actual_away_pitcher_espn_id;
                $probablePitcherId = $side === 'home'
                    ? $game->probable_home_pitcher_espn_id
                    : $game->probable_away_pitcher_espn_id;
                $pitcherId = filled($actualPitcherId) ? $actualPitcherId : $probablePitcherId;
                $pitcherId = trim((string) $pitcherId);

                return $pitcherId !== ''
                    ? [
                        'game' => $game,
                        'pitcher_espn_id' => $pitcherId,
                        'source' => filled($actualPitcherId) ? 'espn_boxscore_confirmed' : 'espn_probable',
                    ]
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array{game: Game, pitcher_espn_id: string, source: string}>  $knownAssignments
     * @return Collection<int, string>
     */
    private function rotationFrom(Collection $knownAssignments): Collection
    {
        $size = max(3, (int) config('mlb.starter_projection.rotation_size', 5));
        $reverseRotation = collect();

        foreach ($knownAssignments as $assignment) {
            if (! $reverseRotation->contains($assignment['pitcher_espn_id'])) {
                $reverseRotation->push($assignment['pitcher_espn_id']);
            }

            if ($reverseRotation->count() >= $size) {
                break;
            }
        }

        return $reverseRotation->reverse()->values();
    }

    private function gamesAfterAnchorThroughTarget(Game $target, Team $team, Game $anchor): int
    {
        $anchorStart = MlbGameStart::for($anchor);
        $targetStart = MlbGameStart::for($target);

        if ($anchorStart === null || $targetStart === null) {
            return 0;
        }

        return Game::query()
            ->where('season', $target->season)
            ->where(function (Builder $query) use ($team): void {
                $query->where('home_team_id', $team->id)
                    ->orWhere('away_team_id', $team->id);
            })
            ->whereDate('game_date', '>=', $anchorStart->toDateString())
            ->whereDate('game_date', '<=', $targetStart->toDateString())
            ->get()
            ->filter(function (Game $game) use ($anchorStart, $targetStart): bool {
                $start = MlbGameStart::for($game);

                return $start !== null && $start->gt($anchorStart) && $start->lte($targetStart);
            })
            ->count();
    }

    /**
     * @param  Collection<int, string>  $rotation
     * @return array{pitcher_espn_id: ?string, substituted: bool, reason: string}
     */
    private function resolveRosterPitcher(Team $team, string $pitcherEspnId, Collection $rotation, Game $target): array
    {
        $player = Player::query()
            ->where('team_id', $team->id)
            ->where('espn_id', $pitcherEspnId)
            ->first();

        if ($player && ! $this->isUnavailable($player, $target)) {
            return [
                'pitcher_espn_id' => $pitcherEspnId,
                'substituted' => false,
                'reason' => 'rotation_slot',
            ];
        }

        $replacement = Player::query()
            ->where('team_id', $team->id)
            ->where('position', 'SP')
            ->whereNotIn('espn_id', $rotation->all())
            ->orderByDesc('elo_rating')
            ->orderBy('id')
            ->get()
            ->first(fn (Player $candidate): bool => ! $this->isUnavailable($candidate, $target));

        return [
            'pitcher_espn_id' => $replacement?->espn_id,
            'substituted' => $replacement !== null,
            'reason' => $replacement ? 'roster_replacement_for_unavailable_rotation_slot' : 'rotation_slot_unresolved',
        ];
    }

    private function isUnavailable(Player $player, Game $target): bool
    {
        $statuses = PlayerInjury::query()
            ->where('player_id', $player->id)
            ->where('is_active', true)
            ->pluck('status')
            ->map(fn (mixed $status): string => strtoupper((string) $status));

        return $statuses->contains(fn (string $status): bool => str_contains($status, 'IL')
            || str_contains($status, 'OUT')
            || str_contains($status, 'SUSPENDED')
        );
    }

    private function confidence(int $rotationSize, int $gamesAhead, bool $substituted): float
    {
        $base = (float) config('mlb.starter_projection.base_confidence', 0.88);
        $minimum = (float) config('mlb.starter_projection.minimum_confidence', 0.30);
        $decay = (float) config('mlb.starter_projection.per_game_decay', 0.018);
        $sizePenalty = max(0, 5 - $rotationSize) * 0.08;
        $substitutionPenalty = $substituted ? 0.12 : 0.0;

        return round(max($minimum, $base - $sizePenalty - $substitutionPenalty - max(0, $gamesAhead - 1) * $decay), 4);
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{pitcher_espn_id: null, confidence: null, evidence: array<string, mixed>}
     */
    private function emptyProjection(string $reason, array $evidence = []): array
    {
        return [
            'pitcher_espn_id' => null,
            'confidence' => null,
            'evidence' => array_merge(['status' => 'unavailable', 'reason' => $reason], $evidence),
        ];
    }

    private function changed(mixed $before, mixed $after): bool
    {
        if (is_float($after)) {
            return abs((float) $before - $after) > 0.00005;
        }

        if (is_array($after)) {
            return $before !== $after;
        }

        return $before !== $after;
    }
}
