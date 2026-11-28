<?php

use App\Models\CFB\Game;
use App\Models\CFB\SourceAssessmentRevision;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Data\CfbSourceRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Storage::fake('local');
    config(['cfb.data.source_disk' => 'local', 'database.connections.cfb_source_writer' => config('database.connections.mysql')]);
    $this->game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(), 'home_score' => 7, 'away_score' => 0]);
    $this->payload = ['synthetic' => 'unchanged body'];
    $this->valid = ['state' => 'complete', 'discrepancies' => [], 'evidence' => []];
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    while (DB::connection('cfb_source_writer')->transactionLevel() > 0) {
        DB::connection('cfb_source_writer')->rollBack();
    }
    DB::purge('cfb_source_writer');
});

it('refuses stale accepted game context behind a pinned repeatable-read snapshot', function () {
    $source = app(CfbSourceRevision::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->valid);
    $check->update(['accepted_at' => now()]);
    $accepted = SourceAssessmentRevision::orderByDesc('id')->firstOrFail()->getAttributes();
    DB::beginTransaction();
    DB::table('cfb_games')->where('id', $this->game->id)->first();
    DB::connection('cfb_source_writer')->table('cfb_games')->where('id', $this->game->id)->update(['away_score' => 3]);
    $recorder = app(CfbSourceAssessmentRecorder::class);
    expect($recorder->acceptedForGame($this->game, 'boxscore'))->toBeNull();
    $current = $source->record($this->game, 'boxscore', $this->payload, $this->valid);
    DB::commit();
    expect($current->state)->toBe('partial')->and($current->accepted_at)->toBeNull()
        ->and($current->discrepancies)->toContain('game_context_changed_before_assessment')
        ->and(SourceAssessmentRevision::whereKey($accepted['id'])->first()->getAttributes())->toBe($accepted);
});

it('sees a partial reassessment committed by a named connection after an old snapshot', function () {
    $source = app(CfbSourceRevision::class);
    $check = $source->record($this->game, 'boxscore', $this->payload, $this->valid);
    $check->update(['accepted_at' => now()]);
    DB::beginTransaction();
    DB::table('cfb_game_data_checks')->where('id', $check->id)->first();
    $otherGame = Game::on('cfb_source_writer')->findOrFail($this->game->id);
    $other = $source->record($otherGame, 'boxscore', $this->payload,
        ['state' => 'partial', 'discrepancies' => ['synthetic_new_partial'], 'evidence' => []]);
    expect($other->getConnectionName())->toBe('cfb_source_writer')
        ->and(app(CfbSourceAssessmentRecorder::class)->acceptedForGame($this->game, 'boxscore'))->toBeNull();
    DB::commit();
    expect($check->fresh()->state)->toBe('partial')->and($check->fresh()->accepted_at)->toBeNull();
});

it('reuses new named-connection source receipts committed behind an older snapshot without a duplicate claim', function () {
    DB::beginTransaction();
    DB::table('cfb_games')->where('id', $this->game->id)->first();
    $source = app(CfbSourceRevision::class);
    $otherGame = Game::on('cfb_source_writer')->findOrFail($this->game->id);
    $other = $source->record($otherGame, 'boxscore', $this->payload, $this->valid);
    $other->updateQuietly(['accepted_at' => now()]);
    $before = $other->fresh()->getAttributes();
    $current = $source->record($this->game, 'boxscore', $this->payload, $this->valid);
    expect($current->id)->toBe($other->id)
        ->and(app(CfbSourceAssessmentRecorder::class)->isAccepted($current))->toBeTrue();
    DB::commit();
    expect($current->fresh()->getAttributes())->toBe($before)->and(SourceAssessmentRevision::count())->toBe(2);
});
