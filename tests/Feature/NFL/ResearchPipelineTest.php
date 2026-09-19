<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Jobs\ESPN\NFL\FetchPlayers;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\ResearchSource;
use App\Models\NFL\Team;
use App\Models\SportsGameContextReport;
use App\Models\User;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\RecommendationEligibility;
use App\Services\NFL\Research\ResearchPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

function researchGame(array $attributes = []): Game
{
    return Game::factory()->create(array_merge(['home_team_id' => (Team::where('abbreviation', 'PHI')->first() ?? Team::factory()->create(['abbreviation' => 'PHI']))->id, 'away_team_id' => (Team::where('abbreviation', 'WAS')->first() ?? Team::factory()->create(['abbreviation' => 'WAS']))->id, 'season' => 2026, 'season_type' => '2', 'status' => 'STATUS_SCHEDULED', 'game_date' => now()->addDay()->toDateString(), 'game_time' => '20:25:00'], $attributes));
}

it('excludes preseason and carries quarterback history across teams without future leakage', function () {
    $game = researchGame();
    $oldTeam = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'experience' => 8]);
    foreach ([['2', -7, 30], ['1', -5, 99], ['2', 3, 99]] as [$type, $days, $attempts]) {
        $prior = researchGame(['home_team_id' => $oldTeam->id, 'game_date' => now()->addDays($days)->toDateString(), 'status' => 'STATUS_FINAL', 'season_type' => $type]);
        PlayerStat::create(['game_id' => $prior->id, 'player_id' => $player->id, 'team_id' => $oldTeam->id, 'passing_attempts' => $attempts, 'passing_yards' => 240]);
    }
    $action = app(GeneratePredictionFromHistoricalElo::class);
    $stats = (new ReflectionMethod($action, 'priorQbStats'))->invoke($action, $player->id, $game->home_team_id, $game);
    expect($stats['attempts'])->toBe(30)->and($stats['games'])->toBe(1);
    expect((new ReflectionMethod($action, 'qbExperienceBucket'))->invoke($action, 8, 0))->toBe('elite_veteran');
});

it('holds a general bet when a specialist disagrees or QB history is missing', function () {
    $s = app(RecommendationEligibility::class);
    $a = ['bet_classification' => 'bet', 'pro_signal_layer' => ['tier' => 'lean', 'market_scores' => ['spread' => ['tier' => 'official_candidate']]]];
    expect($s->evaluate($a, [])['eligible'])->toBeTrue();
    expect($s->evaluate($a, ['qb_form' => ['reason' => 'insufficient_prior_attempts']])['eligible'])->toBeFalse();
    $a['pro_signal_layer']['market_scores']['spread']['tier'] = 'pass';
    expect($s->evaluate($a, [])['eligible'])->toBeFalse()->and($s->evaluate($a, [])['status'])->toBe('pass')->and($s->evaluate($a, [], ['market_stale'])['status'])->toBe('hold');

    $a['pro_signal_layer']['market_scores']['spread']['tier'] = 'official_candidate';
    $missingEpa = $s->evaluate($a, ['true_epa' => ['enabled' => true, 'applied' => false, 'reason' => 'missing_team_metrics']]);
    expect($missingEpa['status'])->toBe('hold')
        ->and($missingEpa['data_reasons'])->toContain('missing_true_epa');
});

it('ingests unordered RSS and records edits as immutable source versions', function () {
    $this->travelTo(now()->setDate(2026, 9, 12));
    $source = ResearchSource::create(['key' => 'PHI:rss', 'team' => 'PHI', 'kind' => 'rss', 'url' => 'https://www.philadelphiaeagles.com/rss/news']);
    $feed = '<rss><channel><item><title>Old</title><pubDate>Sat, 30 Apr 2022 18:10:58 GMT</pubDate><link>https://www.philadelphiaeagles.com/news/old</link></item><item><title>New injury report</title><pubDate>Fri, 11 Sep 2026 20:00:00 GMT</pubDate><link>https://www.philadelphiaeagles.com/news/update</link></item></channel></rss>';
    Http::fake(['*/rss/news' => Http::response($feed), '*/news/update' => Http::sequence()->push('<article>'.str_repeat('New verified injury information. ', 4).'</article>')->push('<article>'.str_repeat('Changed official injury information. ', 4).'</article>')]);
    $ingest = app(OfficialSourceIngestor::class);
    $ingest->poll($source);
    $ingest->poll($source->fresh());
    expect(ResearchDocument::count())->toBe(2)->and(ResearchDocument::first()->body)->toContain('New verified');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/old'));
});

