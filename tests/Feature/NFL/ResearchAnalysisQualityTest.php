<?php

use App\AI\Agents\NflGameContextResearchAgent;
use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Models\NFL\TeamStat;
use App\Models\SportsGameContextReport;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\ResearchAnalysisContext;
use App\Services\NFL\Research\ResearchClaimGrounding;
use App\Services\NFL\Research\ResearchRefreshPolicy;
use App\Services\NFL\Research\ResearchUncertaintyPolicy;
use Illuminate\Support\Facades\Artisan;

uses()->group('nfl', 'ai');

it('supplies only completed current regular season results before the target and observation cutoff', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->startOfDay());
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $base = ['home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'season_type' => 2,
        'status' => 'STATUS_FINAL', 'game_date' => '2026-10-20', 'game_time' => '17:00:00', 'home_score' => 30, 'away_score' => 20];
    $prior = Game::factory()->create($base);
    TeamStat::factory()->create(['game_id' => $prior->id, 'team_id' => $home->id, 'total_yards' => 400, 'rushing_yards' => null]);
    foreach ([['season' => 2025], ['season_type' => 1], ['status' => 'STATUS_SCHEDULED'], ['game_date' => '2026-10-25'], ['home_score' => null]] as $excluded) {
        Game::factory()->create([...$base, ...$excluded]);
    }
    $target = Game::factory()->create([...$base, 'game_date' => '2026-10-25', 'status' => 'STATUS_SCHEDULED']);
    $context = app(ResearchAnalysisContext::class)->forGame($target, ['predicted_spread' => 4.5]);
    $team = $context['season_evidence']['teams']['home'];
    expect($team['games'])->toBe(1)->and($team['game_ids'])->toBe([$prior->id])
        ->and($team['scoring_margin'])->toBe(10.0)->and($team['total_yards']['per_game'])->toBe(400.0)
        ->and($team['rushing_yards']['per_game'])->toBeNull()->and($team['rushing_yards']['sample_size'])->toBe(0)
        ->and($context['season_evidence']['teams']['away']['stat_coverage_games'])->toBe(0);
    // A historical target must also exclude completed results after its kickoff.
    $target->game_date = '2026-10-19';
    expect(app(ResearchAnalysisContext::class)->forGame($target, [])['season_evidence']['teams']['home']['games'])->toBe(0);
});

function groundedResearchInput(): array
{
    return ['season_type' => 2, 'official_documents' => [['id' => 7, 'url' => 'https://team.example/news', 'text' => 'The coach confirmed Jordan Love will start Sunday.']],
        'analysis_context' => ['quarterbacks' => ['away' => ['projected_player' => 'Jordan Love']],
            'final_forecast' => ['predicted_spread' => 4.5], 'season_evidence' => ['teams' => ['home' => ['games' => 6]]]]];
}

function groundedResearchClaim(): array
{
    return ['claim' => 'Jordan Love will start.', 'source_url' => 'https://team.example/news', 'evidence_kind' => 'document',
        'document_id' => 7, 'evidence_excerpt' => 'Jordan Love will start Sunday.', 'evidence_reference' => null];
}

it('rejects unrelated citations and fabricated passages while preserving unresolved questions', function () {
    $valid = groundedResearchClaim();
    $invalid = [...$valid, 'evidence_excerpt' => 'Jordan Love is ruled out for Sunday.'];
    $result = app(ResearchClaimGrounding::class)->apply(['facts' => [], 'decision_research' => [
        'supporting' => [$valid, $invalid, [...$valid, 'document_id' => 8]],
        'unresolved' => [[...$invalid, 'blocking' => true, 'scope' => 'game', 'reason_code' => 'material_availability']],
    ]], groundedResearchInput());
    expect($result['decision_research']['supporting'])->toHaveCount(1)
        ->and($result['analysis_quality']['rejected_claims'])->toBe(2)
        ->and($result['decision_research']['unresolved'][0]['blocking'])->toBeTrue()
        ->and($result['decision_research']['unresolved'][0]['evidence_status'])->toBe('unverified_question');
});

