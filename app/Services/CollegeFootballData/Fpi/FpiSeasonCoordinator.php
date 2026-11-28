<?php

namespace App\Services\CollegeFootballData\Fpi;

use App\Models\CFB\FpiRating;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\Sports\ExplicitOffsetInstant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use WeakMap;

final class FpiSeasonCoordinator
{
    private WeakMap $owned;

    public function __construct(private readonly FpiSourceInventory $sources, private readonly EloWriteTargetIdentity $targets,
        private readonly CanonicalPayloadHasher $hasher, private readonly FpiSourceCache $cache)
    {
        $this->owned = new WeakMap;
    }

    public function activate(Connection $connection, int $season, string $target, string $actor, string $reason,
        string $drainReference, string $sourceReviewReference, CarbonImmutable $drainedAt): void
    {
        $this->season($season);
        $this->outsideTransaction($connection);
        if (! hash_equals($this->targets->describe($connection)['fingerprint'], $target) || $drainedAt->isFuture()) {
            throw new RuntimeException('FPI activation target or drain instant is invalid.');
        }
        foreach ([[$actor, 80], [$reason, 256], [$drainReference, 160], [$sourceReviewReference, 160]] as [$value, $maximum]) {
            if ($value === '' || trim($value) !== $value || strlen($value) > $maximum || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new RuntimeException('FPI activation requires bounded explicit operator and review statements.');
            }
        }
        $now = $this->now();
        $connection->table('cfbd_fpi_season_heads')->insert(['season' => $season, 'protocol_version' => 1,
            'target_fingerprint' => $target, 'activation_operator' => $actor, 'activation_reason' => $reason,
            'drain_reference' => $drainReference, 'source_review_reference' => $sourceReviewReference,
            'activated_at' => $now, 'drained_at' => $drainedAt->utc()->format('Y-m-d H:i:s.u'),
            'issued_sequence' => 0, 'latest_terminal_sequence' => 0, 'latest_applied_sequence' => 0,
            'created_at' => $now, 'updated_at' => $now]);
    }