it('rejects entity XML and unapproved source hosts without fetching them', function () {
    Http::fake(['*' => Http::response('<!DOCTYPE rss [<!ENTITY leak SYSTEM "file:///etc/passwd">]><rss/>')]);
    $s = ResearchSource::create(['key' => 'PHI:rss', 'team' => 'PHI', 'kind' => 'rss', 'url' => 'https://www.philadelphiaeagles.com/rss/news']);
    expect(fn () => app(OfficialSourceIngestor::class)->poll($s))->toThrow(RuntimeException::class);
    $s->url = 'https://localhost/rss';
    expect(fn () => app(OfficialSourceIngestor::class)->poll($s))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('skips newsroom categories without letting navigation crowd out current articles', function () {
    $source = ResearchSource::create(['key' => 'DET:newsroom', 'team' => 'DET', 'kind' => 'newsroom', 'url' => 'https://www.detroitlions.com/news/']);
    Http::fake([
        '*/news/' => Http::response('<nav><a href="/news/signup">Signup</a></nav><main><a href="/news/category">Category</a><a href="/news/injury-update">Injury update</a></main>'),
        '*/news/category' => Http::response('<main></main>'),
        '*/news/injury-update' => Http::response('<meta property="article:published_time" content="'.now()->subHour()->toIso8601String().'"><article>'.str_repeat('Official current injury update. ', 4).'</article>'),
    ]);
    app(OfficialSourceIngestor::class)->poll($source);
    expect(ResearchDocument::count())->toBe(1)->and($source->fresh()->error)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/signup'));
});

it('reconciles a verified IR transaction against a stale questionable claim', function () {
    $game = researchGame();
    $player = Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Eli Stowers']);
    $s = ResearchSource::create(['key' => 'PHI:rss', 'team' => 'PHI', 'kind' => 'rss', 'url' => 'https://www.philadelphiaeagles.com/rss/news']);
    ResearchDocument::create(['source_id' => $s->id, 'team' => 'PHI', 'url' => 'https://www.philadelphiaeagles.com/news/ir', 'url_hash' => str_repeat('a', 64), 'content_hash' => str_repeat('b', 64), 'title' => 'Eagles place TE Eli Stowers on Injured Reserve', 'body' => 'Official announcement', 'published_at' => now()->subHour(), 'observed_at' => now()]);
    $service = app(EvidencePacket::class);
    $packet = $service->forGame($game);
    expect($packet['availability'])->toHaveCount(1)->and($packet['availability'][0]['player_id'])->toBe($player->id);
    $result = $service->reconcile(['status' => 'ready', 'facts' => [['claim' => 'Eli Stowers is questionable']], 'risk_flags' => []], $packet);
    expect($result['status'])->toBe('partial')->and(implode(' ', $result['risk_flags']))->toContain('source_conflict');
});

it('does not confuse unavailable or a negated clearance with a positive availability claim', function () {
    $service = app(EvidencePacket::class);
    foreach (['Micah Parsons is unavailable.', 'Micah Parsons is not available.', 'Micah Parsons is on PUP; Other Player is available.', 'Micah Parsons is out. Other Player will play.'] as $claim) {
        expect($service->contradictsUnavailable($claim, 'Micah Parsons'))->toBeFalse();
    }
    expect($service->contradictsUnavailable('Micah Parsons is questionable.', 'Micah Parsons'))->toBeTrue();
    expect($service->contradictsUnavailable('Micah Parsons will play.', 'Micah Parsons'))->toBeTrue();
});

it('invalidates research when a player match is repaired but not just when a source is rechecked', function () {
    $service = app(EvidencePacket::class);
    $packet = ['holds' => ['unlinked_reserve_player:Test'], 'availability' => []];
    $old = $service->contextHash($packet);
    $packet = ['holds' => [], 'availability' => [['player_id' => 1, 'status' => 'Out', 'document_id' => 7, 'observed_at' => now()->toIso8601String()]]];
    $fixed = $service->contextHash($packet);
    $packet['availability'][0]['observed_at'] = now()->addMinute()->toIso8601String();
    expect($fixed)->not->toBe($old)->and($service->contextHash($packet))->toBe($fixed);
});

it('matches accent variants and queues only one roster refresh for missing identities', function () {
    Queue::fake();
    $game = researchGame();
    Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Audric Estime']);
    $service = app(OfficialSourceIngestor::class);
    $service->refreshUnmatchedRoster('PHI', [['player_name' => 'Audric Estimé']]);
    Queue::assertNothingPushed();
    $service->refreshUnmatchedRoster('PHI', [['player_name' => 'Missing Player']]);
    $service->refreshUnmatchedRoster('PHI', [['player_name' => 'Missing Player']]);
    Queue::assertPushed(FetchPlayers::class, 1);
});

it('keeps legacy and malformed uncertainty blocking while accepting explicit scoped uncertainty', function () {
    $service = app(NflWebContextResearchService::class);
    $method = new ReflectionMethod($service, 'decisionResearch');
    $base = ['claim' => 'Unknown status', 'source_url' => 'https://example.com', 'interpretation' => 'Unknown'];
    $result = $method->invoke($service, ['unresolved' => [$base, [...$base, 'scope' => 'invalid', 'blocking' => false], [...$base, 'scope' => 'props', 'blocking' => true], [...$base, 'scope' => 'informational', 'blocking' => false]]], ['https://example.com']);
    expect($result['unresolved'][0]['blocking'])->toBeTrue()->and($result['unresolved'][1]['blocking'])->toBeTrue()->and($result['unresolved'][2]['scope'])->toBe('props')->and($result['unresolved'][3]['blocking'])->toBeFalse();
});

it('requires sourced arguments on both sides before marking research ready', function () {
    $service = app(NflWebContextResearchService::class);
    $method = new ReflectionMethod($service, 'enforceTwoSidedEvidence');
    $oneSided = $method->invoke($service, [
        'status' => 'ready',
        'risk_flags' => [],
        'decision_research' => [
            'supporting' => [['claim' => 'Support', 'source_url' => 'https://example.com/support']],
            'opposing' => [],
        ],
    ]);
    $twoSided = $method->invoke($service, [
        'status' => 'ready',
        'risk_flags' => [],
        'decision_research' => [
            'supporting' => [['claim' => 'Support', 'source_url' => 'https://example.com/support']],
            'opposing' => [['claim' => 'Oppose', 'source_url' => 'https://example.com/oppose']],
        ],
    ]);

    expect($oneSided['status'])->toBe('partial')
        ->and($oneSided['risk_flags'])->toContain('two_sided_research_missing')
        ->and($twoSided['status'])->toBe('ready')
        ->and($twoSided['decision_research']['supporting'][0]['source_url'])->toBe('https://example.com/support')
        ->and($twoSided['decision_research']['opposing'][0]['source_url'])->toBe('https://example.com/oppose');
});

it('buckets immaterial forecast drift without hiding a meaningful move', function () {
    $pipeline = app(ResearchPipeline::class);
    $base = [
        'predicted_spread' => 5.01,
        'predicted_total' => 42.01,
        'win_probability' => .601,
        'model_metadata' => ['true_epa' => ['enabled' => true, 'applied' => true]],
    ];

    expect($pipeline->candidateContextHash($base))
        ->toBe($pipeline->candidateContextHash([...$base, 'predicted_spread' => 5.20, 'predicted_total' => 42.20, 'win_probability' => .603]))
        ->not->toBe($pipeline->candidateContextHash([...$base, 'predicted_spread' => 5.51]));
});

it('changes the material revision hash for meaningful research prose but ignores cosmetic drift', function () {
    $pipeline = app(ResearchPipeline::class);
    $method = new ReflectionMethod($pipeline, 'revisionHash');
    $method->setAccessible(true);
    $preview = [
        'outputs' => ['predicted_spread' => 3.5, 'predicted_total' => 44.5, 'win_probability' => .61],
        'model_metadata' => [],
        'model_version' => 'test-v1',
    ];
    $brief = [
        'supporting' => [[
            'source_url' => 'https://example.com/game',
            'scope' => 'game',
            'claim' => 'The quarterback practiced in full.',
            'interpretation' => 'That raises the passing floor.',
        ]],
        'opposing' => [[
            'source_url' => 'https://example.com/game',
            'scope' => 'game',
            'claim' => 'The left tackle is questionable.',
        ]],
        'unresolved' => [[
            'source_url' => 'https://example.com/game',
            'scope' => 'game',
            'blocking' => true,
            'question' => 'Will the left tackle play?',
        ]],
        'facts' => [[
            'category' => 'injury',
            'team_side' => 'home',
            'certainty' => 'confirmed',
            'claim' => 'The quarterback practiced in full.',
            'source_urls' => ['https://example.com/game'],
            'effective_at' => '2026-09-14T15:00:00Z',
            'observed_at' => '2026-09-14T15:05:00Z',
        ]],
        'risk_flags' => [],
    ];
    $hash = fn (array $materialBrief): string => $method->invoke(
        $pipeline,
        $preview,
        ['documents' => [], 'availability' => []],
        [],
        ['status' => 'candidate', 'data_reasons' => [], 'model_reasons' => []],
        [],
        $materialBrief,
    );
    $cosmetic = $brief;
    $cosmetic['supporting'][0]['claim'] = '  THE quarterback practiced in FULL!!! ';
    $cosmetic['unresolved'][0]['question'] = 'will the LEFT tackle play';
    $cosmetic['facts'][0]['claim'] = 'The quarterback practiced in full';
    $cosmetic['facts'][0]['observed_at'] = '2026-09-14T15:15:00Z';
    $changedClaim = $brief;
    $changedClaim['supporting'][0]['claim'] = 'The quarterback did not practice.';
    $changedQuestion = $brief;
    $changedQuestion['unresolved'][0]['question'] = 'Will the left tackle start?';
    $changedFact = $brief;
    $changedFact['facts'][0]['claim'] = 'The quarterback was limited.';

    expect($hash($cosmetic))->toBe($hash($brief))
        ->and($hash($changedClaim))->not->toBe($hash($brief))
        ->and($hash($changedQuestion))->not->toBe($hash($brief))
        ->and($hash($changedFact))->not->toBe($hash($brief));
});

it('uses the quote timestamp rather than a container refresh to judge market freshness', function () {
    config(['nfl_research.market_freshness_minutes' => 30]);
    $game = researchGame(['odds_updated_at' => now()]);
    $pipeline = app(ResearchPipeline::class);
    $stale = [
        ['bookmaker' => 'book', 'side' => 'home', 'line' => -3.5, 'price' => -110, 'observed_at' => now()->subHour()->toIso8601String()],
        ['bookmaker' => 'book', 'side' => 'away', 'line' => 3.5, 'price' => -110, 'observed_at' => now()->subHour()->toIso8601String()],
    ];
    $freshOneSided = [[...$stale[0], 'observed_at' => now()->subMinutes(5)->toIso8601String()]];
    $freshPaired = [
        ...$freshOneSided,
        [...$stale[1], 'observed_at' => now()->subMinutes(5)->toIso8601String()],
    ];

    expect($pipeline->marketIsFresh($game, $stale))->toBeFalse()
        ->and($pipeline->marketIsFresh($game, $freshOneSided))->toBeFalse()
        ->and($pipeline->marketIsFresh($game, $freshPaired))->toBeTrue();
});

it('persists and hashes only fresh paired research spread quotes', function () {
    config(['nfl_research.market_freshness_minutes' => 30]);
    $freshAt = now()->subMinutes(5)->toIso8601String();
    $staleAt = now()->subHours(2)->toIso8601String();
    $game = researchGame([
        'odds_updated_at' => now(),
        'odds_data' => [
            'home_team' => 'Philadelphia Eagles',
            'bookmakers' => [
                ['key' => 'paired', 'markets' => [[
                    'key' => 'spreads',
                    'last_update' => $freshAt,
                    'outcomes' => [
                        ['name' => 'Philadelphia Eagles', 'point' => -3.5, 'price' => -110],
                        ['name' => 'Washington Commanders', 'point' => 3.5, 'price' => -110],
                    ],
                ]]],
                ['key' => 'one-sided', 'markets' => [[
                    'key' => 'spreads',
                    'last_update' => $freshAt,
                    'outcomes' => [
                        ['name' => 'Philadelphia Eagles', 'point' => -4.0, 'price' => -105],
                    ],
                ]]],
                ['key' => 'stale', 'markets' => [[
                    'key' => 'spreads',
                    'last_update' => $staleAt,
                    'outcomes' => [
                        ['name' => 'Philadelphia Eagles', 'point' => -3.0, 'price' => -110],
                        ['name' => 'Washington Commanders', 'point' => 3.0, 'price' => -110],
                    ],
                ]]],
            ],
        ],
    ]);

    $quotes = app(ResearchPipeline::class)->quotes($game);

    expect($quotes)->toHaveCount(2)
        ->and(collect($quotes)->pluck('bookmaker')->unique()->values()->all())->toBe(['paired'])
        ->and(collect($quotes)->pluck('side')->sort()->values()->all())->toBe(['away', 'home']);
});

it('labels home and away probabilities explicitly for an away favorite', function () {
    $game = researchGame();
    $result = app(NflWebContextResearchService::class)->namedTeamProjections($game, ['predicted_spread' => -5.7, 'win_probability' => .358]);
    expect($result['home'])->toBe(['team' => 'PHI', 'projected_winning_margin' => -5.7, 'win_probability' => .358]);
    expect($result['away'])->toBe(['team' => 'WAS', 'projected_winning_margin' => 5.7, 'win_probability' => .642]);
});

it('saves revised forecasts idempotently without overwriting the original', function () {
    $game = researchGame();
    $original = Prediction::factory()->create(['game_id' => $game->id, 'predicted_spread' => 3, 'predicted_total' => 40]);
    $this->mock(GeneratePredictionFromHistoricalElo::class, function ($mock) {
        $mock->shouldReceive('preview')->times(3)->andReturn(['outputs' => ['predicted_spread' => 5, 'predicted_total' => 42, 'win_probability' => .6], 'model_metadata' => [], 'model_version' => 'revised-test']);
    });
    $pipeline = app(ResearchPipeline::class);
    $a = $pipeline->review($game, false);
    $b = $pipeline->review($game, false);
    expect($a->id)->toBe($b->id)->and((float) $original->fresh()->predicted_spread)->toBe(3.0)->and($a->brief['eligibility']['eligible'])->toBeFalse();
    $original->update(['predicted_spread' => 8]);
    $c = $pipeline->review($game->fresh(), false);
    expect((float) $c->baseline['predicted_spread'])->toBe(3.0)->and((float) $original->fresh()->predicted_spread)->toBe(8.0);
});

it('does not generate a pregame revision after kickoff', function () {
    $game = researchGame(['game_date' => now()->subDay()->toDateString()]);
    expect(app(ResearchPipeline::class)->review($game, false))->toBeNull();
});

it('rotates small research batches so every eligible game is covered', function () {
    config(['nfl_research.enabled' => true]);
    $alreadyReviewed = researchGame();
    $nextGame = researchGame();
    SportsGameContextReport::query()->create([
        'sport' => 'nfl',
        'game_id' => $alreadyReviewed->id,
        'prompt_version' => 'test',
        'input_hash' => hash('sha256', 'test'),
        'researched_at' => now(),
    ]);

    $this->mock(ResearchPipeline::class, function ($mock) use ($nextGame) {
        $mock->shouldReceive('review')
            ->once()
            ->with(Mockery::on(fn (Game $game): bool => $game->is($nextGame)), false)
            ->andReturnNull();
    });

    $this->artisan('nfl:research-pipeline', [
        '--date' => now()->addDay()->toDateString(),
        '--days-forward' => 0,
        '--limit' => 1,
        '--no-ingest' => true,
        '--no-web' => true,
    ])->expectsOutput('NFL research coverage: 2 eligible game(s); reviewing 1 this run; 1 remain for the next batch.')
        ->assertFailed(); // A skipped/null assessment must not report readiness.
});

it('does not equate reserve activation with clearance to play', function () {
    $game = researchGame();
    Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'Test Player']);
    $source = ResearchSource::create(['key' => 'PHI:rss', 'team' => 'PHI', 'kind' => 'rss', 'url' => 'https://www.philadelphiaeagles.com/rss/news']);
    foreach (['Eagles place TE Test Player on IR', 'Eagles activate TE Test Player from IR'] as $i => $title) {
        ResearchDocument::create(['source_id' => $source->id, 'team' => 'PHI', 'url' => $source->url.'/'.$i, 'url_hash' => hash('sha256', (string) $i), 'content_hash' => hash('sha256', $title), 'title' => $title, 'body' => $title, 'published_at' => now()->subHours(2 - $i), 'observed_at' => now()]);
    }
    $packet = app(EvidencePacket::class)->forGame($game);
    expect($packet['availability'])->toBeEmpty()->and($packet['holds'])->toContain('activation_requires_game_status:Test Player');
});