it('separates a model projection from independent completed-game evidence', function () {
    $base = ['claim' => 'Internal evidence', 'evidence_kind' => 'application', 'source_url' => '', 'document_id' => null, 'evidence_excerpt' => null];
    $result = app(ResearchClaimGrounding::class)->apply(['decision_research' => ['supporting' => [
        [...$base, 'evidence_reference' => 'analysis_context.final_forecast.predicted_spread'],
        [...$base, 'evidence_reference' => 'analysis_context.season_evidence.teams.home.games'],
        [...$base, 'evidence_reference' => 'invented.record'],
    ]]], groundedResearchInput());
    expect($result['decision_research']['supporting'])->toHaveCount(1)
        ->and($result['decision_research']['model_context'])->toHaveCount(1)
        ->and($result['analysis_quality']['rejected_claims'])->toBe(1);
});

it('distinguishes confirming the same starter from injury or a different starter', function (string $concern, ?string $reported, bool $blocking) {
    $row = [...groundedResearchClaim(), 'reason_code' => 'model_input_conflict', 'scope' => 'game', 'blocking' => true,
        'concern_type' => $concern, 'projected_player' => 'Jordan Love', 'reported_player' => $reported];
    $result = app(ResearchClaimGrounding::class)->apply(['decision_research' => ['unresolved' => [$row]]], groundedResearchInput());
    $item = app(ResearchUncertaintyPolicy::class)->normalize($result['decision_research']['unresolved'][0]);
    expect($item['blocking'])->toBe($blocking);
})->with([
    'same starter confirmed' => ['routine_confirmation', 'Jordan Love', false],
    'routine announcement absent' => ['routine_confirmation', null, false],
    'different starter' => ['starter_change', 'Other Quarterback', true],
    'specific injury' => ['injury', 'Jordan Love', true],
    'conflicting report despite routine label' => ['routine_confirmation', 'Other Quarterback', true],
]);

it('does not reinterpret an ungrounded quarterback conflict or preseason participation', function () {
    foreach ([[2, 'fabricated passage'], [1, 'Jordan Love will start Sunday.']] as [$seasonType, $excerpt]) {
        $row = [...groundedResearchClaim(), 'evidence_excerpt' => $excerpt, 'reason_code' => 'model_input_conflict', 'scope' => 'game', 'blocking' => true,
            'concern_type' => 'routine_confirmation', 'projected_player' => 'Jordan Love', 'reported_player' => 'Jordan Love'];
        $result = app(ResearchClaimGrounding::class)->apply(['decision_research' => ['unresolved' => [$row]]], [...groundedResearchInput(), 'season_type' => $seasonType]);
        expect($result['decision_research']['unresolved'][0]['reason_code'])->toBe('model_input_conflict');
    }
});

it('retains a quarterback hold when synced evidence contains an active injury despite a routine label', function () {
    $input = groundedResearchInput();
    $input['analysis_context']['quarterbacks']['away']['player_id'] = 42;
    $input['synced_injuries'] = [['player_id' => 42, 'status' => 'Questionable']];
    $row = [...groundedResearchClaim(), 'reason_code' => 'material_availability', 'scope' => 'game', 'blocking' => true,
        'concern_type' => 'routine_confirmation', 'projected_player' => 'Jordan Love', 'reported_player' => 'Jordan Love'];
    $result = app(ResearchClaimGrounding::class)->apply(['decision_research' => ['unresolved' => [$row]]], $input);
    expect($result['decision_research']['unresolved'][0]['reason_code'])->toBe('material_availability');
});

it('reports grounding quality separately from prediction performance without treating legacy reports as audited', function () {
    $this->travelTo(now()->setDate(2026, 10, 24));
    foreach ([[1, 2], [2, 5]] as [$game, $rejected]) {
        SportsGameContextReport::create(['sport' => 'nfl', 'game_id' => $game, 'status' => 'partial',
            'prompt_version' => 'v3', 'input_hash' => hash('sha256', (string) $game), 'researched_at' => now(),
            'raw_payload' => ['analysis_quality' => ['version' => 1, 'verified_claims' => 3, 'rejected_claims' => $rejected, 'unverified_questions' => 1]]]);
    }
    expect(Artisan::call('nfl:research-evaluation'))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['research_quality']['audited_reports'])->toBe(2)
        ->and($output['research_quality']['rejected_claims'])->toBe(7);
});

