<?php

namespace App\Services\Predictions;

use App\Exceptions\EloAdmissionRefused;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CanonicalPrediction;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use App\Services\Sports\Elo\EloAdmissionMode;
use App\Services\Sports\Elo\EloSqlClockPolicy;
use App\Services\Sports\Elo\EloWriterProtocol;
use App\Services\Sports\Elo\EloWriteTargetIdentity;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Facades\DB;

class PredictionPublisher
{
    public function __construct(
        private readonly CanonicalPayloadHasher $hasher,
        private readonly PublicationEffectDelivery $effects,
    ) {}

    public function publish(CanonicalPrediction $prediction): CanonicalPrediction
    {
        $managed = app(EloAdmissionMode::class)->managed($prediction->sport, $prediction->getConnection());
        if ($managed) {
            app(EloWriterProtocol::class)->requireCapability($prediction->sport);
            if ($prediction->getConnection() !== DB::connection()) {
                throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
            }
            app(EloSqlClockPolicy::class)->assertCurrent($prediction->getConnection(), true);
        }

        return DB::transaction(function () use ($prediction, $managed): CanonicalPrediction {
            $eventId = CanonicalPrediction::query()->whereKey($prediction->getKey())->value('sport_event_id');
            $event = SportEvent::query()->lockForUpdate()->findOrFail($eventId);

            $prediction = CanonicalPrediction::query()
                ->with(['calculationRun', 'markets', 'sportEvent'])
                ->lockForUpdate()
                ->findOrFail($prediction->getKey());
            $prediction->setRelation('sportEvent', $event);
            $snapshot = $prediction->calculationRun?->inputSnapshot;
            $evidence = data_get($snapshot?->metadata, 'elo_input_admission');
            if ($managed || $evidence !== null) {
                if ($prediction->getConnection() !== DB::connection()) {
                    throw new EloAdmissionRefused('sql_clock_mixed_source_connection', [], []);
                }
                app(EloSqlClockPolicy::class)->assertCurrent($prediction->getConnection());
                if (! is_array($evidence) || ! EloSqlClockPolicy::matchesPortablePolicy(data_get($snapshot?->metadata, 'elo_sql_clock_policy'))
                    || ($evidence['target_fingerprint'] ?? null) !== app(EloWriteTargetIdentity::class)->describe($prediction->getConnection())['fingerprint']) {
                    throw new EloAdmissionRefused('managed_publication_provenance_unqualified', [], []);
                }
            }

            if ($prediction->publication_state === 'published') {
                DB::afterCommit(fn () => $this->effects->wakeUp($prediction->id));

                return $prediction;
            }

            if ($prediction->publication_state !== 'draft') {
                throw new PredictionLifecycleException('Only draft canonical prediction revisions can be published.');
            }

            if ($prediction->calculationRun?->status !== 'succeeded'
                || ! hash_equals((string) $prediction->output_hash, (string) $prediction->calculationRun?->output_hash)) {
                throw new PredictionLifecycleException('A prediction requires a successful matching calculation run before publication.');
            }

            if (! hash_equals((string) $prediction->output_hash, $this->storedOutputHash($prediction))) {
                throw new PredictionLifecycleException('Canonical prediction output changed after its successful calculation run.');
            }

            $admittedStart = ($event->sport === 'cfb' ? CanonicalEventStart::forCurrentCfbEvent($event, true) : CanonicalEventStart::forEvent($event, true));
            if ($prediction->phase === 'pregame'
                && ($admittedStart === null || now()->greaterThanOrEqualTo($admittedStart) || ! CanonicalEventStart::snapshot($event, $prediction->calculationRun?->inputSnapshot, true))) {
                throw new PredictionLifecycleException('Pregame canonical predictions must be published before the event starts.');
            }

            if (CanonicalPrediction::query()
                ->where('sport_event_id', $prediction->sport_event_id)
                ->where('phase', $prediction->phase)
                ->where('revision', '>', $prediction->revision)
                ->whereNotNull('published_at')
                ->lockForUpdate()
                ->first(['id']) !== null) {
                throw new PredictionLifecycleException('A newer revision has already been published for this event and phase.');
            }

            $current = CanonicalPrediction::query()
                ->where('sport_event_id', $prediction->sport_event_id)
                ->where('phase', $prediction->phase)
                ->where('publication_state', 'published')
                ->whereKeyNot($prediction->getKey())
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                $current->update([
                    'publication_state' => 'superseded',
                    'superseded_at' => now(),
                ]);
            }

            $prediction->update([
                'publication_state' => 'published',
                'published_at' => now(),
            ]);

            $this->effects->record($prediction);
            DB::afterCommit(fn () => $this->effects->wakeUp($prediction->id));

            return $prediction->fresh(['markets', 'calculationRun.release', 'calculationRun.inputSnapshot']);
        });
    }

    private function storedOutputHash(CanonicalPrediction $prediction): string
    {
        $markets = $prediction->markets
            ->sortBy(fn (PredictionMarket $market): string => $market->market_type.':'.$market->selection)
            ->map(fn (PredictionMarket $market): array => [
                'market_type' => $market->market_type,
                'selection' => $market->selection,
                'projected_line' => $market->projected_line === null ? null : (float) $market->projected_line,
                'probability' => $market->probability === null ? null : (float) $market->probability,
                'confidence_score' => $market->confidence_score === null ? null : (float) $market->confidence_score,
            ])
            ->values()
            ->all();

        return $this->hasher->hash([
            'markets' => $markets,
            'metadata' => (array) $prediction->output_metadata,
        ]);
    }
}