    public function begin(int $season, int $week): FpiAcquisition
    {
        $this->season($season);
        if ($week < 0 || $week > 2147483647) {
            throw new RuntimeException('FPI week must fit the existing nonnegative signed-integer storage contract.');
        }
        $connection = (new FpiRating)->getConnection();
        $this->outsideTransaction($connection);
        $existsSql = $connection->getSchemaGrammar()->compileTableExists(null, $connection->getTablePrefix().'cfbd_fpi_season_heads');
        if (! is_string($existsSql) || ! $connection->scalar($existsSql, [], false)) {
            throw new RuntimeException('Install and explicitly activate the FPI season acquisition protocol before fetching.');
        }
        $target = $this->targets->describe($connection)['fingerprint'];
        $header = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $season)->first();
        $this->head($header, $season, $target);
        $inventory = $this->sources->capture($connection, $season, false);
        $pdo = $connection->getPdo();
        [$id, $sequence, $owner, $startedAt, $expiresAt, $headIdentityHash] = $connection->transaction(function () use ($connection, $season, $week, $target, $inventory) {
            $head = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $season)->lockForUpdate()->first();
            $this->head($head, $season, $target);
            if ((int) $head->issued_sequence >= PHP_INT_MAX) {
                throw new RuntimeException('FPI acquisition sequence capacity is exhausted.');
            }
            $headIdentityHash = $this->headIdentity($head);
            $sequence = (int) $head->issued_sequence + 1;
            $owner = bin2hex(random_bytes(32));
            $started = CarbonImmutable::now('UTC');
            $now = $started->format('Y-m-d H:i:s.u');
            $expiresAt = $started->addSeconds(120)->format('Y-m-d H:i:s.u');
            $id = $connection->table('cfbd_fpi_season_attempts')->insertGetId(['head_id' => $head->id, 'sequence' => $sequence,
                'week' => $week, 'activation_identity_hash' => $headIdentityHash, 'owner_hash' => hash('sha256', $owner), 'source_identity_hash' => $inventory['hash'],
                'started_at' => $now, 'expires_at' => $expiresAt,
                'row_count' => 0, 'created_at' => $now, 'updated_at' => $now]);
            if ($connection->table('cfbd_fpi_season_heads')->where('id', $head->id)->where('issued_sequence', $head->issued_sequence)
                ->update(['issued_sequence' => $sequence, 'updated_at' => $now]) !== 1) {
                throw new RuntimeException('FPI reservation sequence did not persist.');
            }

            return [(int) $id, $sequence, $owner, $now, $expiresAt, $headIdentityHash];
        });
        $context = new FpiAcquisition($season, $week, $id, $sequence);
        $this->owned[$context] = ['connection' => $connection, 'pdo' => $pdo, 'owner' => $owner,
            'source_hash' => $inventory['hash'], 'target' => $target, 'head_hash' => $headIdentityHash,
            'cache_prefix' => 'cfbd-fpi-source:v1:'.hash('sha256', json_encode([$target, $season, $inventory['data']['provider_base_url'], hash('sha256', (string) config('services.collegefootballdata.api_key'))], JSON_THROW_ON_ERROR)), 'started_at' => $startedAt, 'expires_at' => $expiresAt, 'deadline' => hrtime(true) / 1e9 + 90];

        return $context;
    }

    public function acquireSource(FpiAcquisition $context, Closure $fetch): array
    {
        return $this->cache->acquire($context, $this, $fetch);
    }

    public function cachePrefix(FpiAcquisition $context): string
    {
        $state = $this->state($context);
        $this->outsideTransaction($state['connection']);

        return $state['cache_prefix'];
    }

    public function sourceCacheKey(FpiAcquisition $context, string $hash): string
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
            throw new RuntimeException('FPI source hash is invalid.');
        }

        return $this->cachePrefix($context).':attempt:'.$context->attemptId.':'.$hash;
    }

    public function recordStagedSource(FpiAcquisition $context, string $key, array $rows, CarbonImmutable $observedAt): void
    {
        $hash = $this->responseHash($rows, $observedAt);
        if (! array_is_list($rows) || count($rows) > 1024 || $observedAt->isFuture() || $key !== $this->sourceCacheKey($context, $hash)) {
            throw new RuntimeException('FPI staged cache owner changed.');
        }
        $this->owned[$context] = [...$this->state($context), 'staged_key' => $key, 'staged_hash' => $hash,
            'staged_rows' => count($rows), 'staged_observed_at' => $observedAt->utc()->format('Y-m-d H:i:s.u')];
    }

    public function responseHash(array $rows, CarbonImmutable $observedAt): string
    {
        return $this->hasher->hash(['rows' => $rows, 'observed_at' => $observedAt->utc()->format('Y-m-d H:i:s.u')]);
    }

    public function cacheOrigin(FpiAcquisition $context): ?array
    {
        $state = $this->state($context);
        $connection = $state['connection'];
        $this->outsideTransaction($connection);
        $this->remaining($context);
        $head = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $context->season)->first();
        $this->head($head, $context->season, $state['target']);
        $attempt = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $context->attemptId)->first();
        if ($attempt === null || ! hash_equals($state['head_hash'], $this->headIdentity($head))
            || ! $this->matchesInteger($head->issued_sequence, $context->sequence)
            || ! $this->matchesInteger($attempt->head_id, (int) $head->id)
            || ! $this->matchesInteger($attempt->sequence, $context->sequence)
            || ! $this->matchesInteger($attempt->week, $context->week)
            || $attempt->source_identity_hash !== $state['source_hash']
            || $attempt->activation_identity_hash !== $state['head_hash'] || $attempt->outcome !== null
            || ! hash_equals($attempt->owner_hash, hash('sha256', $state['owner']))
            || $attempt->started_at !== $state['started_at'] || $attempt->expires_at !== $state['expires_at']
            || $this->instant($attempt->expires_at)->lte(CarbonImmutable::now('UTC'))) {
            throw new RuntimeException('FPI acquisition is no longer the current live owner before fetching.');
        }
        if ((int) $head->latest_cache_origin_sequence === 0) {
            return null;
        }
        $origin = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $head->latest_cache_origin_attempt_id)->first();
        if ($origin === null || ! $this->matchesInteger($origin->row_count, (int) $origin->row_count) || (int) $origin->row_count > 1024
            || ! (($origin->outcome === 'applied' && $origin->error_code === null && (int) $origin->row_count > 0)
                || ($origin->outcome === 'unavailable' && $origin->error_code === 'empty_source' && $this->matchesInteger($origin->row_count, 0))) || ! $this->matchesInteger($origin->head_id, (int) $head->id)
            || ! $this->matchesInteger($origin->sequence, (int) $head->latest_cache_origin_sequence)
            || $origin->response_sha256 !== $head->latest_cache_origin_sha256 || $origin->source_cache_key !== $head->latest_cache_origin_key
            || $origin->activation_identity_hash !== $head->latest_cache_origin_activation_hash
            || $origin->accepted_source_identity_hash !== $head->latest_cache_origin_source_hash) {
            throw new RuntimeException('FPI cached-source pointer has no coherent accepted SQL origin.');
        }
        if ($origin->activation_identity_hash !== $state['head_hash'] || $origin->accepted_source_identity_hash !== $state['source_hash']) {
            return null;
        }
        $key = $state['cache_prefix'].':attempt:'.$origin->id.':'.$origin->response_sha256;
        if (strlen($key) > 255 || $key !== $origin->source_cache_key) {
            return null;
        }

        return ['key' => $key, 'hash' => $origin->response_sha256, 'rows' => (int) $origin->row_count,
            'observed_at' => $this->instant($origin->source_observed_at)->format('Y-m-d H:i:s.u')];
    }

    private function headIdentity(object $head): string
    {
        return $this->hasher->hash(array_intersect_key((array) $head, array_flip(['id', 'season', 'protocol_version',
            'target_fingerprint', 'activation_operator', 'activation_reason', 'drain_reference', 'source_review_reference',
            'activated_at', 'drained_at', 'created_at'])));
    }

    public function remaining(FpiAcquisition $context): float
    {
        $state = $this->state($context);
        $remaining = $state['deadline'] - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RuntimeException('FPI aggregate acquisition deadline expired.');
        }

        return $remaining;
    }

    /** The internal command callback uses only already-admitted source models and target rows. */
    public function commit(FpiAcquisition $context, array $rows, CarbonImmutable $observedAt, Closure $write): int
    {
        $state = $this->state($context);
        $connection = $state['connection'];
        $this->outsideTransaction($connection);
        $this->remaining($context);
        if (count($rows) > 1024 || ! array_is_list($rows) || $observedAt->isFuture()) {
            throw new RuntimeException('FPI source rows or observation instant are invalid.');
        }
        $responseHash = $this->responseHash($rows, $observedAt);
        if (($state['staged_rows'] ?? null) !== count($rows)
            || ($state['staged_observed_at'] ?? null) !== $observedAt->utc()->format('Y-m-d H:i:s.u')
            || ($state['staged_hash'] ?? null) !== $responseHash || ($state['staged_key'] ?? null) !== $state['cache_prefix'].':attempt:'.$context->attemptId.':'.$responseHash) {
            throw new RuntimeException('FPI source was not staged by this exact owned acquisition.');
        }
        try {
            return $connection->transaction(function () use ($context, $state, $connection, $rows, $write, $responseHash, $observedAt): int {
                $head = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $context->season)->lockForUpdate()->first();
                $this->head($head, $context->season, $state['target']);
                if (! hash_equals($state['head_hash'], $this->headIdentity($head))) {
                    throw new RuntimeException('FPI activation review epoch changed during acquisition.');
                }
                $attempt = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $context->attemptId)->lockForUpdate()->first();
                if ($attempt === null || ! $this->matchesInteger($attempt->head_id, (int) $head->id) || ! $this->matchesInteger($attempt->sequence, $context->sequence)
                    || ! $this->matchesInteger($attempt->week, $context->week) || $attempt->outcome !== null
                    || ! hash_equals($attempt->owner_hash, hash('sha256', $state['owner']))
                    || ! hash_equals($attempt->source_identity_hash, $state['source_hash'])
                    || ! hash_equals($attempt->activation_identity_hash, $state['head_hash'])
                    || $attempt->started_at !== $state['started_at'] || $attempt->expires_at !== $state['expires_at']) {
                    throw new RuntimeException('FPI attempt owner or current scope changed.');
                }
                if ($this->instant($attempt->expires_at)->lte(CarbonImmutable::now('UTC'))) {
                    throw new RuntimeException('FPI acquisition lease expired before commit.');
                }
                if ((int) $head->issued_sequence !== $context->sequence) {
                    $this->terminal($connection, $head, $attempt, 'superseded', null, $responseHash, count($rows));

                    return 1;
                }
                $inventory = $this->sources->capture($connection, $context->season, true);
                if (! hash_equals($state['source_hash'], $inventory['hash'])) {
                    throw new RuntimeException('FPI team, mapping or affiliation source changed during acquisition.');
                }
                if ($rows === []) {
                    $proposal = ['result' => 0, 'bindings' => []];
                } else {
                    [$teams, $mappings] = $this->sources->models($connection, $inventory['data']);
                    $model = (new FpiRating)->setConnection($connection->getName());
                    if ($model->getConnection() !== $connection) {
                        throw new RuntimeException('FPI rating connection changed.');
                    }
                    $ratingQuery = FpiSourceInventory::ratingProjection($model->newQuery()->useWritePdo()->getQuery())
                        ->where('season', $context->season)->where('week', $context->week);
                    if ($connection->getDriverName() === 'mysql') {
                        $ratingQuery->forceIndex('cfb_fpi_ratings_season_week_index');
                    }
                    $rawRatings = $ratingQuery->orderBy('team_id')->limit(1025)->lockForUpdate()->get();
                    $ratings = $model->newCollection($rawRatings->map(function ($row) use ($model, $connection) {
                        if ((int) $row->invalid_rating_body !== 0) {
                            throw new RuntimeException('FPI target scalar body exceeds its projection budget.');
                        }
                        $data = (array) $row;
                        unset($data['invalid_rating_body']);

                        return $model->newFromBuilder($data, $connection->getName());
                    })->all());
                    if ($ratings->count() > 1024) {
                        throw new RuntimeException('FPI target inventory exceeds the row budget.');
                    }
                    $this->remaining($context);
                    $proposal = $write($teams, $mappings, $ratings, $connection);
                }
                if (! is_array($proposal) || ($proposal['result'] ?? null) !== 0 || ! is_array($proposal['bindings'] ?? null)) {
                    throw new RuntimeException('FPI batch validation refused the proposed projection.');
                }
                $expectedSource = $this->sources->expectedAfterBindings($inventory['data'], $proposal['bindings']);
                $postSource = $this->sources->capture($connection, $context->season, true);
                if (! hash_equals($this->hasher->hash($expectedSource), $postSource['hash'])) {
                    throw new RuntimeException('FPI declared source changed before batch completion.');
                }
                $this->state($context);
                $this->remaining($context);
                $currentHead = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $context->season)->lockForUpdate()->first();
                $this->head($currentHead, $context->season, $state['target']);
                if (! hash_equals($state['head_hash'], $this->headIdentity($currentHead))) {
                    throw new RuntimeException('FPI activation review epoch changed during projection.');
                }
                $currentAttempt = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $context->attemptId)->lockForUpdate()->first();
                if ($currentAttempt === null || ! $this->matchesInteger($currentHead->id, (int) $head->id)
                    || ! $this->matchesInteger($currentHead->issued_sequence, $context->sequence)
                    || ! $this->matchesInteger($currentAttempt->head_id, (int) $head->id)
                    || ! $this->matchesInteger($currentAttempt->sequence, $context->sequence)
                    || ! $this->matchesInteger($currentAttempt->week, $context->week)
                    || $currentAttempt->outcome !== null
                    || ! hash_equals($currentAttempt->owner_hash, hash('sha256', $state['owner']))
                    || ! hash_equals($currentAttempt->source_identity_hash, $state['source_hash'])
                    || ! hash_equals($currentAttempt->activation_identity_hash, $state['head_hash'])
                    || $currentAttempt->started_at !== $state['started_at'] || $currentAttempt->expires_at !== $state['expires_at']
                    || $this->instant($currentAttempt->expires_at)->lte(CarbonImmutable::now('UTC'))) {
                    throw new RuntimeException('FPI current lease or owner changed during projection.');
                }
                $this->terminal($connection, $currentHead, $currentAttempt, $rows === [] ? 'unavailable' : 'applied', $rows === [] ? 'empty_source' : null, $responseHash, count($rows),
                    ['source_cache_key' => $state['staged_key'], 'accepted_source_identity_hash' => $postSource['hash'],
                        'source_observed_at' => $observedAt->utc()->format('Y-m-d H:i:s.u')]);

                return 0;
            });
        } finally {
            // A thrown projection is handled by fail() using its retained owned reservation.
        }
    }

    public function finish(FpiAcquisition $context, string $outcome, string $code): void
    {
        if (! in_array($outcome, ['failed', 'unavailable', 'expired'], true) || strlen($code) > 64 || ! preg_match('/\A[a-z0-9_]+\z/', $code)) {
            throw new RuntimeException('FPI failure completion cannot author an applied receipt.');
        }
        $state = $this->state($context);
        $connection = $state['connection'];
        $this->outsideTransaction($connection);
        $connection->transaction(function () use ($context, $state, $connection, $outcome, $code): void {
            $head = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('season', $context->season)->lockForUpdate()->first();
            $attempt = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $context->attemptId)->lockForUpdate()->first();
            $this->head($head, $context->season, $state['target']);
            if ($attempt === null || ! hash_equals($state['head_hash'], $this->headIdentity($head))
                || ! $this->matchesInteger($attempt->head_id, (int) $head->id)
                || ! $this->matchesInteger($attempt->sequence, $context->sequence)
                || ! $this->matchesInteger($attempt->week, $context->week)
                || $attempt->owner_hash !== hash('sha256', $state['owner'])
                || $attempt->source_identity_hash !== $state['source_hash']
                || $attempt->activation_identity_hash !== $state['head_hash']
                || $attempt->started_at !== $state['started_at'] || $attempt->expires_at !== $state['expires_at']) {
                throw new RuntimeException('FPI failure receipt no longer matches its owned immutable reservation.');
            }
            if ($this->instant($attempt->expires_at)->lte(CarbonImmutable::now('UTC'))) {
                $outcome = 'expired';
                $code = 'lease_expired';
            }
            if ($head && $attempt && $attempt->outcome === null && (int) $attempt->head_id === (int) $head->id
                && hash_equals($attempt->owner_hash, hash('sha256', $state['owner']))) {
                $this->terminal($connection, $head, $attempt, $outcome, $code);
            }
        });
        unset($this->owned[$context]);
    }

    private function terminal(Connection $connection, object $head, object $attempt, string $outcome, ?string $code,
        ?string $responseHash = null, int $rows = 0, array $source = []): void
    {
        $now = $this->now();
        if ($connection->table('cfbd_fpi_season_attempts')->where('id', $attempt->id)->whereNull('outcome')->update([
            'outcome' => $outcome, 'error_code' => $code, 'response_sha256' => $responseHash, 'row_count' => $rows,
            'completed_at' => $now, 'updated_at' => $now, ...$source]) !== 1) {
            throw new RuntimeException('FPI terminal receipt did not persist.');
        }
        $attributes = ['updated_at' => $now, 'latest_terminal_sequence' => max((int) $head->latest_terminal_sequence, (int) $attempt->sequence)];
        if ($outcome === 'applied') {
            $attributes['latest_applied_sequence'] = (int) $attempt->sequence;
        }
        if ($outcome === 'applied' || ($outcome === 'unavailable' && $code === 'empty_source' && $rows === 0 && $source !== [])) {
            $attributes += ['latest_cache_origin_sequence' => (int) $attempt->sequence, 'latest_cache_origin_attempt_id' => $attempt->id,
                'latest_cache_origin_key' => $source['source_cache_key'], 'latest_cache_origin_sha256' => $responseHash,
                'latest_cache_origin_activation_hash' => $attempt->activation_identity_hash, 'latest_cache_origin_source_hash' => $source['accepted_source_identity_hash']];
        }
        $connection->table('cfbd_fpi_season_heads')->where('id', $head->id)->update($attributes);
        $storedHead = $connection->table('cfbd_fpi_season_heads')->useWritePdo()->where('id', $head->id)->lockForUpdate()->first();
        $storedAttempt = $connection->table('cfbd_fpi_season_attempts')->useWritePdo()->where('id', $attempt->id)->lockForUpdate()->first();
        $expectedAttempt = [...(array) $attempt, 'outcome' => $outcome, 'error_code' => $code, 'response_sha256' => $responseHash,
            'row_count' => $rows, 'completed_at' => $now, 'updated_at' => $now, ...$source];
        if ($storedHead === null || $storedAttempt === null || $this->headIdentity($storedHead) !== $this->headIdentity($head)
            || ! $this->matchesInteger($storedHead->issued_sequence, (int) $head->issued_sequence)) {
            throw new RuntimeException('FPI terminal owner or activation header changed during acknowledgement.');
        }
        foreach ([...(array) $head, ...$attributes] as $field => $expected) {
            if (! $this->sameScalar($storedHead->$field ?? null, $expected)) {
                throw new RuntimeException('FPI terminal head state did not persist as planned.');
            }
        }
        foreach ($expectedAttempt as $field => $expected) {
            if (! $this->sameScalar($storedAttempt->$field ?? null, $expected)) {
                throw new RuntimeException('FPI terminal receipt changed during acknowledgement.');
            }
        }
    }

    private function sameScalar(mixed $actual, mixed $expected): bool
    {
        if ($actual === $expected) {
            return true;
        }

        return is_int($expected) && (is_int($actual) || is_string($actual))
            && preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $actual)
            && (string) $actual === (string) $expected;
    }

    private function state(FpiAcquisition $context): array
    {
        $state = $this->owned[$context] ?? null;
        if ($state === null || $state['connection']->getPdo() !== $state['pdo'] || $state['connection']->getTablePrefix() !== ''
            || (new FpiRating)->getConnection() !== $state['connection']
            || ! hash_equals($state['target'], $this->targets->describe($state['connection'])['fingerprint'])) {
            throw new RuntimeException('FPI reservation instance or physical connection changed.');
        }

        return $state;
    }

    private function head(?object $head, int $season, string $target): void
    {
        if ($head === null || (int) $head->season !== $season || (int) $head->protocol_version !== 1
            || ! hash_equals((string) $head->target_fingerprint, $target)
            || $this->instant($head->activated_at)->isFuture()
            || $this->instant($head->drained_at)->gt($this->instant($head->activated_at))) {
            throw new RuntimeException('FPI season requires explicit reviewed target activation and prior writer drain before fetching.');
        }
        $issued = filter_var($head->issued_sequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX]]);
        $terminal = filter_var($head->latest_terminal_sequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $applied = filter_var($head->latest_applied_sequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $origin = filter_var($head->latest_cache_origin_sequence, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($issued === false || $terminal === false || $applied === false || $origin === false || $applied > $origin || $origin > $terminal || $terminal > $issued) {
            throw new RuntimeException('FPI season sequence state is incoherent.');
        }
        foreach (['activation_operator', 'activation_reason', 'drain_reference', 'source_review_reference'] as $field) {
            if (! is_string($head->$field) || trim($head->$field) === '') {
                throw new RuntimeException('FPI activation review statements are missing.');
            }
        }
    }

    private function outsideTransaction(Connection $connection): void
    {
        if ($connection->getTablePrefix() !== '') {
            throw new RuntimeException('FPI protocol requires the declared literal unprefixed stores.');
        }
        if ($connection->getDriverName() === 'mysql') {
            $isolation = $connection->selectOne('SELECT @@transaction_isolation AS isolation_level', [], false)?->isolation_level;
            if ($isolation !== 'REPEATABLE-READ') {
                throw new RuntimeException('FPI range ownership requires the reviewed REPEATABLE READ transaction contract.');
            }
            $this->assertSchema($connection);
        }
        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
            throw new RuntimeException('FPI acquisition requires its own transaction boundary before provider IO.');
        }
    }

    private function assertSchema(Connection $connection): void
    {
        $tables = ['cfb_teams', 'cfbd_team_mappings', 'cfb_team_season_affiliations', 'cfb_fpi_ratings', 'cfbd_fpi_season_heads', 'cfbd_fpi_season_attempts'];
        $engines = $connection->table('information_schema.TABLES')->useWritePdo()->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereIn('TABLE_NAME', $tables)->selectRaw('TABLE_NAME AS table_name, ENGINE AS engine')->get()->keyBy('table_name');
        foreach ($tables as $table) {
            if (strtolower((string) ($engines->get($table)?->engine ?? '')) !== 'innodb') {
                throw new RuntimeException('FPI current-range admission requires every declared table to be InnoDB.');
            }
        }
        $prefixes = [
            'cfbd_team_mappings' => ['cfbd_team_mappings_sport_index', ['sport']],
            'cfb_team_season_affiliations' => ['cfb_team_season_affiliations_season_subdivision_index', ['season']],
            'cfb_fpi_ratings' => ['cfb_fpi_ratings_season_week_index', ['season', 'week']],
        ];
        $indexes = $connection->table('information_schema.STATISTICS')->useWritePdo()->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereIn('TABLE_NAME', $tables)->whereIn('INDEX_NAME', ['PRIMARY', ...array_column($prefixes, 0)])
            ->selectRaw('TABLE_NAME AS table_name, INDEX_NAME AS index_name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS sequence_number')
            ->orderBy('SEQ_IN_INDEX')->get();
        foreach ($tables as $table) {
            $primary = $indexes->where('table_name', $table)->where('index_name', 'PRIMARY')->pluck('column_name')->values()->all();
            if ($primary !== ['id']) {
                throw new RuntimeException('FPI declared source tables require a single literal id primary key.');
            }
        }
        foreach ($prefixes as $table => [$index, $prefix]) {
            $columns = $indexes->where('table_name', $table)->where('index_name', $index)->pluck('column_name')->values()->all();
            if (array_slice($columns, 0, count($prefix)) !== $prefix) {
                throw new RuntimeException('FPI bounded source ranges require the reviewed scope index prefixes.');
            }
        }
    }

    private function season(int $season): void
    {
        if ($season < 1 || $season > 2147483647) {
            throw new RuntimeException('FPI season must fit the existing positive signed-integer storage contract.');
        }
    }

    private function matchesInteger(mixed $raw, int $expected): bool
    {
        return (is_int($raw) || is_string($raw)) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $raw)
            && filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === $expected;
    }

    private function instant(mixed $raw): CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $raw)
            || ($instant = ExplicitOffsetInstant::parse(str_replace(' ', 'T', $raw).'Z')) === null) {
            throw new RuntimeException('FPI receipt clock is not a valid owned UTC carrier.');
        }

        return $instant;
    }

    private function now(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
    }
}
