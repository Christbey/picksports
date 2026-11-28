<?php

use App\Models\AnalysisWorkItem;
use App\Models\CalculationRelease;
use App\Models\CalculationReleaseComponent;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\FeatureSchema;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionMarket;
use App\Models\PredictionReviewRevision;
use App\Models\SportEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

function immutableCorePrediction(string $state = 'draft'): CanonicalPrediction
{
    $event = SportEvent::factory()->create(['sport' => 'nba']);
    $snapshot = EventInputSnapshot::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba']);
    $release = CalculationRelease::factory()->approved()->create(['sport' => 'nba']);
    $run = CalculationRun::factory()->create(['sport_event_id' => $event->id,
        'event_input_snapshot_id' => $snapshot->id, 'calculation_release_id' => $release->id]);

    return CanonicalPrediction::factory()->create(['sport_event_id' => $event->id, 'sport' => 'nba',
        'calculation_run_id' => $run->id, 'publication_state' => $state, 'revision' => 5, 'phase' => 'pregame',
        'detail_source' => null, 'detail_sport' => null, 'detail_id' => null,
        'published_at' => $state === 'published' ? now() : null]);
}

/** @return array{Model,string,mixed,string} */
function immutableCoreFixture(string $kind): array
{
    if ($kind === 'input') {
        return [EventInputSnapshot::factory()->create(), 'metadata', ['tampered' => true], 'id'];
    }
    if ($kind === 'schema') {
        return [FeatureSchema::create(['sport' => 'nba', 'version' => 'synthetic-v1', 'schema_hash' => str_repeat('a', 64), 'definition' => []]), 'definition', ['tampered' => true], 'id'];
    }
    if ($kind === 'review') {
        $work = AnalysisWorkItem::create(['work_key' => hash('sha256', Str::uuid()), 'sport' => 'nba',
            'game_table' => 'nba_games', 'game_id' => 1, 'desired_fingerprint' => str_repeat('a', 64),
            'input_payload' => [], 'execution_spec' => [], 'evidence' => []]);
        $revision = PredictionReviewRevision::create(['analysis_work_item_id' => $work->id, 'sport' => 'nba',
            'prediction_table' => 'nba_predictions', 'prediction_id' => 5, 'desired_fingerprint' => str_repeat('a', 64),
            'input_hash' => str_repeat('b', 64), 'execution_hash' => str_repeat('c', 64), 'evidence_hash' => str_repeat('d', 64),
            'input_payload' => [], 'execution_spec' => [], 'output' => [], 'decision' => [], 'expires_at' => now()->addHour(), 'created_at' => now()]);

        return [$revision, 'output', ['tampered' => true], 'prediction_id'];
    }
    if (in_array($kind, ['evaluation', 'shadow_evaluation'], true)) {
        $prediction = $kind === 'evaluation' ? immutableCorePrediction() : null;
        $evaluation = PredictionEvaluation::create(['canonical_prediction_id' => $prediction?->id,
            'sport_event_id' => $prediction?->sport_event_id, 'sport' => $prediction ? 'nba' : 'nfl',
            'prediction_table' => $prediction ? 'predictions' : 'synthetic_legacy', 'prediction_id' => $prediction?->id ?? 5,
            'game_id' => 5, 'model_version' => 'synthetic-v1', 'feature_version' => 'synthetic-v1',
            'blend_version' => $prediction ? 'baseline-v1' : 'nfl-prop-shadow-v1', 'evaluation_revision' => 5,
            'actuals' => [], 'errors' => [], 'evaluated_at' => now()]);

        return [$evaluation, 'actuals', ['tampered' => true], 'evaluation_revision'];
    }
    if ($kind === 'shadow_snapshot') {
        $snapshot = PredictionFeatureSnapshot::create(['sport' => 'nfl', 'prediction_table' => 'synthetic_legacy',
            'prediction_id' => 5, 'game_id' => 5, 'model_version' => 'synthetic-v1', 'feature_version' => 'synthetic-v1',
            'blend_version' => 'nfl-prop-shadow-v1', 'features' => [], 'outputs' => [], 'generated_at' => now()]);

        return [$snapshot, 'features', ['tampered' => true], 'prediction_id'];
    }
    if ($kind === 'release') {
        return [CalculationRelease::factory()->approved()->create(), 'configuration', ['tampered' => true], 'id'];
    }
    if ($kind === 'prediction') {
        return [immutableCorePrediction('published'), 'output_metadata', ['tampered' => true], 'revision'];
    }
    if ($kind === 'market') {
        $prediction = immutableCorePrediction();
        $market = PredictionMarket::factory()->create(['prediction_id' => $prediction->id, 'confidence_score' => 50]);
        $prediction->update(['publication_state' => 'published', 'published_at' => now()]);

        return [$market, 'confidence_score', 55, 'confidence_score'];
    }
    $release = CalculationRelease::factory()->create();
    $component = CalculationReleaseComponent::factory()->create(['calculation_release_id' => $release->id, 'weight' => 1]);
    $release->update(['status' => 'approved', 'approved_at' => now(), 'effective_at' => now()]);

    return [$component, 'configuration', ['tampered' => true], 'weight'];
}

