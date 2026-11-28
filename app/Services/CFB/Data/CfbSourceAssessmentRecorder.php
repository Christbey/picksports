<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\SourceAssessmentRevision;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class CfbSourceAssessmentRecorder
{
    public function context(Game $game): array
    {
        return $game->only(['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'status', 'home_score', 'away_score']);
    }

    public function latest(GameDataCheck $check): ?SourceAssessmentRevision
    {
        return SourceAssessmentRevision::on($check->getConnectionName())->where('game_data_check_id', $check->id)
            ->orderByDesc('revision')->lockForUpdate()->first();
    }

    public function assessmentHash(array $validation, array $context, ?array $origin = null): string
    {
        return app(CanonicalPayloadHasher::class)->hash(['validation' => $validation, 'context' => $context, 'origin' => $origin]);
    }

    public function manifestIdentity(array $manifest): array
    {
        return array_intersect_key($manifest, array_flip(['id', 'provider_source_file_id', 'provider', 'dataset']));
    }

    public function projectionMatches(GameDataCheck $check, array $validation): bool
    {
        $hasher = app(CanonicalPayloadHasher::class);
        if ($check->state !== $validation['state'] || $hasher->hash($check->discrepancies) !== $hasher->hash($validation['discrepancies'])) {
            return false;
        }
        foreach ($validation['evidence'] as $key => $value) {
            if (! array_key_exists($key, $check->evidence ?? []) || $hasher->hash($check->evidence[$key]) !== $hasher->hash($value)) {
                return false;
            }
        }

        return true;
    }

    private function observedQuery(Game $game, string $component, array $versions = [], ?string $prefix = null, ?CarbonInterface $asOf = null): Builder
    {
        return SourceAssessmentRevision::on($game->getConnectionName())
            ->select('cfb_source_assessment_revisions.*')
            ->join('cfb_game_data_checks as assessed_check', 'assessed_check.id', '=', 'cfb_source_assessment_revisions.game_data_check_id')
            ->where('assessed_check.game_id', $game->id)->where('assessed_check.component', $component)
            ->when($versions !== [] || $prefix !== null, fn ($query) => $query->where(function ($family) use ($versions, $prefix): void {
                if ($versions !== []) {
                    $family->whereIn('assessed_check.validator_version', $versions);
                }
                if ($prefix !== null) {
                    $family->orWhere('assessed_check.validator_version', 'like', $prefix.'%');
                }
            }))
            ->when($asOf !== null, fn ($query) => $query->where('cfb_source_assessment_revisions.recorded_at', '<=', $asOf->copy()->setTimezone(config('app.timezone', 'UTC'))->format('Y-m-d H:i:s.u')))
            ->orderByDesc('cfb_source_assessment_revisions.id');
    }

    /** Most recent observed source in the requested family; unknown imported history is never promoted. */
    public function currentCheck(Game $game, string $component, array $versions = [], ?string $prefix = null, ?CarbonInterface $asOf = null): ?GameDataCheck
    {
        return $game->getConnection()->transaction(function () use ($game, $component, $versions, $prefix, $asOf): ?GameDataCheck {
            Game::on($game->getConnectionName())->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $receipt = $this->observedQuery($game, $component, $versions, $prefix, $asOf)
                ->where('evidence_kind', 'observed_assessment')->lockForUpdate()->first();

            return $receipt === null ? null : GameDataCheck::on($game->getConnectionName())->whereKey($receipt->game_data_check_id)->lockForUpdate()->first();
        });
    }

    public function acceptedForGame(Game $game, string $component, array $versions = [], ?string $prefix = null, ?CarbonInterface $asOf = null): ?GameDataCheck
    {
        return $game->getConnection()->transaction(function () use ($game, $component, $versions, $prefix, $asOf): ?GameDataCheck {
            $check = $this->currentCheck($game, $component, $versions, $prefix, $asOf);

            $receipt = $check === null ? null : $this->acceptanceReceipt($check, $asOf);

            return $receipt !== null && $receipt->context_hash === app(CanonicalPayloadHasher::class)->hash($this->context($game)) ? $check : null;
        });
    }

    /** Receipt ID is the fencing token; use it together with the immutable raw source hash. */
    public function acceptanceReceipt(GameDataCheck $check, ?CarbonInterface $asOf = null): ?SourceAssessmentRevision
    {
        return $check->getConnection()->transaction(function () use ($check, $asOf): ?SourceAssessmentRevision {
            $game = Game::on($check->getConnectionName())->whereKey($check->game_id)->lockForUpdate()->first();
            $current = $check->newQuery()->whereKey($check->id)->lockForUpdate()->first();
            if ($game === null || $current === null || $current->accepted_at === null || $current->state !== 'complete'
                || $current->accepted_at->gt($asOf ?? now())) {
                return null;
            }
            $latestAccepted = $this->observedQuery($game, $check->component, asOf: $asOf)
                ->whereIn('evidence_kind', ['observed_assessment', 'acceptance_transition'])
                ->whereNotNull('projection_snapshot->accepted_at')->lockForUpdate()->first();
            $origin = $this->observedQuery($game, $check->component, asOf: $asOf)
                ->where('game_data_check_id', $check->id)->where('evidence_kind', 'observed_assessment')->lockForUpdate()->first();
            $contextHash = app(CanonicalPayloadHasher::class)->hash($this->context($game));
            if ($latestAccepted?->game_data_check_id !== $check->id || $origin === null || $origin->context_hash !== $contextHash
                || $latestAccepted->context_hash !== $contextHash
                || data_get($latestAccepted->projection_snapshot, 'accepted_at') !== $current->attributesToArray()['accepted_at']) {
                return null;
            }
            $validation = array_intersect_key($origin->projection_snapshot, array_flip(['state', 'discrepancies', 'evidence']));

            return $this->projectionMatches($current, $validation) ? $latestAccepted : null;
        });
    }

    public function isAccepted(GameDataCheck $check, ?CarbonInterface $asOf = null): bool
    {
        return $this->acceptanceReceipt($check, $asOf) !== null;
    }

    public function baseline(GameDataCheck $check): void
    {
        if ($this->latest($check) === null) {
            $this->append($check, null, 'imported_unknown_baseline');
        }
    }

    public function append(GameDataCheck $check, ?array $context, string $kind, ?array $manifest = null): void
    {
        if ($check->getConnection()->transactionLevel() < 1) {
            throw new \LogicException('Source assessment requires its transaction.');
        }
        $previous = $this->latest($check);
        $projection = $check->attributesToArray();
        // SQL storage bookkeeping is not source/assessment identity.
        unset($projection['created_at'], $projection['updated_at']);
        $validation = $check->only(['state', 'discrepancies', 'evidence']);
        $origin = SourceAssessmentRevision::on($check->getConnectionName())->where('game_data_check_id', $check->id)
            ->where('evidence_kind', 'observed_assessment')->orderByDesc('revision')->lockForUpdate()->first();
        $manifest ??= $kind === 'imported_unknown_baseline' ? null : $origin?->manifest_snapshot;
        $payload = ['game_data_check_id' => $check->id, 'revision' => ($previous?->revision ?? 0) + 1,
            'evidence_kind' => $kind, 'context_hash' => $context === null ? null : app(CanonicalPayloadHasher::class)->hash($context),
            'assessment_hash' => $context === null ? null : ($kind === 'observed_assessment'
                ? $this->assessmentHash($validation, $context, $manifest === null ? null : $this->manifestIdentity($manifest))
                : ($origin?->context_hash === app(CanonicalPayloadHasher::class)->hash($context) ? $origin->assessment_hash : null)),
            'context_snapshot' => $context, 'projection_snapshot' => $projection, 'manifest_snapshot' => $manifest];
        $payload['receipt_hash'] = app(CanonicalPayloadHasher::class)->hash($payload);
        SourceAssessmentRevision::on($check->getConnectionName())->create([...$payload, 'recorded_at' => now()]);
    }

    /** Model writes (including quiet APIs) retain prior projection and acceptance transitions. */
    public function saveProjection(GameDataCheck $check, callable $save): bool
    {
        return $check->getConnection()->transaction(function () use ($check, $save): bool {
            $game = Game::on($check->getConnectionName())->whereKey($check->game_id)->lockForUpdate()->firstOrFail();
            $prior = GameDataCheck::on($check->getConnectionName())->whereKey($check->id)->lockForUpdate()->firstOrFail();
            foreach (['game_id', 'component', 'source_hash', 'validator_version', 'source_path'] as $field) {
                if ($check->isDirty($field)) {
                    throw new DomainException('Source check identity is immutable.');
                }
            }
            $this->baseline($prior);
            $latest = $this->latest($prior);
            $context = $this->context($game);
            $dirty = array_diff_key($check->getDirty(), array_flip(['updated_at']));
            if ($dirty === []) {
                return true;
            }
            if ($check->isDirty('accepted_at') && $check->accepted_at !== null) {
                $origin = SourceAssessmentRevision::on($check->getConnectionName())->where('game_data_check_id', $check->id)
                    ->where('evidence_kind', 'observed_assessment')->orderByDesc('revision')->lockForUpdate()->first();
                $validation = $origin === null ? null : array_intersect_key($origin->projection_snapshot, array_flip(['state', 'discrepancies', 'evidence']));
                if ($prior->state !== 'complete' || $check->state !== 'complete' || $origin?->context_hash !== app(CanonicalPayloadHasher::class)->hash($context)
                    || $validation === null || ! $this->projectionMatches($prior, $validation) || ! $this->projectionMatches($check, $validation)) {
                    throw new DomainException('Acceptance requires a complete assessment of the current game context.');
                }
                if (array_keys($dirty) === ['accepted_at'] && $prior->isCurrentAccepted()) {
                    $check->setRawAttributes($prior->getAttributes(), true);

                    return true;
                }
            }
            $acceptanceChanged = $check->isDirty('accepted_at');
            $saved = $save();
            if ($saved) {
                $this->append($check->fresh(), $context, $acceptanceChanged ? 'acceptance_transition' : 'projection_transition');
            }

            return $saved;
        });
    }
}
