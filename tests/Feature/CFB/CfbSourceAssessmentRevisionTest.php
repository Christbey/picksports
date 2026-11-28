<?php

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\SourceAssessmentRevision;
use App\Models\CFB\Team;
use App\Models\ProviderImportManifest;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Data\CfbSourceRevision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config(['cfb.data.source_disk' => 'local']);
    $this->game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory()]);
    $this->payload = ['synthetic' => 'unchanged source bytes'];
    $this->validation = ['state' => 'complete', 'discrepancies' => [], 'evidence' => ['synthetic_observation' => 0]];
});

it('keeps changed validation of identical bytes as a new assessment rather than touching the old state', function () {
    $source = app(CfbSourceRevision::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->update(['accepted_at' => now()]);
    $accepted = SourceAssessmentRevision::orderByDesc('revision')->firstOrFail();
    $bytes = Storage::disk('local')->get($check->source_path);
    $changed = ['state' => 'partial', 'discrepancies' => ['synthetic_missing_cell'], 'evidence' => []];
    $current = $source->record($this->game, 'boxscore', $this->payload, $changed);
    expect($current->id)->toBe($check->id)->and($current->state)->toBe('partial')
        ->and($current->accepted_at)->toBeNull()->and($current->source_hash)->toBe($check->source_hash)
        ->and(SourceAssessmentRevision::count())->toBe(3)
        ->and($accepted->fresh()->projection_snapshot['state'])->toBe('complete')
        ->and($accepted->fresh()->projection_snapshot['accepted_at'])->not->toBeNull()
        ->and(Storage::disk('local')->get($check->source_path))->toBe($bytes)
        ->and(ProviderImportManifest::sole()->status)->toBe('quarantined');
});

it('retains acceptance and timestamps on semantic source reassessment noops including applied evidence', function () {
    $source = app(CfbSourceRevision::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->update(['accepted_at' => now(), 'evidence' => [...$check->evidence, 'raw_fingerprint' => 'synthetic-applied-proof']]);
    $before = $check->fresh()->getAttributes();
    $count = SourceAssessmentRevision::count();
    $this->travel(5)->minutes();
    $same = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    expect($same->getAttributes())->toBe($before)->and(SourceAssessmentRevision::count())->toBe($count);
});

it('records old and new game contexts for unchanged source bytes and requires acceptance of the current assessment', function () {
    $source = app(CfbSourceRevision::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->update(['accepted_at' => now()]);
    $old = SourceAssessmentRevision::orderByDesc('revision')->firstOrFail();
    $this->game->update(['home_score' => 31, 'away_score' => 17]);
    expect(fn () => $check->update(['accepted_at' => now()->addMinute()]))->toThrow(DomainException::class);
    $current = $source->record($this->game->fresh(), 'boxscore', $this->payload, $this->validation);
    expect($current->accepted_at)->toBeNull()->and(SourceAssessmentRevision::count())->toBe(3)
        ->and(SourceAssessmentRevision::orderByDesc('revision')->first()->context_hash)->not->toBe($old->context_hash)
        ->and($old->fresh()->projection_snapshot['accepted_at'])->not->toBeNull();
    $current->update(['accepted_at' => now()]);
    expect($current->fresh()->isCurrentAccepted())->toBeTrue()->and(SourceAssessmentRevision::count())->toBe(4);
});

it('imports prior observations as explicitly unknown context without manufacturing past assessments', function () {
    $check = GameDataCheck::create(['game_id' => $this->game->id, 'component' => 'boxscore', 'source_hash' => hash('sha256', json_encode($this->payload, JSON_PRESERVE_ZERO_FRACTION)),
        'validator_version' => CfbGameDataValidator::VERSION, 'source_path' => 'legacy.json',
        ...$this->validation, 'accepted_at' => now()]);
    expect(SourceAssessmentRevision::count())->toBe(0);
    app(CfbSourceRevision::class)->record($this->game, 'boxscore', $this->payload, $this->validation);
    $baseline = SourceAssessmentRevision::orderBy('revision')->firstOrFail();
    expect($baseline->evidence_kind)->toBe('imported_unknown_baseline')->and($baseline->context_hash)->toBeNull()
        ->and($baseline->context_snapshot)->toBeNull()->and($baseline->projection_snapshot['source_path'])->toBe('legacy.json')
        ->and($check->fresh()->accepted_at)->toBeNull()->and(SourceAssessmentRevision::count())->toBe(2);
});

it('refuses stale acceptance after a new partial assessment and protects receipts through quiet APIs', function () {
    $source = app(CfbSourceRevision::class);
    $stale = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $source->record($this->game, 'boxscore', $this->payload, ['state' => 'partial', 'discrepancies' => ['missing'], 'evidence' => []]);
    expect(fn () => $stale->update(['accepted_at' => now()]))->toThrow(DomainException::class);
    $receipt = SourceAssessmentRevision::firstOrFail();
    expect(fn () => $receipt->updateQuietly(['context_hash' => str_repeat('a', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $receipt->deleteQuietly())->toThrow(LogicException::class)
        ->and(fn () => $receipt->incrementQuietly('revision'))->toThrow(LogicException::class);
});

it('reflects changed validation for an unaccepted identical source', function () {
    $source = app(CfbSourceRevision::class);
    $first = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $second = $source->record($this->game, 'boxscore', $this->payload,
        ['state' => 'partial', 'discrepancies' => ['synthetic_missing'], 'evidence' => []]);
    expect($second->id)->toBe($first->id)->and($second->state)->toBe('partial');
});

it('binds identical source bytes to the explicit provider manifest rather than the first matching hash', function () {
    $source = app(CfbSourceRevision::class);
    $first = $source->record($this->game, 'boxscore', $this->payload, $this->validation, provider: 'synthetic-provider-a');
    $first->update(['accepted_at' => now()]);
    $receipt = SourceAssessmentRevision::where('evidence_kind', 'observed_assessment')->firstOrFail();
    $second = $source->record($this->game, 'boxscore', $this->payload, $this->validation, provider: 'synthetic-provider-b');
    $latest = SourceAssessmentRevision::where('evidence_kind', 'observed_assessment')->orderByDesc('id')->first();
    expect($receipt->manifest_snapshot['provider'])->toBe('synthetic-provider-a')
        ->and($latest->manifest_snapshot['provider'])->toBe('synthetic-provider-b')
        ->and($latest->manifest_snapshot['provider_source_file_id'])->not->toBe($receipt->manifest_snapshot['provider_source_file_id'])
        ->and($second->source_hash)->toBe($first->source_hash)->and($second->accepted_at)->toBeNull();
});

it('orders A B A assessment and acceptance by global receipt IDs under a frozen SQL timestamp', function () {
    $this->freezeTime();
    $source = app(CfbSourceRevision::class);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    $a = $source->record($this->game, 'boxscore', ['body' => 'A'], $this->validation);
    $a->update(['accepted_at' => now()]);
    $b = $source->record($this->game, 'boxscore', ['body' => 'B'], $this->validation);
    $b->update(['accepted_at' => now()]);
    $bReceipt = $recorder->acceptanceReceipt($b)->id;
    expect($recorder->acceptedForGame($this->game, 'boxscore')->id)->toBe($b->id);
    $a = $source->record($this->game, 'boxscore', ['body' => 'A'], $this->validation);
    expect($recorder->currentCheck($this->game, 'boxscore')->id)->toBe($a->id)
        ->and($recorder->acceptedForGame($this->game, 'boxscore'))->toBeNull();
    $a->update(['accepted_at' => now()]);
    expect($recorder->acceptedForGame($this->game, 'boxscore')->id)->toBe($a->id)
        ->and($recorder->acceptanceReceipt($a)->id)->toBeGreaterThan($bReceipt);
    $count = SourceAssessmentRevision::count();
    $source->record($this->game, 'boxscore', ['body' => 'A'], $this->validation);
    expect(SourceAssessmentRevision::count())->toBe($count);
});

it('refuses old accepted context and historical unknown assessments without refreshing providers', function () {
    $source = app(CfbSourceRevision::class);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    $asOf = now()->subMinute();
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->updateQuietly(['accepted_at' => now()]);
    expect($recorder->acceptedForGame($this->game, 'boxscore', asOf: $asOf))->toBeNull();
    $oldAwayScore = (int) $this->game->away_score;
    $this->game->update(['away_score' => $oldAwayScore + 1]);
    expect((int) $this->game->fresh()->away_score)->toBe($oldAwayScore + 1);
    expect($recorder->acceptedForGame($this->game->fresh(), 'boxscore'))->toBeNull()
        ->and($recorder->isAccepted($check))->toBeFalse();
});

it('rolls back source projections when durable assessment recording fails', function () {
    $fail = true;
    SourceAssessmentRevision::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('synthetic assessment write failure');
        }
    });
    try {
        expect(fn () => app(CfbSourceRevision::class)->record($this->game, 'boxscore', $this->payload, $this->validation))->toThrow(RuntimeException::class);
    } finally {
        $fail = false;
    }
    expect(GameDataCheck::where('game_id', $this->game->id)->count())->toBe(0)
        ->and(SourceAssessmentRevision::count())->toBe(0);
    expect(app(CfbSourceRevision::class)->record($this->game, 'boxscore', $this->payload, $this->validation)->state)->toBe('complete');
});

it('does not admit a later assessment inside the same SQL second at a historical cutoff', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00.800000', config('app.timezone')));
    $cutoff = now()->setMicroseconds(100000);
    $check = app(CfbSourceRevision::class)->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->update(['accepted_at' => now()]);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    expect($recorder->currentCheck($this->game, 'boxscore', asOf: $cutoff))->toBeNull()
        ->and($recorder->acceptedForGame($this->game, 'boxscore', asOf: $cutoff))->toBeNull()
        ->and($recorder->acceptedForGame($this->game, 'boxscore', asOf: now()))->not->toBeNull();
});

it('does not certify a stale supplied game against a newer correctly accepted game context', function () {
    $stale = $this->game;
    Game::whereKey($this->game->id)->update(['away_score' => 23]);
    $current = $this->game->fresh();
    $check = app(CfbSourceRevision::class)->record($current, 'boxscore', $this->payload, $this->validation);
    $check->update(['accepted_at' => now()]);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    expect($recorder->acceptedForGame($stale, 'boxscore'))->toBeNull()
        ->and($recorder->acceptedForGame($current, 'boxscore')->id)->toBe($check->id);
});

it('retains an unchanged-score acceptance and rejects changed persisted context despite cached references', function () {
    $this->game->update(['away_score' => 42]);
    $source = app(CfbSourceRevision::class);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->validation);
    $check->updateQuietly(['accepted_at' => now()]);
    $check->load('game');
    $staleGame = $this->game->fresh();
    $this->game->update(['away_score' => 42]);
    expect($recorder->acceptedForGame($this->game->fresh(), 'boxscore')?->id)->toBe($check->id)
        ->and($recorder->isAccepted($check))->toBeTrue();
    $this->game->update(['away_score' => 43]);
    expect((int) $check->game->away_score)->toBe(42)
        ->and((int) $this->game->fresh()->away_score)->toBe(43)
        ->and($recorder->acceptedForGame($staleGame, 'boxscore'))->toBeNull()
        ->and($recorder->acceptedForGame($this->game->fresh(), 'boxscore'))->toBeNull()
        ->and($recorder->isAccepted($check))->toBeFalse();
});