it('grades winner probability and spread pushes separately for each preserved variant', function () {
    $game = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21]);
    $r = ResearchRevision::create(['game_id' => $game->id, 'input_hash' => str_repeat('c', 64), 'baseline' => ['predicted_spread' => 2, 'predicted_total' => 40, 'win_probability' => .5], 'revised' => ['predicted_spread' => 5, 'predicted_total' => 44, 'win_probability' => .6], 'evidence' => [], 'brief' => ['eligibility' => ['eligible' => false]], 'market' => [['bookmaker' => 'fanduel', 'side' => 'home', 'line' => -3, 'price' => -110], ['bookmaker' => 'fanduel', 'side' => 'away', 'line' => 3, 'price' => -110]], 'created_at' => now()->subDay()]);
    app(ResearchPipeline::class)->grade($r);
    expect(data_get($r->fresh()->evaluation, 'variants.revised.quotes.0.result'))->toBe('push')->and(data_get($r->evaluation, 'variants.revised.total_absolute_error'))->toBe(1)->and(data_get($r->evaluation, 'variants.baseline.total_absolute_error'))->toBe(5);
});

it('keeps a revision ungraded until every captured player prop has official results', function () {
    $game = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21]);
    $prop = PlayerProp::query()->create([
        'game_id' => $game->id,
        'player_name' => 'Test Player',
        'market' => 'player_pass_yds',
        'line' => 250.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);
    $revision = ResearchRevision::query()->create([
        'game_id' => $game->id,
        'input_hash' => hash('sha256', 'pending-prop'),
        'baseline' => ['predicted_spread' => 2, 'predicted_total' => 40, 'win_probability' => .5],
        'revised' => ['predicted_spread' => 5, 'predicted_total' => 44, 'win_probability' => .6],
        'evidence' => [],
        'brief' => ['eligibility' => ['eligible' => false], 'player_props' => [[
            'prop_id' => $prop->id,
            'line' => 250.5,
            'over_price' => -110,
            'under_price' => -110,
            'baseline' => ['recommended_side' => 'over'],
            'revised' => ['recommendation' => 'over'],
        ]]],
        'market' => [],
        'created_at' => now()->subDay(),
    ]);

    expect(app(ResearchPipeline::class)->grade($revision))->toBeFalse()
        ->and($revision->fresh()->graded_at)->toBeNull()
        ->and(data_get($revision->fresh()->evaluation, 'player_props.0.status'))->toBe('pending_player_stats');

    $prop->update(['actual_value' => 275, 'graded_at' => now()]);

    expect(app(ResearchPipeline::class)->grade($revision->fresh()))->toBeTrue()
        ->and($revision->fresh()->graded_at)->not->toBeNull()
        ->and(data_get($revision->fresh()->evaluation, 'player_props.0.status'))->toBe('graded');
});

