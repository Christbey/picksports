<?php

namespace App\Services\Sports\Elo;

use App\DataTransferObjects\ESPN\GameData;
use App\Exceptions\EloAdmissionRefused;
use App\Models\MLB\Game as MlbGame;
use App\Models\NFL\Game as NflGame;
use App\Models\SportEloApplication;
use App\Models\SportEloEntityHead;
use App\Models\SportEloSeasonInitialization;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\ESPN\ObservedStatValue;
use App\Support\MLB\MlbGameStart;
use App\Support\NFL\NflGameStart;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

class EloSourceContext
{
    public const NFL_IMPORT_CLOCK_POLICY = ['version' => 'precise_nflverse_v2_missing_clock', 'metadata_bytes' => 16384, 'json_depth' => 32];

    public const MLB_CLOCK_POLICY = EloSourceClock::MLB_POLICY;

    private int $observationsRead = 0;

    private int $gamesConsidered = 0;

    private array $pitchingRows = [];

    private bool $lockedPitchingReady = false;

    public function __construct(private readonly CanonicalPayloadHasher $hasher, private readonly EloEligibilityPolicy $eligibility,
        private readonly EloReviewedBaseline $baselines, private readonly EloDependencyGraph $dependencies) {}

    public function beginObservation(): void
    {
        $this->observationsRead = 0;
        $this->gamesConsidered = 0;
        $this->pitchingRows = [];
        $this->lockedPitchingReady = false;
    }

    public function considered(): array
    {
        return ['games' => $this->gamesConsidered, 'pitching_observations_considered' => $this->observationsRead];
    }

    public function refuse(string $reason, ?int $gameId = null): never
    {
        throw new EloAdmissionRefused($reason, $this->considered(), EloEligibilityPolicy::LIMITS, $gameId === null ? [] : ['game_id' => $gameId]);
    }

