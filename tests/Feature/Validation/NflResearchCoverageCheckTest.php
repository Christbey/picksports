<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Actions\Validation\Checks\NflResearchCoverageCheck;
use App\Actions\Validation\SportValidator;
use App\Models\NFL\Game;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Services\BettingRecommendations\PlayerPropAnalyzer;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-09-16 12:00:00');
    config(['nfl_research.enabled' => true, 'ai.features.nfl_game_context_research.enabled' => true]);
    Http::preventStrayRequests();
});
afterEach(fn () => $this->travelBack());

function researchCoverageGame(int $hours = 12, array $attributes = []): Game
{
    $kickoff = now()->addHours($hours)->utc();

    return Game::factory()->create([
        'home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'game_date' => $kickoff->toDateString(), 'game_time' => $kickoff->format('H:i:s'),
        'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', ...$attributes,
    ]);
}

function researchCoverageReport(Game $game, array $attributes = []): SportsGameContextReport
{
    return SportsGameContextReport::create([
        'sport' => 'nfl', 'game_id' => $game->id, 'prompt_version' => 'test',
        'input_hash' => fake()->sha256(), 'status' => 'ready',
        'sources' => [['url' => 'https://www.nfl.com/news/verified-report']],
        'researched_at' => now()->subMinutes(5), 'expires_at' => now()->addHours(6), ...$attributes,
    ]);
}

function researchCoverageRevision(Game $game, SportsGameContextReport $report, array $eligibility = []): ResearchRevision
{
    return ResearchRevision::create([
        'game_id' => $game->id, 'report_id' => $report->id, 'input_hash' => fake()->sha256(),
        'baseline' => [], 'revised' => [], 'evidence' => [], 'market' => [],
        'brief' => ['eligibility' => [...['status' => 'pass', 'data_complete' => true, 'data_reasons' => [], 'model_reasons' => ['general_model_does_not_approve']], ...$eligibility]],
        'created_at' => now(),
    ]);
}

function researchCoverageResult(): array
{
    return app(NflResearchCoverageCheck::class)->run('nfl', ['window_days' => 7]);
}

it('fails missing research inside 24 hours and warns for farther games', function () {
    $game = researchCoverageGame(48);
    expect(researchCoverageResult()['status'])->toBe('warning');
    $near = researchCoverageGame();
    $result = researchCoverageResult();
    expect($result['status'])->toBe('failing')
        ->and($result['metadata']['missing_game_ids'])->toEqualCanonicalizing([$game->id, $near->id])
        ->and($result['metadata']['blocking_game_ids'])->toBe([$near->id]);
});

it('passes fresh cited research with a model pass and keeps semantic no-op revision reuse valid', function () {
    $game = researchCoverageGame();
    $linked = researchCoverageReport($game);
    researchCoverageRevision($game, $linked);
    researchCoverageReport($game);
    expect(researchCoverageResult()['status'])->toBe('passing')
        ->and(researchCoverageResult()['metadata']['covered_games'])->toBe(1);
});

it('fails stale reports and expired revision evidence even when the latest report is fresh', function () {
    $game = researchCoverageGame();
    $linked = researchCoverageReport($game, ['expires_at' => now()->subMinute()]);
    researchCoverageRevision($game, $linked);
    expect(researchCoverageResult()['metadata']['stale_game_ids'])->toBe([$game->id]);
    researchCoverageReport($game);
    $result = researchCoverageResult();
    expect($result['status'])->toBe('failing')
        ->and($result['metadata']['stale_game_ids'])->toBe([])
        ->and($result['metadata']['unlinked_revision_game_ids'])->toBe([$game->id]);
});

it('applies the tighter pregame age even before a stored six hour expiry', function () {
    $game = researchCoverageGame(4);
    $report = researchCoverageReport($game, ['researched_at' => now()->subHours(2), 'expires_at' => now()->addHour()]);
    researchCoverageRevision($game, $report);
    $result = researchCoverageResult();
    expect($result['metadata']['stale_game_ids'])->toBe([$game->id])
        ->and($result['metadata']['covered_games'])->toBe(0)
        ->and($result['status'])->toBe('failing');
});

it('fails ready reports missing a cited source or a revision', function () {
    $game = researchCoverageGame();
    $report = researchCoverageReport($game);
    expect(researchCoverageResult()['status'])->toBe('failing');
    researchCoverageRevision($game, $report);
    $report->update(['sources' => [['url' => 'not-a-url']]]);
    expect(researchCoverageResult()['status'])->toBe('failing')
        ->and(researchCoverageResult()['metadata']['uncited_game_ids'])->toBe([$game->id]);
});

it('warns for partial research and explicit data holds rather than forcing a recommendation', function () {
    $game = researchCoverageGame();
    $report = researchCoverageReport($game, ['status' => 'insufficient', 'sources' => []]);
    $revision = researchCoverageRevision($game, $report);
    expect(researchCoverageResult()['status'])->toBe('warning')
        ->and(researchCoverageResult()['metadata']['partial_game_ids'])->toBe([$game->id]);
    $report->update(['status' => 'ready', 'sources' => [['url' => 'https://www.nfl.com/news/source']]]);
    $revision->update(['brief' => ['eligibility' => ['status' => 'hold', 'data_complete' => false, 'data_reasons' => ['missing_quarterback_history']]]]);
    expect(researchCoverageResult()['status'])->toBe('warning')
        ->and(researchCoverageResult()['metadata']['data_hold_game_ids'])->toBe([$game->id]);
});