it('enforces protected rows through ordinary quiet and numeric model APIs', function (string $kind, string $operation) {
    [$record, $field, $value, $numeric] = immutableCoreFixture($kind);
    $id = $record->getKey();
    $before = $record->fresh()->getAttributes();
    expect(fn () => match ($operation) {
        'update' => $record->update([$field => $value]),
        'updateQuietly' => $record->updateQuietly([$field => $value]),
        'deleteQuietly' => $record->deleteQuietly(),
        'forceDelete' => $record->forceDelete(),
        'increment' => $record->increment($numeric, 1),
        'incrementQuietly' => $record->incrementQuietly($numeric, 1),
        'decrementQuietly' => $record->decrementQuietly($numeric, 1),
    })->toThrow(LogicException::class);
    expect($record->newQuery()->findOrFail($id)->getAttributes())->toBe($before);
})->with(['input', 'schema', 'review', 'evaluation', 'shadow_evaluation', 'shadow_snapshot', 'release', 'prediction', 'market', 'component'])
    ->with(['update', 'updateQuietly', 'deleteQuietly', 'forceDelete', 'increment', 'incrementQuietly', 'decrementQuietly']);

it('refuses stale draft release definition changes after current approval', function () {
    $stale = CalculationRelease::factory()->create();
    $current = $stale->fresh();
    $current->update(['status' => 'approved', 'approved_at' => now(), 'effective_at' => now()]);
    $before = $current->fresh()->getAttributes();
    expect(fn () => $stale->update(['configuration' => ['tampered' => true]]))->toThrow(LogicException::class);
    expect($current->fresh()->getAttributes())->toBe($before);
});

it('refuses stale native draft output changes after current publication', function () {
    $stale = immutableCorePrediction();
    $current = $stale->fresh();
    $current->update(['publication_state' => 'published', 'published_at' => now()]);
    $before = $current->fresh()->getAttributes();
    expect(fn () => $stale->update(['output_metadata' => ['tampered' => true]]))->toThrow(LogicException::class);
    expect($current->fresh()->getAttributes())->toBe($before);
});

it('keeps native draft lineage fixed while allowing legitimate draft outputs', function (string $field) {
    $draft = immutableCorePrediction();
    $draft->update(['output_metadata' => ['permitted_draft_output' => true]]);
    $replacement = match ($field) {
        'calculation_run_id' => null,
        'sport_event_id' => SportEvent::factory()->create(['sport' => 'nba'])->id,
        'phase' => 'live',
    };
    $before = $draft->fresh()->getAttributes();
    expect(fn () => $draft->update([$field => $replacement]))->toThrow(LogicException::class);
    expect($draft->fresh()->getAttributes())->toBe($before);
})->with(['calculation_run_id', 'sport_event_id', 'phase']);

it('checks the persisted old release owner on component reparent numeric extras and dirty deletion', function (string $operation) {
    [$component] = immutableCoreFixture('component');
    $draft = CalculationRelease::factory()->create();
    $before = $component->fresh()->getAttributes();
    expect(function () use ($component, $draft, $operation) {
        return match ($operation) {
            'reparent' => $component->update(['calculation_release_id' => $draft->id]),
            'numeric_extra' => $component->increment('weight', 1, ['calculation_release_id' => $draft->id]),
            'dirty_delete' => (function () use ($component, $draft) {
                $component->calculation_release_id = $draft->id;

                return $component->delete();
            })(),
        };
    })->toThrow(LogicException::class);
    expect($component->fresh()->getAttributes())->toBe($before);
})->with(['reparent', 'numeric_extra', 'dirty_delete']);

