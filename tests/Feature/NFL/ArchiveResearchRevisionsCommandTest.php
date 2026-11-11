<?php

use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

it('round trips older research through the archive with exact inline recovery and grading parity', function () {
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $game = Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 14,
    ]);
    $fields = ['game_id' => $game->id, 'input_hash' => str_repeat('a', 64),
        'baseline' => ['predicted_spread' => 5.0, 'predicted_total' => 35, 'win_probability' => .6],
        'revised' => ['predicted_spread' => 9.0, 'predicted_total' => 40, 'win_probability' => .7],
        'brief' => ['eligibility' => ['eligible' => true]], 'evidence' => ['summary' => str_repeat('x', 100_000)],
        'market' => [], 'created_at' => now()->subDays(45)];
    $old = ResearchRevision::create($fields);
    $newest = ResearchRevision::create([...$fields, 'input_hash' => str_repeat('b', 64), 'graded_at' => now()]);
    DB::table('nfl_research_revisions')->where('id', $old->id)->update(['evidence' => '{"empty":{},"list":[]}']);
    $old->refresh();
    $pipeline = app(ResearchPipeline::class);
    expect($pipeline->grade($old))->toBeTrue();
    $grade = $old->evaluation;
    $expected = $old->getRawOriginal();
    $options = ['--before' => now()->subDays(31)->toDateString(), '--through-id' => $newest->id];

    $this->artisan('nfl:archive-research-revisions', $options)->assertSuccessful();
    expect($old->fresh()->payload_archive)->toBeNull();
    $this->artisan('nfl:archive-research-revisions', [...$options, '--apply' => true])->assertSuccessful();
    $cold = $old->fresh();
    expect($cold->getRawOriginal('brief'))->toBe('null')
        ->and($cold->getRawOriginal('evidence'))->toBe('null')
        ->and($cold->brief)->toBe($old->brief)
        ->and($cold->evidence)->toBe($old->evidence)
        ->and($newest->fresh()->payload_archive)->toBeNull()
        ->and($pipeline->grade($cold))->toBeTrue()
        ->and($cold->evaluation)->toBe($grade);
    Storage::disk('provider-local')->assertExists($cold->payload_archive['object_key']);

    $this->artisan('nfl:archive-research-revisions', [...$options, '--restore' => true])->assertSuccessful();
    $restored = $old->fresh();
    expect($restored->payload_archive)->toBeNull()
        ->and($restored->getRawOriginal('brief'))->toBe($expected['brief'])
        ->and($restored->getRawOriginal('evidence'))->toBe($expected['evidence']);
});
