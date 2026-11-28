<?php

namespace App\Services\ProviderData\Refresh;

use App\Models\ProviderRefreshAttempt;
use App\Models\ProviderRefreshHead;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ProviderRefreshCoordinator
{
    public function __construct(private readonly EloWriteTargetIdentity $targets, private readonly CanonicalPayloadHasher $hasher) {}

    public function begin(Model $team, string $component, int $season): RefreshContext
    {
        $sport = strtolower(explode('\\', $team::class)[2] ?? '');
        // Test/custom subclasses retain their canonical table, not their PHP namespace identity.
        if (preg_match('/\A(nfl|nba|cfb|cbb|mlb|wnba|wcbb)_teams\z/', $team->getTable(), $matches)) {
            $sport = $matches[1];
        }
        $scope = ['provider' => 'espn', 'sport' => $sport, 'component' => $component, 'team_id' => $team->getKey(), 'season_key' => $season];
        $supported = $component === 'injuries' ? in_array($sport, ['nfl', 'cfb', 'cbb', 'mlb', 'wnba', 'wcbb'], true) && $season === 0
            : $component === 'depth_chart' && in_array($sport, ['nfl', 'nba', 'mlb'], true) && $season >= 1900 && $season <= (int) CarbonImmutable::now('UTC')->year + 1;
        if ($component === 'depth_chart' && in_array($sport, ['nfl', 'nba', 'mlb'], true)
            && ! $supported && config('provider_refresh.enabled', false) === true) {
            throw new RuntimeException('Refresh depth season is outside the supported explicit scope.');
        }
        $connection = $team->getConnection();
        $schema = $connection->getSchemaBuilder();
        $existsSql = $schema->getConnection()->getSchemaGrammar()->compileTableExists(null, $connection->getTablePrefix().'provider_refresh_heads');
        if (! is_string($existsSql)) {
            throw new RuntimeException('Unsupported authoritative refresh metadata connection.');
        }
        $installed = (bool) $connection->scalar($existsSql, [], false);
        $enabled = config('provider_refresh.enabled', false) === true;
        if (! $installed) {
            if ($enabled && $supported) {
                throw new RuntimeException('Install provider refresh ordering storage before admission.');
            }

            return new RefreshContext($this, $team, null, null);
        }

        return $connection->transaction(function () use ($team, $scope, $supported, $enabled, $connection): RefreshContext {
            $current = $team->newQuery()->whereKey($team->getKey())->lockForUpdate()->firstOrFail();
            if ((string) $current->espn_id !== (string) $team->espn_id) {
                throw new RuntimeException('Refresh team identity changed before acquisition.');
            }
            $head = ProviderRefreshHead::on($connection->getName())->where($scope)->lockForUpdate()->first();
            if ($head === null) {
                if ($enabled && $supported) {
                    throw new RuntimeException('Refresh scope requires explicit reviewed activation and drain evidence.');
                }

                return new RefreshContext($this, $team, null, null);
            }
            if (! $supported || ! $enabled) {
                throw new RuntimeException('Activated refresh scope cannot fall back to a disabled or unsupported legacy writer.');
            }
            $this->validateHead($head, $team);
            $owner = bin2hex(random_bytes(32));
            $lease = config('provider_refresh.lease_seconds', 120);
            if (! is_int($lease) || $lease < 91 || $lease > 300) {
                throw new RuntimeException('Invalid refresh attempt lease.');
            }
            $startedAt = CarbonImmutable::now('UTC');
            $attempt = ProviderRefreshAttempt::on($connection->getName())->create(['head_id' => $head->id,
                'sequence' => $head->issued_sequence + 1, 'owner_hash' => hash('sha256', $owner), 'team_provider_id' => (string) $current->espn_id,
                'started_at' => $startedAt, 'expires_at' => $startedAt->addSeconds($lease)]);
            $head->forceFill(['issued_sequence' => $attempt->sequence])->save();

            return new RefreshContext($this, $team, $attempt, $owner, $scope);
        });
    }

    private function validateHead(ProviderRefreshHead $head, Model $team, bool $requireEnabled = true): void
    {
        $target = $this->targets->describe($team->getConnection())['fingerprint'];
        $approved = config('provider_refresh.write_target_fingerprint');
        if (! $head->hasRecordedAttestations() || ($requireEnabled && config('provider_refresh.enabled', false) !== true) || $head->protocol_version != 1 || config('provider_refresh.protocol_version', 1) !== 1
            || ! is_string($approved) || ! hash_equals($target, $approved) || ! hash_equals($head->target_fingerprint, $target)
            || $head->activated_at === null || $head->drained_at === null || $head->activated_at->isFuture()
            || $head->drained_at->gt($head->activated_at)) {
            throw new RuntimeException('Refresh protocol, target or reviewed drain admission is unavailable.');
        }
    }

    public function commit(RefreshContext $context, bool $reliable, array $rows, Closure $write): int
    {
        if ($context->attempt === null) {
            return $write();
        }
        $fingerprint = $this->hasher->hash(['rows' => $rows, 'reliable' => $reliable]);

        return $context->team->getConnection()->transaction(function () use ($context, $reliable, $rows, $write, $fingerprint): int {
            [$head, $attempt] = $this->lock($context);
            if ($attempt->outcome !== null) {
                throw new RuntimeException('Refresh attempt is already terminal.');
            }
            if ($attempt->expires_at->lte(CarbonImmutable::now('UTC'))) {
                throw new RuntimeException('Refresh attempt expired before projection.');
            }
            if ($head->issued_sequence !== $attempt->sequence) {
                $this->terminal($head, $attempt, 'superseded', null, $reliable, count($rows), $fingerprint);

                return 0;
            }
            $context->budget->remaining();
            $count = $write();
            $this->terminal($head, $attempt, 'applied', null, $reliable, count($rows), $fingerprint, $context->historyFields(), $context->owner);

            return $count;
        });
    }

    public function finish(RefreshContext $context, string $outcome, string $code): void
    {
        if ($context->attempt === null) {
            return;
        }
        $context->team->getConnection()->transaction(function () use ($context, $outcome, $code): void {
            [$head, $attempt] = $this->lock($context, false);
            if ($attempt->outcome !== null) {
                return;
            }
            $this->terminal($head, $attempt, $attempt->expires_at->lte(CarbonImmutable::now('UTC')) ? 'expired' : $outcome, $code);
        });
    }

    private function lock(RefreshContext $context, bool $validate = true): array
    {
        $team = $context->team->newQuery()->whereKey($context->team->getKey())->lockForUpdate()->firstOrFail();
        $name = $team->getConnection()->getName();
        $head = ProviderRefreshHead::on($name)->whereKey($context->attempt->head_id)->lockForUpdate()->firstOrFail();
        $attempt = ProviderRefreshAttempt::on($name)->whereKey($context->attempt->id)->lockForUpdate()->firstOrFail();
        if (! hash_equals($attempt->owner_hash, hash('sha256', (string) $context->owner)) || (int) $attempt->head_id !== (int) $head->id) {
            throw new RuntimeException('Refresh attempt owner mismatch.');
        }
        if ($context->scope !== [] && $this->hasher->hash($head->only(array_keys($context->scope))) !== $this->hasher->hash($context->scope)) {
            throw new RuntimeException('Refresh scope identity changed after acquisition.');
        }
        if ($validate) {
            $this->validateHead($head, $team);
            if ((string) $team->espn_id !== $attempt->team_provider_id) {
                throw new RuntimeException('Refresh provider team identity changed.');
            }
        }

        return [$head, $attempt];
    }

    /** Explicit operator IDs only; no provider work or global historical scan. */
    public function expire(Connection $connection, array $ids, bool $apply): array
    {
        if ($ids === [] || count($ids) > 100 || count(array_unique($ids)) !== count($ids)
            || collect($ids)->contains(fn ($id) => ! is_int($id) || $id < 1)) {
            throw new RuntimeException('Select one to one hundred unique positive attempt IDs.');
        }
        sort($ids, SORT_NUMERIC);
        $results = [];
        foreach ($ids as $id) {
            $preview = ProviderRefreshAttempt::on($connection->getName())->findOrFail($id);
            $scope = ProviderRefreshHead::on($connection->getName())->findOrFail($preview->head_id);
            if (! in_array($scope->sport, ['nfl', 'nba', 'cfb', 'cbb', 'mlb', 'wnba', 'wcbb'], true)) {
                throw new RuntimeException('Unsupported refresh scope identity.');
            }
            $model = 'App\\Models\\'.strtoupper($scope->sport).'\\Team';
            $results[] = $connection->transaction(function () use ($id, $scope, $model, $connection, $apply): array {
                $team = $model::on($connection->getName())->whereKey($scope->team_id)->lockForUpdate()->firstOrFail();
                $head = ProviderRefreshHead::on($connection->getName())->whereKey($scope->id)->lockForUpdate()->firstOrFail();
                $attempt = ProviderRefreshAttempt::on($connection->getName())->whereKey($id)->lockForUpdate()->firstOrFail();
                if ((int) $attempt->head_id !== (int) $head->id || (int) $head->team_id !== (int) $team->id) {
                    throw new RuntimeException('Refresh expiry scope changed.');
                }
                $eligible = $attempt->outcome === null && $attempt->expires_at->lte(CarbonImmutable::now('UTC'));
                if ($eligible && $apply) {
                    $this->validateHead($head, $team, false);
                    $this->terminal($head, $attempt, 'expired', 'operator_expired_lease');
                }

                return ['attempt_id' => $id, 'eligible' => $eligible, 'applied' => $eligible && $apply, 'outcome' => $attempt->outcome];
            });
        }

        return $results;
    }

    private function terminal(ProviderRefreshHead $head, ProviderRefreshAttempt $attempt, string $outcome, ?string $code,
        bool $reliable = false, int $rows = 0, ?string $fingerprint = null, array $history = [], ?string $owner = null): void
    {
        $attributes = ['outcome' => $outcome, 'completed_at' => CarbonImmutable::now('UTC'), 'observed' => $fingerprint !== null,
            'reliable' => $reliable, 'row_count' => $rows, 'source_fingerprint' => $fingerprint, 'error_code' => $code, ...$history];
        if ($outcome === 'applied') {
            if ($owner === null) {
                throw new RuntimeException('Applied refresh owner proof unavailable.');
            }
            $attempt->completeAppliedFromOwner($owner, $attributes);
        } else {
            $attempt->forceFill($attributes)->save();
        }
        $attributes = [];
        if ($attempt->sequence > $head->latest_terminal_sequence) {
            $attributes += ['latest_terminal_sequence' => $attempt->sequence, 'latest_terminal_attempt_id' => $attempt->id];
        }
        if ($outcome === 'applied') {
            $attributes += ['latest_applied_sequence' => $attempt->sequence, 'latest_applied_attempt_id' => $attempt->id];
        }
        if ($attributes !== []) {
            $head->forceFill($attributes)->save();
        }
    }
}
