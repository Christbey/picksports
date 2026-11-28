<?php

namespace App\Actions\ESPN\NBA;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\NBA\Player;
use App\Models\NBA\Team;
use App\Services\NBA\Injuries\InjuryAcquisitionOrder;
use App\Services\NBA\Injuries\InjuryCanaryScope;
use App\Services\NBA\Injuries\InjuryFeedClient;
use App\Services\NBA\Injuries\InjurySnapshotRecorder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'nba_player_injuries';

    private bool $capturing = false;

    private bool $prepared = false;

    private array $capture = [];

    private array $allEntries = [];

    private array $projectionEntries = [];

    private array $entryIssues = [];

    private array $references = [];

    public function execute(string $teamEspnId, bool $requireCapture = false): int
    {
        $this->capturing = (bool) config('nba.injuries.observations_enabled', false);
        if (! $this->capturing) {
            if ($requireCapture) {
                throw new \RuntimeException('NBA structured capture is required.');
            }

            return parent::execute($teamEspnId);
        }
        $team = $this->findTeamByEspnId($teamEspnId);
        if (! $team || ! app(InjuryCanaryScope::class)->allowsTeam((int) $team->id)) {
            if ($requireCapture) {
                throw new \RuntimeException('NBA structured capture admission denied.');
            }
            $this->capturing = false;

            return parent::execute($teamEspnId);
        }
        // Structured capture's state/recorder services are currently default-connection bound.
        // Refuse unsupported remapping before sequence allocation or provider acquisition.
        if ($team->getConnection()->getName() !== DB::connection()->getName()
            || (new Player)->getConnection()->getName() !== DB::connection()->getName()) {
            throw new \RuntimeException('NBA structured injury capture requires coherent default-connection models.');
        }
        foreach (['nba_player_injury_snapshots', 'nba_player_injury_snapshot_entries', 'nba_player_injury_observations', 'nba_injury_team_states'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException('Install NBA injury observation storage before activation.');
            }
        }
        $this->prepared = false;
        $this->allEntries = $this->projectionEntries = $this->entryIssues = $this->references = [];
        $sequence = app(InjuryAcquisitionOrder::class)->begin((int) $team->id);
        $this->capture = ['acquisition_sequence' => $sequence, 'projection_applied' => false, 'started_at' => now(), 'responses' => [], 'issues' => [],
            'source_type' => 'team_injuries', 'source_url' => app(InjuryFeedClient::class)->teamUrl($teamEspnId)];
        // Acquire before locking the team. Parent execute consumes only this prepared result.
        $this->projectionEntries = $this->extractTeamInjuries($teamEspnId);
        $this->prepared = true;
        $entries = $this->snapshotEntries((int) $team->id);

        return DB::transaction(function () use ($teamEspnId, $team, $entries): int {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $state = DB::table('nba_injury_team_states')->where('team_id', $team->id)->lockForUpdate()->first();
            $current = (int) $state->issued_sequence === $this->capture['acquisition_sequence'];
            $this->capture['projection_applied'] = $current && $this->injuryPayloadObserved;
            $count = $current ? parent::execute($teamEspnId) : 0;
            $observation = app(InjurySnapshotRecorder::class)->record($team, $this->capture, $entries);
            if ($current) {
                DB::table('nba_injury_team_states')->where('team_id', $team->id)->update(['latest_observation_id' => $observation->id]);
                foreach ($this->capture['responses'] as $response) {
                    app(InjuryFeedClient::class)->cacheResponse($response);
                }
            }

            return $count;
        });
    }

    protected function extractTeamInjuries(string $teamEspnId): array
    {
        if (! $this->capturing) {
            return parent::extractTeamInjuries($teamEspnId);
        }
        if ($this->prepared) {
            return $this->projectionEntries;
        }
        $this->injuryPayloadObserved = false;
        $this->injuryPayloadReliable = false;
        $client = app(InjuryFeedClient::class);
        $primary = $this->fetch($client->teamUrl($teamEspnId), false);
        $payload = $primary['payload'];
        $collectionKey = is_array($payload) ? collect(['items', 'injuries', 'entries'])->first(fn ($key) => array_key_exists($key, $payload)) : null;
        if ($primary['error_code'] === null && $collectionKey !== null && is_array($payload[$collectionKey]) && array_is_list($payload[$collectionKey])) {
            $this->capture['responses'][0]['required'] = true;
            $items = $payload[$collectionKey];
            if (count(array_intersect(['items', 'injuries', 'entries'], array_keys($payload))) !== 1) {
                $this->capture['issues'][] = 'ambiguous_collection';
            }
            $this->collectionIssues($payload, count($items));
            foreach ($items as $entry) {
                if (! is_array($entry) || $entry === []) {
                    $this->capture['issues'][] = 'invalid_entry';

                    continue;
                }
                $this->allEntries[] = $this->resolveRefEntity($entry);
            }
        } else {
            $this->capture['issues'][] = 'primary_unavailable';
            $this->capture['source_type'] = 'roster';
            $this->capture['source_url'] = $client->teamUrl($teamEspnId, true);
            $roster = $this->fetch($this->capture['source_url'], true);
            if ($roster['error_code'] !== null || ! is_array($roster['payload']) || ! is_array($roster['payload']['athletes'] ?? null) || ! array_is_list($roster['payload']['athletes'])) {
                $this->capture['issues'][] = 'roster_unavailable';
                $this->capture['outcome'] = 'failed';

                return [];
            }
            // A successful fallback has its own explicit origin, not primary-feed authority.
            $this->capture['issues'] = [];
            $count = 0;
            $rosterAthleteIds = [];
            foreach ($roster['payload']['athletes'] as $group) {
                if (! is_array($group)) {
                    $this->capture['issues'][] = 'invalid_roster';

                    continue;
                }
                $athletes = array_key_exists('items', $group) ? $group['items'] : [$group];
                if (! is_array($athletes) || ! array_is_list($athletes)) {
                    $this->capture['issues'][] = 'invalid_roster';

                    continue;
                }
                if (array_key_exists('items', $group)) {
                    $this->collectionIssues($group, count($athletes));
                }
                foreach ($athletes as $athlete) {
                    $count++;
                    $id = is_array($athlete) ? $this->extractAthleteIdFromEntity($athlete) : null;
                    if ($id === null || ! is_array($athlete['injuries'] ?? null) || ! array_is_list($athlete['injuries'])) {
                        $this->capture['issues'][] = 'roster_injury_coverage_unknown';

                        continue;
                    }
                    $rosterAthleteIds[] = $id;
                    foreach ($athlete['injuries'] as $entry) {
                        if (! is_array($entry) || $entry === []) {
                            $this->capture['issues'][] = 'invalid_entry';

                            continue;
                        }
                        $resolved = $this->resolveRefEntity($entry);
                        $resolved['athlete'] ??= ['id' => $id];
                        if ($this->extractAthleteEspnId($resolved) !== $id) {
                            $this->capture['issues'][] = 'roster_athlete_mismatch';

                            continue;
                        }
                        $this->allEntries[] = $resolved;
                    }
                }
            }
            $this->collectionIssues($roster['payload'], $count);
            $team = $this->findTeamByEspnId($teamEspnId);
            if (array_diff(array_keys($this->playerIdMapByEspnId((int) $team->id)), $rosterAthleteIds) !== [] || $count === 0) {
                $this->capture['issues'][] = 'roster_identity_coverage_unknown';
            }
        }
        $this->injuryPayloadObserved = true;
        $team = $this->findTeamByEspnId($teamEspnId);
        $players = $this->playerIdMapByEspnId((int) $team->id);
        $accepted = [];
        foreach ($this->allEntries as $position => $entry) {
            $issues = [];
            $athlete = $this->extractAthleteEspnId($entry);
            $normalized = $this->normalizeInjury($entry);
            $reportedTeam = $entry['team']['id'] ?? null;
            if ($reportedTeam === null && is_string($entry['team']['$ref'] ?? null)
                && preg_match('#/teams/(\d+)#', $entry['team']['$ref'], $matches)) {
                $reportedTeam = $matches[1];
            }
            if ($reportedTeam !== null && (string) $reportedTeam !== $teamEspnId) {
                $issues[] = 'team_mismatch';
            }
            if ($athlete === null) {
                $issues[] = 'athlete_identity_missing';
            } elseif (! isset($players[$athlete])) {
                $issues[] = 'athlete_unmapped';
            }
            if ($normalized['status'] === null) {
                $issues[] = 'status_missing';
            }
            $time = $entry['lastUpdated'] ?? $entry['date'] ?? null;
            if ($time !== null && $this->normalizeDateTime($time) === null) {
                $issues[] = 'source_time_unknown';
            }
            $this->entryIssues[$position] = $issues;
            $this->capture['issues'] = [...$this->capture['issues'], ...$issues];
            if (! array_intersect($issues, ['team_mismatch', 'athlete_identity_missing', 'status_missing'])) {
                $accepted[] = $entry;
            }
        }
        $this->injuryPayloadReliable = $this->capture['issues'] === [];
        $this->capture['outcome'] = ! $this->injuryPayloadReliable ? 'partial' : ($this->allEntries === [] ? 'empty' : 'complete');

        return $accepted;
    }

    protected function resolveRefEntity(array $entity): array
    {
        if (! $this->capturing) {
            return parent::resolveRefEntity($entity);
        }
        $url = $entity['$ref'] ?? null;
        if (! is_string($url) || $url === '') {
            return $entity;
        }
        if (! isset($this->references[$url])) {
            if (count($this->references) >= 100) {
                $this->capture['issues'][] = 'reference_limit';

                return $entity;
            }
            $this->references[$url] = $this->fetch($url, true);
        }
        $response = $this->references[$url];
        if ($response['error_code'] !== null || ! is_array($response['payload']) || $response['payload'] === []) {
            $this->capture['issues'][] = 'unresolved_reference';

            return $entity;
        }

        return $response['payload'] + $entity;
    }

    protected function normalizeDateTime(mixed $value): ?string
    {
        if (! $this->capturing) {
            return parent::normalizeDateTime($value);
        }
        if (! is_string($value) || ! preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone((string) config('app.timezone', 'UTC'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function snapshotSourceUpdatedAt(array $injury): ?CarbonInterface
    {
        if (! $this->capturing) {
            return parent::snapshotSourceUpdatedAt($injury);
        }
        $value = $this->normalizeDateTime($injury['lastUpdated'] ?? $injury['date'] ?? null);

        return $value !== null ? Carbon::parse($value, (string) config('app.timezone', 'UTC')) : null;
    }

    private function fetch(string $url, bool $required): array
    {
        $response = app(InjuryFeedClient::class)->fetch($url, false);
        if (strlen(json_encode(array_column($this->capture['responses'], 'payload'), JSON_THROW_ON_ERROR))
            + strlen(json_encode($response['payload'], JSON_THROW_ON_ERROR)) > 4194304) {
            $response = [...$response, 'payload' => null, 'error_code' => 'observation_too_large'];
            $this->capture['issues'][] = 'observation_too_large';
        }
        $response['required'] = $required;
        $this->capture['responses'][] = $response;

        return $response;
    }

    private function collectionIssues(array $payload, int $count): void
    {
        foreach (['count', 'total', 'totalCount'] as $key) {
            if (array_key_exists($key, $payload) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false || (int) $payload[$key] !== $count)) {
                $this->capture['issues'][] = 'collection_incomplete';
            }
        }
        foreach (['page', 'pageCount', 'pageIndex'] as $key) {
            if (isset($payload[$key]) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]) === false)) {
                $this->capture['issues'][] = 'collection_incomplete';
            }
        }
        foreach (['next', 'nextPage', 'nextPageUrl', 'links.next', 'hasMore', 'hasNext', 'hasNextPage'] as $key) {
            if (data_get($payload, $key)) {
                $this->capture['issues'][] = 'collection_incomplete';
            }
        }
        foreach ((array) ($payload['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next') {
                $this->capture['issues'][] = 'collection_incomplete';
            }
        }
        if ($count >= 100 && ! array_key_exists('count', $payload) && ! array_key_exists('total', $payload) && ! array_key_exists('totalCount', $payload)) {
            $this->capture['issues'][] = 'collection_limit_unknown';
        }
    }

    private function snapshotEntries(int $teamId): array
    {
        $players = $this->playerIdMapByEspnId($teamId);

        return array_map(function (array $entry, int $position) use ($players): array {
            $normalized = $this->normalizeInjury($entry);
            $athlete = $this->extractAthleteEspnId($entry);
            $field = isset($entry['lastUpdated']) ? 'lastUpdated' : (isset($entry['date']) ? 'date' : null);

            return [...$normalized, 'position' => $position,
                'player_id' => in_array('team_mismatch', $this->entryIssues[$position] ?? [], true) ? null : ($players[$athlete] ?? null),
                'espn_athlete_id' => $athlete, 'injury_key' => $this->injuryKey($normalized),
                'source_time_field' => $field, 'source_time_raw' => $field !== null && is_string($entry[$field]) ? $entry[$field] : null,
                'issues' => $this->entryIssues[$position] ?? [], 'raw_payload' => $entry];
        }, $this->allEntries, array_keys($this->allEntries));
    }
}