it('regrades research revisions after a same-second official score correction', function () {
    config(['nfl_research.enabled' => true]);
    Carbon::setTestNow('2026-09-16 12:00:00');
    $game = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21]);
    $revision = ResearchRevision::create([
        'game_id' => $game->id, 'input_hash' => hash('sha256', 'corrected-result'),
        'baseline' => ['predicted_spread' => 2, 'predicted_total' => 40, 'win_probability' => .5],
        'revised' => ['predicted_spread' => 5, 'predicted_total' => 44, 'win_probability' => .6],
        'evidence' => [], 'brief' => [], 'market' => [], 'created_at' => now()->subDay(),
    ]);
    app(ResearchPipeline::class)->grade($revision);
    $game->update(['away_score' => 27]);
    $this->artisan('nfl:research-pipeline', ['--grade' => true])
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 1, pending 0.')
        ->assertSuccessful();
    expect(data_get($revision->fresh()->evaluation, 'actual_margin'))->toBe(-3)
        ->and(data_get($revision->fresh()->evaluation, 'actual_total'))->toBe(51);
    Carbon::setTestNow();
});

it('the grade command skips already completed research revisions', function () {
    config(['nfl_research.enabled' => true]);
    $game = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21]);
    ResearchRevision::query()->create([
        'game_id' => $game->id,
        'input_hash' => hash('sha256', 'already-graded'),
        'baseline' => [],
        'revised' => [],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'evaluation' => [],
        'created_at' => now()->subDay(),
        'graded_at' => now(),
    ]);
    $pending = ResearchRevision::query()->create([
        'game_id' => $game->id,
        'input_hash' => hash('sha256', 'not-yet-graded'),
        'baseline' => [],
        'revised' => [],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'created_at' => now(),
    ]);
    $this->mock(ResearchPipeline::class, function ($mock) use ($pending) {
        $mock->shouldReceive('grade')->once()->with(Mockery::on(fn (ResearchRevision $revision): bool => $revision->is($pending)))->andReturnTrue();
    });

    $this->artisan('nfl:research-pipeline', ['--grade' => true])
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 1, pending 0.')
        ->assertSuccessful();
});