it('refuses quiet child creation on already released owners', function (string $kind) {
    if ($kind === 'component') {
        $release = CalculationRelease::factory()->approved()->create();
        $child = new CalculationReleaseComponent(['calculation_release_id' => $release->id, 'component_type' => 'rules', 'role' => 'baseline', 'weight' => 1]);
    } else {
        $prediction = immutableCorePrediction('published');
        $child = new PredictionMarket(['prediction_id' => $prediction->id, 'market_type' => 'moneyline', 'selection' => 'home', 'probability' => 0.5]);
    }
    expect(fn () => $child->saveQuietly())->toThrow(LogicException::class);
    expect($child->newQuery()->count())->toBe(0);
})->with(['component', 'market']);

it('keeps intentionally mutable legacy evaluation and nonshadow snapshot projections writable', function (string $kind) {
    [$protected, $field, $value] = immutableCoreFixture($kind === 'evaluation' ? 'shadow_evaluation' : 'shadow_snapshot');
    $legacy = $protected->replicate();
    $legacy->blend_version = 'baseline-v1';
    $legacy->save();
    $legacy->updateQuietly([$field => $value]);
    expect($legacy->fresh()->getAttribute($field))->toBe($value);
    $legacy->delete();
    expect($legacy->newQuery()->find($legacy->id))->toBeNull();
})->with(['evaluation', 'snapshot']);

it('protects pending calculation run lineage even through quiet updates', function (string $field) {
    $run = CalculationRun::factory()->create();
    $replacement = match ($field) {
        'event_input_snapshot_id' => EventInputSnapshot::factory()->create()->id,
        'calculation_release_id' => CalculationRelease::factory()->approved()->create()->id,
    };
    $before = $run->fresh()->getAttributes();
    expect(fn () => $run->updateQuietly([$field => $replacement]))->toThrow(LogicException::class);
    expect($run->fresh()->getAttributes())->toBe($before);
})->with(['event_input_snapshot_id', 'calculation_release_id']);

it('refuses stale run writes after another instance succeeds while preserving pending recovery writes', function () {
    $stale = CalculationRun::factory()->create();
    $stale->update(['status' => 'running', 'started_at' => now()]);
    $current = $stale->fresh();
    $current->update(['status' => 'succeeded', 'completed_at' => now(), 'output_hash' => str_repeat('a', 64)]);
    $before = $current->fresh()->getAttributes();
    expect(fn () => $stale->update(['diagnostics' => ['tampered' => true]]))->toThrow(LogicException::class);
    expect($current->fresh()->getAttributes())->toBe($before);
});

it('refuses quiet deletion of an unreferenced calculation run at the application boundary', function () {
    $run = CalculationRun::factory()->create();
    expect(fn () => $run->deleteQuietly())->toThrow(LogicException::class);
    expect($run->fresh())->not->toBeNull();
});

it('refuses unsaved numeric instance calls before any implicit table-wide update', function (string $operation) {
    EventInputSnapshot::factory()->create(['id' => 2001]);
    EventInputSnapshot::factory()->create(['id' => 2002]);
    $before = EventInputSnapshot::orderBy('id')->get()->map->getAttributes()->all();
    $blank = new EventInputSnapshot;
    expect(fn () => $blank->{$operation}('id', 1000))->toThrow(LogicException::class);
    expect(EventInputSnapshot::orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
})->with(['increment', 'decrement', 'incrementQuietly', 'decrementQuietly']);

it('preserves supported native draft transitions and refuses legacy masquerading', function (string $next) {
    $draft = immutableCorePrediction();
    $patch = ['publication_state' => $next];
    if ($next === 'withdrawn') {
        $patch['withdrawn_at'] = now();
    }
    if (in_array($next, ['legacy', 'superseded'], true)) {
        expect(fn () => $draft->update($patch))->toThrow(LogicException::class);
        expect($draft->fresh()->publication_state)->toBe('draft');
    } else {
        $draft->update($patch);
        expect($draft->fresh()->publication_state)->toBe($next);
    }
})->with(['draft', 'published', 'withdrawn', 'legacy', 'superseded']);

it('refuses native drafts with a missing withdrawal timestamp and inconsistent native legacy states', function () {
    $draft = immutableCorePrediction();
    expect(fn () => $draft->update(['publication_state' => 'withdrawn']))->toThrow(LogicException::class);
    $inconsistent = immutableCorePrediction('legacy');
    expect(fn () => $inconsistent->update(['output_metadata' => ['laundered' => true]]))->toThrow(LogicException::class);
});
