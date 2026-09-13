<?php

use App\Actions\NFL\GeneratePredictionFromHistoricalElo;
use App\Jobs\ESPN\NFL\FetchPlayers;
use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Prediction;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchRevision;
use App\Models\NFL\ResearchSource;
use App\Models\NFL\Team;
use App\Models\User;
use App\Services\NFL\NflWebContextResearchService;
use App\Services\NFL\Research\EvidencePacket;
use App\Services\NFL\Research\OfficialSourceIngestor;
use App\Services\NFL\Research\RecommendationEligibility;
use App\Services\NFL\Research\ResearchPipeline;
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