it('bounds research grading and never spends the batch on future games', function () {
    config(['nfl_research.enabled' => true]);
    $futureGame = researchGame([
        'status' => 'STATUS_SCHEDULED',
        'home_score' => null,
        'away_score' => null,
    ]);
    $firstFinalGame = researchGame([
        'status' => 'STATUS_FINAL',
        'home_score' => 24,
        'away_score' => 21,
    ]);
    $secondFinalGame = researchGame([
        'status' => 'STATUS_FINAL',
        'home_score' => 27,
        'away_score' => 20,
    ]);
    $future = ResearchRevision::query()->create([
        'game_id' => $futureGame->id,
        'input_hash' => hash('sha256', 'future-ungraded'),
        'baseline' => [],
        'revised' => [],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'created_at' => now()->subDays(3),
    ]);
    $first = ResearchRevision::query()->create([
        'game_id' => $firstFinalGame->id,
        'input_hash' => hash('sha256', 'first-final-ungraded'),
        'baseline' => [],
        'revised' => [],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'created_at' => now()->subDays(2),
    ]);
    $second = ResearchRevision::query()->create([
        'game_id' => $secondFinalGame->id,
        'input_hash' => hash('sha256', 'second-final-ungraded'),
        'baseline' => [],
        'revised' => [],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'created_at' => now()->subDay(),
    ]);

    $this->mock(ResearchPipeline::class, function ($mock) use ($first) {
        $mock->shouldReceive('grade')
            ->once()
            ->with(Mockery::on(fn (ResearchRevision $revision): bool => $revision->is($first)))
            ->andReturnTrue();
    });

    $this->artisan('nfl:research-pipeline', [
        '--grade' => true,
        '--grade-limit' => 1,
        '--grade-batch-size' => 1,
    ])
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 1, pending 0.')
        ->assertSuccessful();

    expect($future->fresh()->graded_at)->toBeNull()
        ->and($second->fresh()->graded_at)->toBeNull();
});

