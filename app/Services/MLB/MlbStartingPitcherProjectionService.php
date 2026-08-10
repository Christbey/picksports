<?php

namespace App\Services\MLB;

use App\Models\MLB\Game;
use App\Models\MLB\PitcherEloRating;
use App\Models\MLB\Player;
use App\Models\MLB\PlayerInjury;
use App\Models\MLB\PlayerStat;
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
            'version' => (string) config('mlb.starter_projection.version', 'rotation-v2'),
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
        $this->forecasts->recordProbablePitchers($game);
        $this->recordForecast($game, 'home', $home);
        $this->recordForecast($game, 'away', $away);

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
        $ranked = $this->rankCandidates(
            $target,
            $team,
            $rotation,
            $knownAssignments,
            $rawPitcherId,
        );

        if ($ranked['candidates'] === []) {
            return $this->emptyProjection('rotation_candidates_unavailable', [
                'team_id' => $team->id,
                'side' => $side,
                'rotation_size' => $rotation->count(),
                'raw_projected_pitcher_espn_id' => $rawPitcherId,
            ]);
        }

        $projectedPitcherId = $ranked['expected_slot_available'] ? $rawPitcherId : null;
        $trackingPitcherId = (string) data_get($ranked, 'candidates.0.pitcher_espn_id');
        $confidence = (float) data_get($ranked, 'candidates.0.probability', 0.0);

        return [
            'pitcher_espn_id' => $projectedPitcherId,
            'tracking_pitcher_espn_id' => $trackingPitcherId,
            'confidence' => $confidence,
            'evidence' => [
                'status' => $projectedPitcherId ? 'projected' : 'uncertain_rotation',
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
                'roster_substitution' => ! $ranked['expected_slot_available'],
                'reason' => $ranked['expected_slot_available']
                    ? 'calibrated_rotation_slot'
                    : 'expected_rotation_slot_unavailable',
                'candidates' => $ranked['candidates'],
                'unknown_probability' => $ranked['unknown_probability'],
                'expected_pitcher_rating' => $ranked['expected_pitcher_rating'],
                'uncertainty' => round(1 - $confidence, 4),
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
            ->whereNotIn('status', ['STATUS_CANCELED', 'STATUS_POSTPONED'])
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
        $minimumSize = max(3, (int) config('mlb.starter_projection.minimum_rotation_size', 3));
        $maximumSize = max(
            (int) config('mlb.starter_projection.rotation_size', 5),
            (int) config('mlb.starter_projection.maximum_rotation_size', 6),
        );
        $minimumStarts = $knownAssignments->count() >= 10 ? 2 : 1;
        $startCounts = $knownAssignments->countBy('pitcher_espn_id');
        $eligiblePitchers = $startCounts
            ->filter(fn (int $starts): bool => $starts >= $minimumStarts)
            ->keys();
        $reverseRotation = collect();

        foreach ($knownAssignments as $assignment) {
            if ($eligiblePitchers->contains($assignment['pitcher_espn_id'])
                && ! $reverseRotation->contains($assignment['pitcher_espn_id'])) {
                $reverseRotation->push($assignment['pitcher_espn_id']);
            }

            if ($reverseRotation->count() >= $maximumSize) {
                break;
            }
        }

        if ($reverseRotation->count() < $minimumSize) {
            foreach ($knownAssignments as $assignment) {
                if (! $reverseRotation->contains($assignment['pitcher_espn_id'])) {
                    $reverseRotation->push($assignment['pitcher_espn_id']);
                }

                if ($reverseRotation->count() >= (int) config('mlb.starter_projection.rotation_size', 5)) {
                    break;
                }
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
            ->whereNotIn('status', ['STATUS_CANCELED', 'STATUS_POSTPONED'])
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
     * @param  Collection<int, array{game: Game, pitcher_espn_id: string, source: string}>  $knownAssignments
     * @return array{candidates: list<array<string, mixed>>, unknown_probability: float, expected_pitcher_rating: float, expected_slot_available: bool}
     */
    private function rankCandidates(
        Game $target,
        Team $team,
        Collection $rotation,
        Collection $knownAssignments,
        string $expectedPitcherId,
    ): array {
        $players = Player::query()
            ->where('team_id', $team->id)
            ->where(function (Builder $query) use ($rotation): void {
                $query->whereIn('espn_id', $rotation->all())
                    ->orWhere('position', 'SP');
            })
            ->get()
            ->filter(fn (Player $player): bool => filled($player->espn_id))
            ->unique('espn_id')
            ->values();

        if ($players->isEmpty()) {
            return $this->emptyCandidateRanking();
        }

        $startsByPitcher = $knownAssignments->groupBy('pitcher_espn_id');
        $workload = $this->latestWorkload($players, $target);
        $ratings = $this->candidateRatings($players, $target);
        $expectedIndex = $rotation->search($expectedPitcherId);

        $candidates = $players
            ->reject(fn (Player $player): bool => $this->isUnavailable($player, $target))
            ->map(function (Player $player) use (
                $target,
                $rotation,
                $startsByPitcher,
                $workload,
                $ratings,
                $expectedPitcherId,
                $expectedIndex,
            ): array {
                $pitcherId = (string) $player->espn_id;
                $assignments = $startsByPitcher->get($pitcherId, collect());
                $lastAssignment = $assignments->first();
                $lastStart = $lastAssignment ? MlbGameStart::for($lastAssignment['game']) : null;
                $targetStart = MlbGameStart::for($target);
                $daysSinceLastStart = $lastStart && $targetStart
                    ? (int) $lastStart->startOfDay()->diffInDays($targetStart->startOfDay())
                    : null;
                $rotationIndex = $rotation->search($pitcherId);
                $rotationDistance = $rotationIndex !== false && $expectedIndex !== false
                    ? min(
                        abs($rotationIndex - $expectedIndex),
                        $rotation->count() - abs($rotationIndex - $expectedIndex),
                    )
                    : null;
                $lastPitchCount = data_get($workload, "{$player->id}.pitch_count");
                $score = min(2.0, $assignments->count() / 3);

                if ($pitcherId === $expectedPitcherId) {
                    $score += 5.0;
                } elseif ($rotationDistance !== null) {
                    $score += max(0.2, 1.8 - $rotationDistance * 0.6);
                }

                if ($daysSinceLastStart !== null) {
                    $score += max(-2.0, 2.0 - abs($daysSinceLastStart - 5) * 0.65);
                    if ($daysSinceLastStart < 4) {
                        $score -= 2.0;
                    }
                }

                if (is_numeric($lastPitchCount) && (int) $lastPitchCount >= 100 && $daysSinceLastStart !== null && $daysSinceLastStart <= 4) {
                    $score -= 0.75;
                }

                return [
                    'pitcher_espn_id' => $pitcherId,
                    'pitcher_name' => $player->full_name,
                    'rating' => $ratings->get($player->id),
                    'score' => round($score, 4),
                    'starts_in_history' => $assignments->count(),
                    'days_since_last_start' => $daysSinceLastStart,
                    'last_pitch_count' => is_numeric($lastPitchCount) ? (int) $lastPitchCount : null,
                    'rotation_slot' => $rotationIndex !== false ? $rotationIndex + 1 : null,
                    'expected_rotation_slot' => $expectedIndex !== false ? $expectedIndex + 1 : null,
                ];
            })
            ->sortByDesc('score')
            ->values();

        if ($candidates->isEmpty()) {
            return $this->emptyCandidateRanking();
        }

        $expectedSlotAvailable = $candidates->contains(
            fn (array $candidate): bool => $candidate['pitcher_espn_id'] === $expectedPitcherId,
        );
        if ($expectedSlotAvailable) {
            $expected = $candidates->firstWhere('pitcher_espn_id', $expectedPitcherId);
            $candidates = collect([$expected])
                ->merge($candidates->reject(fn (array $candidate): bool => $candidate['pitcher_espn_id'] === $expectedPitcherId))
                ->values();
        }

        $limit = max(2, (int) config('mlb.starter_projection.candidate_limit', 4));
        $probabilities = $expectedSlotAvailable
            ? [(float) config('mlb.starter_projection.projected_slot_probability', 0.60), 0.18, 0.10, 0.04]
            : [(float) config('mlb.starter_projection.uncertain_candidate_probability', 0.25), 0.18, 0.12, 0.05];
        $rankedCandidates = $candidates
            ->take($limit)
            ->values()
            ->map(function (array $candidate, int $index) use ($probabilities): array {
                $candidate['probability'] = round((float) ($probabilities[$index] ?? 0.0), 4);
                unset($candidate['score']);

                return $candidate;
            });
        $namedProbability = (float) $rankedCandidates->sum('probability');
        $unknownProbability = round(max(0.0, 1.0 - $namedProbability), 4);
        $fallbackRating = $this->teamStarterFallbackRating($team, $target, $rankedCandidates);
        $expectedRating = (float) $rankedCandidates->sum(
            fn (array $candidate): float => (float) $candidate['probability'] * (float) ($candidate['rating'] ?? $fallbackRating),
        ) + $unknownProbability * $fallbackRating;

        return [
            'candidates' => $rankedCandidates->all(),
            'unknown_probability' => $unknownProbability,
            'expected_pitcher_rating' => round($expectedRating, 2),
            'expected_slot_available' => $expectedSlotAvailable,
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

    /**
     * @param  Collection<int, Player>  $players
     * @return Collection<int, array{pitch_count: ?int}>
     */
    private function latestWorkload(Collection $players, Game $target): Collection
    {
        return PlayerStat::query()
            ->select(['mlb_player_stats.player_id', 'mlb_player_stats.pitch_count', 'mlb_player_stats.pitches_thrown', 'mlb_games.game_date'])
            ->join('mlb_games', 'mlb_games.id', '=', 'mlb_player_stats.game_id')
            ->whereIn('mlb_player_stats.player_id', $players->pluck('id'))
            ->whereDate('mlb_games.game_date', '<', $target->game_date)
            ->orderByDesc('mlb_games.game_date')
            ->orderByDesc('mlb_player_stats.id')
            ->get()
            ->unique('player_id')
            ->mapWithKeys(fn (PlayerStat $stat): array => [
                $stat->player_id => [
                    'pitch_count' => $stat->pitch_count ?? $stat->pitches_thrown,
                ],
            ]);
    }

    /**
     * @param  Collection<int, Player>  $players
     * @return Collection<int, float>
     */
    private function candidateRatings(Collection $players, Game $target): Collection
    {
        $historical = PitcherEloRating::query()
            ->whereIn('player_id', $players->pluck('id'))
            ->whereDate('date', '<', $target->game_date)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->unique('player_id')
            ->mapWithKeys(fn (PitcherEloRating $rating): array => [
                $rating->player_id => (float) $rating->elo_rating,
            ]);

        return $players->mapWithKeys(fn (Player $player): array => [
            $player->id => (float) ($historical->get($player->id)
                ?? $player->elo_rating
                ?? config('mlb.elo.default_rating', 1500)),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     */
    private function teamStarterFallbackRating(Team $team, Game $target, Collection $candidates): float
    {
        $recent = PitcherEloRating::query()
            ->where('team_id', $team->id)
            ->whereDate('date', '<', $target->game_date)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('elo_rating')
            ->filter(fn (mixed $rating): bool => is_numeric($rating));

        if ($recent->isNotEmpty()) {
            return (float) $recent->avg();
        }

        $candidateRatings = $candidates->pluck('rating')->filter(fn (mixed $rating): bool => is_numeric($rating));

        return $candidateRatings->isNotEmpty()
            ? (float) $candidateRatings->avg()
            : (float) config('mlb.elo.default_rating', 1500);
    }

    /**
     * @return array{candidates: array<never>, unknown_probability: float, expected_pitcher_rating: float, expected_slot_available: bool}
     */
    private function emptyCandidateRanking(): array
    {
        return [
            'candidates' => [],
            'unknown_probability' => 1.0,
            'expected_pitcher_rating' => (float) config('mlb.elo.default_rating', 1500),
            'expected_slot_available' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function recordForecast(Game $game, string $side, array $projection): void
    {
        $trackingPitcherId = $projection['tracking_pitcher_espn_id']
            ?? $projection['pitcher_espn_id']
            ?? null;

        $this->forecasts->record($game, $side, [
            ...$projection,
            'pitcher_espn_id' => $trackingPitcherId,
        ]);
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