it('fails disabled research only when eligible games exist', function () {
    config(['nfl_research.enabled' => false]);
    expect(researchCoverageResult()['status'])->toBe('passing');
    researchCoverageGame(48);
    expect(researchCoverageResult()['status'])->toBe('failing');
});

it('includes postseason but excludes preseason past kickoffs finals and games beyond seven days', function () {
    $postseason = researchCoverageGame(12, ['season_type' => '3']);
    $boundary = researchCoverageGame(168);
    researchCoverageGame(12, ['season_type' => '1']);
    researchCoverageGame(-1);
    researchCoverageGame(12, ['status' => 'STATUS_FINAL']);
    researchCoverageGame(169);
    expect(researchCoverageResult()['metadata']['missing_game_ids'])->toEqualCanonicalizing([$postseason->id, $boundary->id])
        ->and(app(NflResearchCoverageCheck::class)->run('cfb', []))->toBeNull();
});

it('registers NFL research coverage in both validator scopes and uses batched queries', function () {
    foreach (range(1, 16) as $number) {
        $game = researchCoverageGame(48);
        researchCoverageRevision($game, researchCoverageReport($game));
    }
    DB::enableQueryLog();
    researchCoverageResult();
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower($query['query']), 'select') && ! str_contains($query['query'], 'sqlite_master'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(3);
    $validator = app(SportValidator::class);
    foreach (['fullChecks', 'dataChecks'] as $property) {
        $checks = (new ReflectionProperty($validator, $property))->getValue($validator);
        expect(collect($checks)->contains(fn ($check) => $check instanceof NflResearchCoverageCheck))->toBeTrue();
    }
    Http::assertNothingSent();
});

it('links newer research immediately while deduplicating repeated reviews of the same report', function (string $status) {
    $game = researchCoverageGame();
    $preview = ['outputs' => ['predicted_spread' => 3, 'predicted_total' => 45, 'win_probability' => 60], 'model_metadata' => [], 'model_version' => 'test'];
    $this->mock(GeneratePredictionFromHistoricalElo::class, fn ($mock) => $mock->shouldReceive('preview')->andReturn($preview));
    $this->mock(PlayerPropAnalyzer::class, fn ($mock) => $mock->shouldReceive('previewNflGame')->andReturn([]));
    $this->mock(EvidencePacket::class, function ($mock) {
        $mock->shouldReceive('forGame')->andReturn(['availability' => [], 'holds' => [], 'source_coverage' => []]);
        $mock->shouldReceive('researchDocuments')->andReturn([]);
        $mock->shouldReceive('contextHash')->andReturn('stable-evidence');
    });
    $pipeline = app(ResearchPipeline::class);
    $attributes = ['status' => $status, 'raw_payload' => ['document_ids' => [], 'evidence_context_hash' => 'stable-evidence',
        'candidate_hash' => $pipeline->candidateContextHash([...$preview['outputs'], 'model_metadata' => []])]];
    $original = researchCoverageReport($game, $attributes);
    $first = $pipeline->review($game, false);
    $fresh = researchCoverageReport($game, $attributes);
    $renewed = $pipeline->review($game, false);
    expect($renewed->id)->not->toBe($first->id);
    $original->update(['expires_at' => now()->subMinute()]);
    expect($pipeline->review($game, false)->id)->toBe($renewed->id);
    expect($renewed->id)->not->toBe($first->id)
        ->and((int) $renewed->report_id)->toBe($fresh->id)
        ->and((int) $first->fresh()->report_id)->toBe($original->id)
        ->and($pipeline->review($game, false)->id)->toBe($renewed->id);
    if ($status === 'partial') {
        expect(data_get($renewed->brief, 'eligibility.status'))->toBe('hold')
            ->and(researchCoverageResult()['status'])->toBe('warning')
            ->and(researchCoverageResult()['metadata']['unlinked_revision_game_ids'])->toBe([]);
    }
    Http::assertNothingSent();
})->with(['ready', 'partial']);

it('readiness fails data holds and stale markets but accepts a completed no-edge pass', function () {
    $game = researchCoverageGame();
    $revision = researchCoverageRevision($game, researchCoverageReport($game));
    $game->update(['odds_updated_at' => now(), 'odds_data' => [
        'home_team' => 'Home', 'away_team' => 'Away',
        'bookmakers' => [['key' => 'book', 'last_update' => now()->toIso8601String(), 'markets' => [
            ['key' => 'spreads', 'outcomes' => [['name' => 'Home', 'point' => -3, 'price' => -110], ['name' => 'Away', 'point' => 3, 'price' => -110]]],
        ]]],
    ]]);
    $this->artisan('nfl:research-readiness')->assertSuccessful();
    $revision->update(['brief' => ['eligibility' => ['status' => 'hold', 'data_complete' => false, 'data_reasons' => ['research_candidate_changed']]]]);
    $this->artisan('nfl:research-readiness')->assertFailed();
    $revision->update(['brief' => ['eligibility' => ['status' => 'pass', 'data_complete' => true, 'data_reasons' => []]]]);
    $odds = $game->odds_data;
    $odds['bookmakers'][0]['last_update'] = now()->subHours(2)->toIso8601String();
    $game->update(['odds_data' => $odds]);
    // A new retrieval timestamp must not disguise an old provider quote.
    $this->artisan('nfl:research-readiness')->assertFailed();
});