it('rotates pending prop revisions behind never-attempted final revisions and retries after the cooldown', function () {
    config([
        'nfl_research.enabled' => true,
        'nfl_research.grading.retry_after_minutes' => 55,
    ]);
    $pendingGame = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 24, 'away_score' => 21]);
    $nextGame = researchGame(['status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 17]);
    $prop = PlayerProp::query()->create([
        'game_id' => $pendingGame->id,
        'player_name' => 'Pending Player',
        'market' => 'player_pass_yds',
        'line' => 250.5,
        'over_price' => -110,
        'under_price' => -110,
    ]);
    $pending = ResearchRevision::query()->create([
        'game_id' => $pendingGame->id,
        'input_hash' => hash('sha256', 'rotation-pending'),
        'baseline' => ['predicted_spread' => 2, 'predicted_total' => 40, 'win_probability' => .5],
        'revised' => ['predicted_spread' => 3, 'predicted_total' => 42, 'win_probability' => .6],
        'evidence' => [],
        'brief' => ['player_props' => [[
            'prop_id' => $prop->id,
            'line' => 250.5,
            'over_price' => -110,
            'under_price' => -110,
            'baseline' => ['recommended_side' => 'over'],
            'revised' => ['recommendation' => 'over'],
        ]]],
        'market' => [],
        'created_at' => now()->subDays(2),
    ]);
    $next = ResearchRevision::query()->create([
        'game_id' => $nextGame->id,
        'input_hash' => hash('sha256', 'rotation-next'),
        'baseline' => ['predicted_spread' => 1, 'predicted_total' => 37, 'win_probability' => .55],
        'revised' => ['predicted_spread' => 2, 'predicted_total' => 38, 'win_probability' => .57],
        'evidence' => [],
        'brief' => [],
        'market' => [],
        'created_at' => now()->subDay(),
    ]);
    $options = [
        '--grade' => true,
        '--grade-limit' => 1,
        '--grade-batch-size' => 1,
    ];

    $this->artisan('nfl:research-pipeline', $options)
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 0, pending 1.')
        ->assertSuccessful();
    expect($pending->fresh()->grade_attempted_at)->not->toBeNull()
        ->and($pending->fresh()->graded_at)->toBeNull()
        ->and($next->fresh()->grade_attempted_at)->toBeNull();

    $this->artisan('nfl:research-pipeline', $options)
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 1, pending 0.')
        ->assertSuccessful();
    expect($next->fresh()->graded_at)->not->toBeNull();

    $this->artisan('nfl:research-pipeline', $options)
        ->expectsOutput('Inspected 0 final ungraded research revision(s); completed 0, pending 0.')
        ->assertSuccessful();

    $prop->update(['actual_value' => 275, 'graded_at' => now()]);
    $this->travel(56)->minutes();

    $this->artisan('nfl:research-pipeline', $options)
        ->expectsOutput('Inspected 1 final ungraded research revision(s); completed 1, pending 0.')
        ->assertSuccessful();
    expect($pending->fresh()->graded_at)->not->toBeNull();
});