    /** Current range locks precede stats/entities/heads; a stale plan is never widened with late game locks. */
    public function lockGames(Connection $connection, string $sport, array $definition, array $participants, array $extraIds = [], ?int $currentSeason = null, ?array $rangeSeasons = null, ?int $forecastGameId = null, ?array $forecastCutoffKey = null): array
    {
        $participantScope = function ($query) use ($participants): void {
            foreach ($participants as $type => $ids) {
                $query->orWhere(fn ($scope) => $scope->where('entity_type', $type)->whereIn('entity_id', $ids));
            }
        };
        $heads = SportEloEntityHead::on($connection->getName())->where('sport', $sport)->where($participantScope)
            ->limit(array_sum(array_map('count', $participants)) + 1)->toBase()
            ->get(['id', 'entity_type', 'entity_id', 'season', 'initialization_id', 'last_effect_id', 'last_game_id', 'generation']);
        $seasons = $heads->pluck('season')->map(fn ($season): int => (int) $season)->all();
        if ($currentSeason !== null) {
            $seasons[] = $currentSeason;
        }
        $seasons = array_values(array_unique([...$seasons, ...($rangeSeasons ?? [])]));
        if (count($seasons) > 2) {
            $this->refuse('incoherent_source_seasons');
        }
        $dependencyPlan = $this->dependencies->plan($connection, $sport, $definition, $heads, $extraIds);
        $discoveryParticipants = $participants;
        foreach ($dependencyPlan['ranges'] as $windows) {
            foreach ($windows as $window) {
                $discoveryParticipants[$window['entity_type']][] = $window['entity_id'];
            }
        }
        foreach ($discoveryParticipants as &$ids) {
            $ids = array_values(array_unique($ids));
        }
        unset($ids);
        $discoverySeasons = array_values(array_unique([...($rangeSeasons ?? $seasons), ...array_keys($dependencyPlan['ranges'])]));
        $baselineRows = SportEloSeasonInitialization::on($connection->getName())->where(['sport' => $sport,
            'calculation_version' => $definition['version'], 'kind' => 'baseline_admission', 'baseline_kind' => 'reviewed_legacy_current'])
            ->where($participantScope)->whereIn('season', $seasons)
            ->limit((array_sum(array_map('count', $participants)) * max(1, count($seasons))) + 1)
            ->toBase()->get(['id', 'evidence->game->id as baseline_game_id']);
        $required = array_values(array_unique([...$extraIds, ...$heads->pluck('last_game_id')->filter()->map(fn ($id): int => (int) $id)->all(),
            ...$baselineRows->pluck('baseline_game_id')->filter()->map(fn ($id): int => (int) $id)->all(), ...$dependencyPlan['game_ids']]));
        sort($required, SORT_NUMERIC);
        $columns = ['id', 'sport_event_id', 'espn_event_id', 'season', 'season_type', 'week', 'game_date', 'game_time', 'status',
            'home_team_id', 'away_team_id', 'home_score', 'away_score'];
        if ($sport !== 'mlb') {
            $columns[] = 'neutral_site';
        }
        if ($sport === 'nfl') {
            $columns[] = 'nflverse_game_id';
        }
        if ($sport === 'mlb') {
            $columns = [...$columns, ...MlbGameStart::FIELDS];
        }
        $gameRange = $definition['game_model']::on($connection->getName())->where(function ($query) use ($required, $discoverySeasons, $discoveryParticipants, $sport): void {
            $query->whereIn('id', $required)->orWhere(function ($range) use ($discoverySeasons, $discoveryParticipants, $sport): void {
                $range->whereIn('season', $discoverySeasons)->whereIn('status', GameData::finalStatuses());
                // Pitcher participation can change through stats; locking the full season's final game range
                // fences those writes before current stat discovery, including historical team transfers.
                if ($sport !== 'mlb' || empty($discoveryParticipants['pitcher'])) {
                    $teams = $discoveryParticipants['team'] ?? [];
                    $range->where(fn ($teamsQuery) => $teamsQuery->whereIn('home_team_id', $teams)->orWhereIn('away_team_id', $teams));
                }
            });
        })->orderBy('id')->lockForUpdate()->limit(EloEligibilityPolicy::LIMITS['games'] + 1);
        // Lock and count only scalar identities first. An oversized set must not hydrate thousands
        // of Eloquent instances before it can return the structured refusal.
        $gameIds = [];
        foreach ((clone $gameRange)->toBase()->select('id')->cursor() as $row) {
            $gameIds[] = (int) $row->id;
        }
        $this->gamesConsidered = count($gameIds);
        if ($this->gamesConsidered > EloEligibilityPolicy::LIMITS['games']) {
            $this->refuse('game_source_budget_exceeded');
        }
        $games = $definition['game_model']::on($connection->getName())->whereIn('id', $gameIds)
            ->orderBy('id')->lockForUpdate()->get($columns)->keyBy('id');
        if ($games->count() !== count($gameIds)) {
            $this->refuse('locked_source_set_changed');
        }
        unset($gameIds);
        foreach ($required as $id) {
            if (! $games->has($id)) {
                $this->refuse('required_predecessor_source_unavailable');
            }
        }
        if ($sport === 'mlb' && $games->isNotEmpty()) {
            $pitchingQuery = $connection->table('mlb_player_stats')->whereIn('game_id', $games->keys()->all())->where('stat_type', 'pitching')
                ->orderBy('game_id')->orderBy('id')->lockForUpdate()->limit(max(1, EloEligibilityPolicy::LIMITS['pitching_observations'] - $this->observationsRead + 1));
            $observationCount = 0;
            foreach ((clone $pitchingQuery)->select('id')->cursor() as $row) {
                $observationCount++;
            }
            $this->observationsRead += $observationCount;
            if ($this->observationsRead > EloEligibilityPolicy::LIMITS['pitching_observations']) {
                $this->refuse('pitching_source_budget_exceeded');
            }
            // The same current range is already locked. Do not bind 100,000 identifiers into a
            // second prepared statement or materialize value rows before the scalar budget check.
            $rows = $pitchingQuery->get(['id', 'game_id', 'team_id', 'player_id', 'stat_type', 'innings_pitched']);
            if ($rows->count() !== $observationCount) {
                $this->refuse('locked_pitching_set_changed');
            }
            foreach ($rows->groupBy('game_id') as $id => $gameRows) {
                if ($gameRows->count() > EloEligibilityPolicy::LIMITS['pitching_per_game']) {
                    $this->refuse('pitching_per_game_budget_exceeded');
                }
                $this->pitchingRows[(int) $id] = $gameRows;
            }
        }
        $this->lockedPitchingReady = $sport === 'mlb';
        $throughId = $currentSeason !== null && count($extraIds) === 1 ? (int) $extraIds[0] : null;
        $through = $throughId === null ? null : ($games[$throughId] ?? null);
        $throughKey = $forecastGameId !== null ? $forecastCutoffKey : ($through === null ? null : $this->gameKey($through));
        $sources = [];
        foreach ($games as $id => $game) {
            if ($forecastGameId !== null && (int) $id === $forecastGameId) {
                // Admission validates this separately as a current scheduled target, never a final result.
                $this->assertImportedClock($connection, $game);

                continue;
            }
            $forced = in_array((int) $id, $required, true);
            $related = in_array((int) $game->home_team_id, $participants['team'] ?? [], true)
                || in_array((int) $game->away_team_id, $participants['team'] ?? [], true)
                || ($sport === 'mlb' && $this->pitcherRelevant($game, $participants['pitcher'] ?? []));
            $dependencyRelated = $this->dependencies->requiresSource($dependencyPlan, $game, $sport === 'mlb' ? $this->pitchingSource($connection, $game) : []);
            if (! $forced && ! $related && ! $dependencyRelated) {
                continue;
            }
            $classification = $this->eligibility->classify($sport, $game->getRawOriginal('season_type'));
            if (! $forced && $classification === 'excluded') {
                continue;
            }
            if (! $forced && ! $dependencyRelated && $throughKey !== null && (int) $game->season === $currentSeason) {
                // A known later final is not a dependency of this earlier event. Unknown same-day timing still blocks.
                $date = substr((string) $game->getRawOriginal('game_date'), 0, 10);
                if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) === 1 && $date > substr($throughKey[0], 0, 10)) {
                    continue;
                }
                if ($this->gameKey($game) > $throughKey) {
                    continue;
                }
            }
            if (! $forced && $classification === 'unknown') {
                $this->refuse('unknown_final_season_type', (int) $game->id);
            }
            try {
                $sources[$id] = $this->source($sport, $game, $sport === 'mlb' ? $this->pitchingSource($connection, $game) : []);
            } catch (EloAdmissionRefused $exception) {
                throw $exception;
            } catch (Throwable) {
                $this->refuse('incomplete_final_source', (int) $game->id);
            }
        }
        $planned = [];
        foreach ($heads as $head) {
            $identity = array_intersect_key((array) $head, array_flip(['id', 'initialization_id', 'last_effect_id', 'last_game_id', 'generation', 'season']));
            foreach ($identity as $key => $value) {
                $identity[$key] = $value === null ? null : (int) $value;
            }
            $planned[$head->entity_type.':'.$head->entity_id] = $identity;
        }

        return ['games' => $games, 'sources' => $sources, 'planned_heads' => $planned, 'considered' => $this->considered(), 'dependency_plan' => $dependencyPlan];
    }

    public function verifyDependencies(Connection $connection, string $sport, array $definition, array $locked, iterable $heads, ?SportEloApplication $target = null, array $requestedHistory = []): array
    {
        return $this->dependencies->verify($connection, $sport, $definition, $locked['dependency_plan'], $locked, $heads, $target, $requestedHistory);
    }

    private function pitcherRelevant(Model $game, array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $rows = $this->pitchingRows[(int) $game->id] ?? collect();
        if (! $rows->contains(fn ($row) => in_array((int) $row->player_id, $ids, true))) {
            return false;
        }
        foreach ($rows->groupBy('team_id') as $teamRows) {
            if (! $teamRows->contains(fn ($row) => in_array((int) $row->player_id, $ids, true))) {
                continue;
            }
            foreach ($teamRows as $row) {
                if ($row->innings_pitched === null || ! is_numeric($row->innings_pitched) || ! is_finite((float) $row->innings_pitched) || (float) $row->innings_pitched < 0) {
                    $this->refuse('uncertain_pitching_participation');
                }
            }
            $maximum = $teamRows->max(fn ($row): float => (float) $row->innings_pitched);
            if ($teamRows->contains(fn ($row) => (float) $row->innings_pitched === $maximum && in_array((int) $row->player_id, $ids, true))) {
                return true;
            }
        }

        return false;
    }

    private function playedAt(Model $game): CarbonImmutable
    {
        try {
            return EloSourceClock::observed($game);
        } catch (\InvalidArgumentException $error) {
            if ($game instanceof MlbGame) {
                $this->refuse('unknown_mlb_observed_chronology', (int) $game->id);
            }
            throw $error;
        }
    }

    public function gameKey(Model $game): array
    {
        try {
            return [$this->playedAt($game)->format('Y-m-d H:i:s.u'), (int) $game->id];
        } catch (Throwable) {
            $this->refuse('unknown_final_chronology');
        }
    }

    public function reviewedCutoff(Connection $connection, SportEloEntityHead $head, int $season, array $definition, array $locked): ?array
    {
        return $this->baselines->cutoff($connection, $head, $season, $definition, $locked);
    }

    public function beforeCutoff(Model $game, ?array $cutoff): bool
    {
        return $this->baselines->includes($game, $cutoff);
    }

    /** Every known post-cutoff event must have a matching native receipt before extending or rolling over. */
    public function verifyPending(Connection $connection, SportEloEntityHead $head, array $definition, array $locked, ?Model $target = null, ?array $throughOverride = null): void
    {
        $season = $target === null ? $head->season : (int) $target->season;
        $cutoff = $this->reviewedCutoff($connection, $head, $season, $definition, $locked);
        $through = $throughOverride ?? ($target === null ? null : $this->gameKey($target));
        foreach ($locked['games'] as $game) {
            if ((int) $game->season !== $season || ($target !== null && (int) $game->id === (int) $target->id)) {
                continue;
            }
            $related = $head->entity_type === 'team'
                ? in_array($head->entity_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)
                : $this->pitcherRelevant($game, [$head->entity_id]);
            if (! $related || $this->eligibility->classify($head->sport, $game->getRawOriginal('season_type')) === 'excluded') {
                continue;
            }
            if ($through !== null) {
                $date = substr((string) $game->getRawOriginal('game_date'), 0, 10);
                if (preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) === 1 && $date > substr($through[0], 0, 10)) {
                    continue;
                }
            }
            $key = $this->gameKey($game);
            if ($through !== null && $key > $through) {
                continue;
            }
            if ($this->eligibility->classify($head->sport, $game->getRawOriginal('season_type')) === 'unknown') {
                $this->refuse('unknown_final_season_type', (int) $game->id);
            }
            if ($this->beforeCutoff($game, $cutoff)) {
                continue;
            }
            $source = $locked['sources'][$game->id] ?? null;
            if ($source === null) {
                $this->refuse('post_cutoff_source_unavailable', (int) $game->id);
            }
            $application = SportEloApplication::on($connection->getName())->where(['sport' => $head->sport,
                'game_id' => $game->id, 'calculation_version' => $definition['version']])->lockForUpdate()->first();
            if ($application === null) {
                $this->refuse('unreceipted_prior_final', (int) $game->id);
            }
            $payload = $application->only(['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint', 'source_snapshot']);
            $payload['played_at'] = $application->played_at->format('Y-m-d H:i:s.u');
            if ($application->configuration_hash !== $definition['configuration_hash']
                || ! hash_equals($application->receipt_hash, $this->hasher->hash($payload))
                || ! hash_equals($application->source_fingerprint, $this->hasher->hash($source))) {
                $this->refuse('corrected_prior_final_source', (int) $game->id);
            }
            if ($head->last_played_at === null || $key > [$head->last_played_at->format('Y-m-d H:i:s.u'), (int) $head->last_game_id]) {
                $this->refuse('applied_final_ahead_of_head', (int) $game->id);
            }
        }
    }

    public function source(string $sport, Model $game, array $pitching): array
    {
        if (! in_array($game->status, GameData::finalStatuses(), true)
            || ! $game->home_team_id || ! $game->away_team_id || $game->home_team_id === $game->away_team_id) {
            throw new RuntimeException('Elo source requires final observed scores and distinct known participants.');
        }
        $this->assertImportedClock($game->getConnection(), $game);
        ObservedStatValue::integer($game->getRawOriginal('home_score'));
        ObservedStatValue::integer($game->getRawOriginal('away_score'));
        $playedAt = $this->playedAt($game);
        $clockFields = $game instanceof MlbGame ? MlbGameStart::FIELDS : [];

        return ['game' => array_intersect_key($game->getAttributes(), array_flip(['id', 'espn_event_id', 'season', 'season_type', 'week',
            'game_date', 'game_time', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'status', 'neutral_site', ...$clockFields])),
            'played_at_utc' => $playedAt->format('Y-m-d H:i:s.u'), 'pitching_observations' => $pitching,
            ...($game instanceof MlbGame ? ['clock_policy' => self::MLB_CLOCK_POLICY] : [])];
    }

    public function verifyHead(Connection $connection, SportEloEntityHead $head, array $locked): void
    {
        $planned = $locked['planned_heads'][$head->entity_type.':'.$head->entity_id] ?? null;
        $current = $head->only(['id', 'initialization_id', 'last_effect_id', 'last_game_id', 'generation', 'season']);
        if ($planned === null || $this->hasher->hash($planned) !== $this->hasher->hash($current)) {
            throw new RuntimeException('Elo predecessor changed outside the planned locked source set. Considered '.json_encode($this->considered(), JSON_THROW_ON_ERROR).'.');
        }
        if ($head->last_game_id === null) {
            return;
        }
        $source = $locked['sources'][$head->last_game_id] ?? null;
        if ($source === null) {
            throw new RuntimeException('Elo predecessor changed outside the planned locked source set.');
        }
        $application = SportEloApplication::on($connection->getName())->where(['sport' => $head->sport,
            'game_id' => $head->last_game_id, 'calculation_version' => $head->calculation_version])->lockForUpdate()->first();
        if ($application !== null) {
            $payload = $application->only(['sport', 'game_id', 'season', 'calculation_version', 'configuration_hash', 'source_fingerprint', 'source_snapshot']);
            $payload['played_at'] = $application->played_at->format('Y-m-d H:i:s.u');
            if ($application->configuration_hash !== $head->configuration_hash
                || ! hash_equals($application->receipt_hash, $this->hasher->hash($payload))
                || ! hash_equals($application->source_fingerprint, $this->hasher->hash($source))) {
                throw new RuntimeException('A corrected Elo predecessor source requires reviewed reconstruction.');
            }
        } else {
            $initialization = $head->getRelation('integrityInitialization');
            $expected = $initialization->evidence['game'] ?? $initialization->evidence['prior_game_snapshot'] ?? null;
            $game = $locked['games'][$head->last_game_id];
            $current = is_array($expected) ? array_intersect_key($game->getAttributes(), $expected) : [];
            $current['played_at_utc'] = $source['played_at_utc'];
            if (! is_array($expected) || ! hash_equals($this->hasher->hash($expected), $this->hasher->hash($current))) {
                throw new RuntimeException('A changed admitted legacy predecessor requires reviewed reconstruction.');
            }
        }
    }

    private function assertImportedClock(Connection $connection, Model $game): void
    {
        if (! $game instanceof NflGame || ! is_string($game->nflverse_game_id) || $game->nflverse_game_id === ''
            || ! NflGameStart::possibleMissingImportedClock($game->getRawOriginal('game_time'))) {
            return;
        }
        $bytes = match ($connection->getDriverName()) {
            'mysql' => 'OCTET_LENGTH(odds_data)',
            'sqlite' => 'LENGTH(CAST(odds_data AS BLOB))',
            default => throw new RuntimeException('Unsupported imported-clock metadata connection.'),
        };
        $row = $connection->table($game->getTable())->where('id', $game->id)->lockForUpdate()
            ->select(['nflverse_game_id', 'game_time'])->selectRaw($bytes.' AS metadata_bytes')->first();
        if ($row === null || (string) $row->nflverse_game_id !== $game->nflverse_game_id
            || (string) $row->game_time !== (string) $game->getRawOriginal('game_time')) {
            $this->refuse('import_clock_source_changed', (int) $game->id);
        }
        if ($row->metadata_bytes === null) {
            return; // Absent metadata is not evidence of this precise historical placeholder.
        }
        if ((int) $row->metadata_bytes > self::NFL_IMPORT_CLOCK_POLICY['metadata_bytes']) {
            $this->refuseClock('import_clock_metadata_budget', (int) $game->id);
        }
        $raw = $connection->table($game->getTable())->where('id', $game->id)->lockForUpdate()->value('odds_data');
        if (! is_string($raw) || strlen($raw) !== (int) $row->metadata_bytes) {
            $this->refuseClock('import_clock_metadata_changed', (int) $game->id);
        }
        try {
            $payload = json_decode($raw, true, self::NFL_IMPORT_CLOCK_POLICY['json_depth'], JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->refuseClock('import_clock_metadata_invalid', (int) $game->id);
        }
        if (! is_array($payload)) {
            $this->refuseClock('import_clock_metadata_invalid', (int) $game->id);
        }
        if (NflGameStart::knownMissingImportedClockObservation($row->nflverse_game_id, $row->game_time, $payload)) {
            $this->refuseClock('proven_missing_imported_clock', (int) $game->id);
        }
    }

    private function refuseClock(string $reason, int $gameId): never
    {
        throw new EloAdmissionRefused($reason, $this->considered(),
            [...EloEligibilityPolicy::LIMITS, 'import_clock_metadata_bytes' => self::NFL_IMPORT_CLOCK_POLICY['metadata_bytes']], ['game_id' => $gameId]);
    }

    public function pitchingSource(Connection $connection, Model $game, bool $locking = true): array
    {
        if ($locking) {
            if (! $this->lockedPitchingReady) {
                $this->refuse('current_pitching_batch_unavailable');
            }
            $rows = $this->pitchingRows[(int) $game->id] ?? collect();
        } else {
            $rows = $connection->table('mlb_player_stats')->where('game_id', $game->id)->where('stat_type', 'pitching')->orderBy('id')
                ->limit(min(EloEligibilityPolicy::LIMITS['pitching_per_game'] + 1, max(1, EloEligibilityPolicy::LIMITS['pitching_observations'] - $this->observationsRead + 1)))->get(['id', 'game_id', 'team_id', 'player_id', 'stat_type', 'innings_pitched']);
            $this->observationsRead += $rows->count();
        }
        if ($rows->count() > EloEligibilityPolicy::LIMITS['pitching_per_game'] || $this->observationsRead > EloEligibilityPolicy::LIMITS['pitching_observations']) {
            $this->refuse('pitching_observation_budget_exceeded');
        }
        if ($rows->contains(fn ($row) => ! in_array((int) $row->team_id, [(int) $game->home_team_id, (int) $game->away_team_id], true)
            || (int) $row->player_id < 1) || $rows->pluck('player_id')->unique()->count() !== $rows->count()) {
            $this->refuse('contradictory_pitching_identity');
        }
        $selected = [];
        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $eligible = $rows->where('team_id', $teamId)->filter(fn ($row) => $row->innings_pitched !== null);
            foreach ($eligible as $row) {
                if (! is_numeric($row->innings_pitched) || ! is_finite((float) $row->innings_pitched) || (float) $row->innings_pitched < 0) {
                    throw new RuntimeException('Invalid MLB pitching observation.');
                }
            }
            $eligible = $eligible->sortByDesc(fn ($row) => (float) $row->innings_pitched)->values();
            if ($eligible->isNotEmpty()) {
                if ($eligible->count() > 1 && (float) $eligible[0]->innings_pitched === (float) $eligible[1]->innings_pitched) {
                    throw new RuntimeException('Ambiguous MLB pitching selection.');
                }
                $row = $eligible[0];
                $selected[] = ['stat_id' => (int) $row->id, 'player_id' => (int) $row->player_id,
                    'team_id' => (int) $teamId, 'innings_pitched' => (string) $row->innings_pitched];
            }
        }
        if (count($selected) === 1 || (count($selected) === 2 && $selected[0]['player_id'] === $selected[1]['player_id'])) {
            throw new RuntimeException('MLB pitching selection is partial or contradictory.');
        }

        return $selected;
    }
}
