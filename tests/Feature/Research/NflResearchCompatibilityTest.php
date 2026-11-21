<?php

use App\Models\NFL\ResearchRevision;
use App\Models\SportsGameContextReport;
use App\Services\Research\GameResearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-20 12:00:00 America/Chicago');
    config(['prediction_lifecycle.canonical_reads.nfl' => false]);
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => $this->travelBack());

function nflResearchWithoutComparison($game): void
{
    $report = SportsGameContextReport::query()->create([
        'sport' => 'nfl', 'game_id' => $game->id, 'status' => 'ready',
        'prompt_version' => 'legacy-fixture', 'input_hash' => hash('sha256', 'legacy-evidence'),
        'raw_payload' => [], 'researched_at' => now(), 'expires_at' => now()->addHours(2),
    ]);
    ResearchRevision::query()->create([
        'game_id' => $game->id, 'report_id' => $report->id, 'input_hash' => hash('sha256', 'legacy-revision'),
        'baseline' => [], 'revised' => [], 'brief' => [], 'evidence' => [], 'market' => [], 'created_at' => now(),
    ]);
}

it('requests reassessment when an old NFL revision has no comparison hash and the selected forecast was saved later', function () {
    [$game, $legacy] = PredictionReviewFixtures::game('nfl');
    nflResearchWithoutComparison($game);
    expect(app(GameResearchService::class)->forGame($game, 'nfl')['applicability'])->toBe('current');
    $this->travel(2)->minutes();
    $legacy->update(['predicted_spread' => 8.5]);
    $result = app(GameResearchService::class)->forGame($game->fresh(), 'nfl');

    expect($result['applicability'])->toBe('outdated')
        ->and($result['reason_codes'])->toContain('research_prediction_comparison_missing');
    Http::assertNothingSent();
});

it('marks NFL research outdated when selected native coverage is withdrawn without falling back to legacy', function () {
    [$game, , $native] = PredictionReviewFixtures::game('nfl');
    nflResearchWithoutComparison($game);
    config(['prediction_lifecycle.canonical_reads.nfl' => true]);
    $native->update(['publication_state' => 'withdrawn', 'withdrawn_at' => now()]);

    expect(app(GameResearchService::class)->forGame($game, 'nfl')['applicability'])->toBe('outdated');
    Http::assertNothingSent();
});