it('rejects invalid research grading batch limits', function () {
    config(['nfl_research.enabled' => true]);

    $this->artisan('nfl:research-pipeline', [
        '--grade' => true,
        '--grade-limit' => 0,
    ])
        ->expectsOutput('--grade-limit must be at least 1.')
        ->assertFailed();

    $this->artisan('nfl:research-pipeline', [
        '--grade' => true,
        '--grade-batch-size' => 0,
    ])
        ->expectsOutput('--grade-batch-size must be at least 1.')
        ->assertFailed();

    $this->artisan('nfl:research-pipeline', [
        '--grade' => true,
        '--grade-retry-after-minutes' => -1,
    ])
        ->expectsOutput('--grade-retry-after-minutes cannot be negative.')
        ->assertFailed();
});

it('reads reserve table captions without mistaking photo links or active players for unavailable players', function () {
    $active = str_repeat('<tr><td><a href="/photo"><img alt=""></a><span><a>Active Player</a></span></td></tr>', 30);
    $html = '<table><caption>Active</caption><tbody>'.$active.'</tbody></table><table><caption>Reserve/Physically Unable to Perform</caption><tbody><tr><td><a href="/photo"><img alt=""></a><span><a>Micah Parsons</a></span></td></tr></tbody></table>';
    $rows = app(OfficialSourceIngestor::class)->roster($html);
    expect($rows)->toHaveCount(31)->and($rows[0]['roster_status'])->toBe('Active')->and($rows[30])->toBe(['player_name' => 'Micah Parsons', 'roster_status' => 'Reserve/Physically Unable to Perform']);
});