it('reuses grounded unchanged conditional research without clearing its readiness hold', function () {
    $game = new Game(['game_date' => now()->addDay()->toDateString(), 'game_time' => '20:00:00']);
    $report = new SportsGameContextReport(['status' => 'partial', 'researched_at' => now(), 'expires_at' => now()->addHour(),
        'raw_payload' => ['research_fingerprint' => 'evidence', 'candidate_hash' => 'forecast',
            'analysis_quality' => ['version' => 1, 'rejected_claims' => 0, 'unverified_questions' => 0],
            'decision_research' => ['supporting' => [['claim' => 'Support']], 'opposing' => [['claim' => 'Oppose']],
                'unresolved' => [['blocking' => true, 'evidence_status' => 'reference_verified']]]]]);
    $policy = app(ResearchRefreshPolicy::class);
    expect($policy->reusable($report, $game, 'evidence', 'forecast'))->toBeTrue()
        ->and($policy->current($report, $game, 'evidence', 'forecast'))->toBeFalse()
        ->and($policy->reusable($report, $game, 'new_evidence', 'forecast'))->toBeFalse()
        ->and($policy->reusable($report, $game, 'evidence', 'new_forecast'))->toBeFalse();
    $payload = $report->raw_payload;
    $payload['analysis_quality']['unverified_questions'] = 1;
    $report->raw_payload = $payload;
    expect($policy->reusable($report, $game, 'evidence', 'forecast'))->toBeFalse();
});

it('runs grounding through report persistence and holds a response containing a fabricated passage', function () {
    config(['nfl_research.web_search_enabled' => true, 'ai.features.nfl_game_context_research.require_provider_citations' => false]);
    $game = Game::factory()->create(['home_team_id' => Team::factory(), 'away_team_id' => Team::factory(),
        'season' => 2026, 'season_type' => 2, 'game_date' => now()->addDay()->toDateString(), 'status' => 'STATUS_SCHEDULED']);
    $input = groundedResearchInput();
    $packet = ['documents' => [[...$input['official_documents'][0], 'team' => $game->homeTeam->abbreviation, 'title' => 'Starter report', 'content_hash' => 'test']],
        'availability' => [], 'holds' => [], 'source_coverage' => []];
    $this->partialMock(EvidencePacket::class, fn ($mock) => $mock->shouldReceive('forGame')->andReturn($packet));
    $claim = groundedResearchClaim();
    NflGameContextResearchAgent::fake([[
        'analysis_contract_version' => 1, 'status' => 'ready', 'confidence' => 70, 'summary' => 'Conditional analysis.',
        'decision_review' => ['strongest_counterargument' => 'Small sample.', 'reversal_conditions' => ['Different starter announced.'], 'already_priced_into_model' => []],
        'sources' => [['url' => $claim['source_url'], 'title' => 'Starter report', 'source_type' => 'official']],
        'facts' => [[...$claim, 'source_urls' => [$claim['source_url']], 'category' => 'quarterback', 'team_side' => 'both', 'certainty' => 'confirmed']],
        'decision_research' => ['supporting' => [$claim, [...$claim, 'evidence_excerpt' => 'Fabricated injury statement.']],
            'opposing' => [$claim], 'unresolved' => [], 'prop_angles' => []],
    ]])->preventStrayPrompts();
    $result = app(NflWebContextResearchService::class)->research($game);
    $report = $result['report']->fresh();
    expect($report->status)->toBe('partial')
        ->and($report->raw_payload['analysis_quality']['rejected_claims'])->toBe(1)
        ->and($report->raw_payload['decision_research']['supporting'])->toHaveCount(1)
        ->and($report->raw_payload['decision_review']['reversal_conditions'])->toBe(['Different starter announced.'])
        ->and($report->raw_payload['analysis_context']['season_evidence']['season_type'])->toBe(2)
        ->and($report->risk_flags)->toContain('claim_grounding_failed');
});