it('never follows a source redirect to a private or unapproved host', function () {
    Http::fake(['https://www.philadelphiaeagles.com/rss/news' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin'])]);
    $s = ResearchSource::create(['key' => 'PHI:rss', 'team' => 'PHI', 'kind' => 'rss', 'url' => 'https://www.philadelphiaeagles.com/rss/news']);
    expect(fn () => app(OfficialSourceIngestor::class)->poll($s))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

it('keeps reserve observations separate from injury onset to avoid inventing vacated usage', function () {
    $game = researchGame();
    $p = Player::factory()->create(['team_id' => $game->home_team_id, 'full_name' => 'DJ Montgomery']);
    $s = ResearchSource::create(['key' => 'PHI:roster', 'team' => 'PHI', 'kind' => 'roster', 'url' => 'https://www.philadelphiaeagles.com/team/players-roster/', 'succeeded_at' => now()]);
    ResearchDocument::create(['source_id' => $s->id, 'team' => 'PHI', 'url' => $s->url, 'url_hash' => str_repeat('d', 64), 'content_hash' => str_repeat('e', 64), 'title' => 'Official roster', 'body' => 'Roster', 'structured' => [['player_name' => 'D.J. Montgomery', 'roster_status' => 'Reserve/Injured']], 'observed_at' => now()->subDays(3)]);
    $packet = app(EvidencePacket::class)->forGame($game);
    expect($packet['availability'][0]['injury_onset_known'])->toBeFalse()->and($packet['availability'][0]['status'])->toBe('Out');
});

it('protects researched prediction details behind existing prediction permissions', function () {
    config(['subscriptions.enforce_tiers' => true, 'subscriptions.tier_bypass_user_ids' => []]);
    $game = researchGame();
    $this->getJson('/api/v1/nfl/games/'.$game->id.'/research')->assertUnauthorized();
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->getJson('/api/v1/nfl/games/'.$game->id.'/research')->assertForbidden();
    foreach (['view-nfl-predictions', 'view-prediction-spread', 'view-prediction-win-probability', 'view-prediction-betting-value'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $this->getJson('/api/v1/nfl/games/'.$game->id.'/research')->assertOk()->assertJsonPath('game_id', $game->id);
});
