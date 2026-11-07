<?php

use App\Actions\ESPN\CFB\SyncPlayerInjuries;
use App\Models\CFB\AvailabilityReport;
use App\Models\CFB\Game;
use App\Models\CFB\Player;
use App\Models\CFB\PlayerInjury;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\Team;
use App\Models\SportEvent;
use App\Services\CFB\Availability\AvailabilityEvidence;
use App\Services\CFB\Availability\OfficialAvailabilityIngestor;
use App\Services\CFB\CfbPlayerEvidenceService;
use App\Services\CFB\Predictions\CfbPredictionInputQuality;
use App\Services\ESPN\BaseEspnService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 15:00', 'America/Chicago'));
    $this->home = Team::factory()->create(['school' => 'Northwestern', 'abbreviation' => 'NW', 'espn_id' => '77']);
    $this->away = Team::factory()->create(['school' => 'Iowa', 'abbreviation' => 'IOWA']);
    $event = SportEvent::factory()->create(['sport' => 'cfb', 'starts_at' => now()->addHours(4)]);
    $this->game = Game::factory()->create(['season' => 2026, 'game_date' => '2026-11-08', 'sport_event_id' => $event->id,
        'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'status' => 'STATUS_SCHEDULED']);
    $this->qb = Player::factory()->create(['espn_id' => 'chiles', 'team_id' => $this->home->id, 'full_name' => 'Aidan Chiles', 'position' => 'QB']);
    $this->other = Player::factory()->create(['espn_id' => 'other', 'team_id' => $this->away->id, 'full_name' => 'Other Player']);
    $this->report = ['publishDate' => '2026-11-07', 'postedTime' => '14:00:00', 'conferenceTimeZone' => 'CT',
        'footer' => ['date' => '2026-11-07'], 'ReportType' => 'Game Day', 'games' => [
            ['teamName' => 'Northwestern', 'teamDisplayName' => 'Northwestern', 'rows' => [
                ['name' => 'QB #0 Aidan Chiles', 'status' => 'Doubtful', 'exemptStatus' => 'NonExempt']]],
            ['teamName' => 'Iowa', 'rows' => [['name' => 'WR #1 Other Player', 'status' => 'Available']]],
        ]];
    $this->source = config('cfb_availability.conferences.Big Ten');
});

it('ingests the real official widget schema with game scope, player identity and immutable source time', function () {
    $service = app(OfficialAvailabilityIngestor::class);
    $games = collect([$this->game->load(['homeTeam', 'awayTeam', 'sportEvent'])]);
    $service->ingest('10003034', $this->report, $this->source, $games);
    $service->ingest('10003034', $this->report, $this->source, $games);
    expect(AvailabilityReport::count())->toBe(2);
    $evidence = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id);
    expect($evidence['coverage']['complete'])->toBeTrue()
        ->and($evidence['injuries']->sole()->player_id)->toBe($this->qb->id)
        ->and($evidence['injuries']->sole()->status)->toBe('doubtful')
        ->and($evidence['coverage']['source_url'])->toBe($this->source['url']);
    expect(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id, now()->subHours(2))['coverage']['state'])->toBe('unknown');
});

it('carries an official QB absence into quarterback holds even when ESPN has no injury rows', function () {
    $past = Game::factory()->create(['home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'game_date' => '2026-10-31']);
    PlayerStat::create(['game_id' => $past->id, 'team_id' => $this->home->id, 'player_id' => $this->qb->id, 'passing_attempts' => 30]);
    app(OfficialAvailabilityIngestor::class)->ingest('qb', $this->report, $this->source, collect([$this->game]));
    $qb = app(CfbPlayerEvidenceService::class)->quarterback($this->game, $this->home->id);
    expect($qb['quarterback_hold'])->toBeTrue()->and($qb['injury_coverage_complete'])->toBeTrue();
});

it('never interprets no report as a healthy team and flags unknown input coverage', function () {
    $evidence = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id);
    expect($evidence['coverage']['state'])->toBe('unknown')->and($evidence['coverage']['complete'])->toBeFalse();
    $quality = CfbPredictionInputQuality::assess(['require_availability_coverage' => true, 'home' => [], 'away' => []]);
    expect($quality['qualified'])->toBeFalse()->and($quality['risk_flags'])->toContain('home_injury_coverage_unknown', 'away_injury_coverage_unknown');
});

it('keeps unmatched absences visible as partial coverage instead of silently accepting them', function () {
    $report = $this->report;
    $report['games'][0]['rows'][] = ['name' => 'QB #12 Unlinked Starter', 'status' => 'Out'];
    app(OfficialAvailabilityIngestor::class)->ingest('partial', $report, $this->source, collect([$this->game]));
    $evidence = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id);
    expect($evidence['coverage']['state'])->toBe('partial')->and($evidence['coverage']['complete'])->toBeFalse()
        ->and($evidence['coverage']['issues'])->toContain('unmatched_player:Unlinked Starter')
        ->and($evidence['injuries'])->toHaveCount(1);
});

it('retains stale known absences while removing the complete coverage claim', function () {
    $report = $this->report;
    $report['publishDate'] = '2026-11-05';
    app(OfficialAvailabilityIngestor::class)->ingest('stale', $report, $this->source, collect([$this->game]));
    $evidence = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id);
    expect($evidence['coverage']['state'])->toBe('stale')->and($evidence['injuries'])->toHaveCount(1);
});

it('ignores a different opponent or date and rejects empty or future-dated reports', function () {
    $service = app(OfficialAvailabilityIngestor::class);
    $games = collect([$this->game]);
    $wrong = $this->report;
    $wrong['games'][1]['teamName'] = 'Another School';
    expect($service->ingest('wrong', $wrong, $this->source, $games)['reports'])->toBe(0);
    $empty = $this->report;
    $empty['games'][0]['rows'] = [];
    expect(fn () => $service->ingest('empty', $empty, $this->source, $games))->toThrow(RuntimeException::class);
    $future = $this->report;
    $future['postedTime'] = '16:00:00';
    expect(fn () => $service->ingest('future', $future, $this->source, $games))->toThrow(RuntimeException::class);
});

it('records explicit availability from a newer report without clearing unrelated injuries', function () {
    $ingestor = app(OfficialAvailabilityIngestor::class);
    $games = collect([$this->game]);
    $ingestor->ingest('first', $this->report, $this->source, $games);
    $later = $this->report;
    $later['postedTime'] = '14:30:00';
    $later['games'][0]['rows'][0]['status'] = 'Available';
    $ingestor->ingest('next', $later, $this->source, $games);
    expect(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['injuries'])->toHaveCount(0);
    expect(AvailabilityReport::count())->toBe(4);
    $ingestor->ingest('first', $this->report, $this->source, $games);
    expect(PlayerInjury::query()->where('player_id', $this->qb->id)->where('is_active', true)->count())->toBe(0);
});

it('does not let an empty ESPN response deactivate source-verified game corrections', function () {
    $injury = PlayerInjury::create(['team_id' => $this->home->id, 'player_id' => $this->qb->id, 'injury_key' => 'manual',
        'status' => 'Out', 'is_active' => true, 'raw_payload' => ['source' => 'source_verified_user_requested_correction', 'game_id' => $this->game->id]]);
    $action = new class(new BaseEspnService('cfb')) extends SyncPlayerInjuries
    {
        protected function extractTeamInjuries(string $teamEspnId): array
        {
            $this->injuryPayloadObserved = true;
            $this->injuryPayloadReliable = true;

            return [];
        }
    };
    $action->execute('77');
    expect($injury->fresh()->is_active)->toBeTrue();
});

it('isolates source outages and preserves official reports across failed refreshes', function () {
    config()->set('cfb_availability.conferences', ['Big Ten' => $this->source, 'ACC' => config('cfb_availability.conferences.ACC')]);
    Http::fake(fn ($request) => $request['conference'] === 'B10' ? Http::response(['report' => $this->report]) : Http::response([], 503));
    $result = app(OfficialAvailabilityIngestor::class)->sync(collect([$this->game]));
    expect($result['reports'])->toBe(2)->and($result['source_failures'])->toHaveKey('ACC');
    Http::fake(fn () => Http::response([], 503));
    app(OfficialAvailabilityIngestor::class)->sync(collect([$this->game]));
    expect(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['coverage']['complete'])->toBeTrue();
});

it('keeps conflicting statuses partial and conservatively retains the absence', function () {
    $report = $this->report;
    $report['games'][0]['rows'][] = ['name' => 'QB #0 Aidan Chiles', 'status' => 'Available'];
    app(OfficialAvailabilityIngestor::class)->ingest('conflict', $report, $this->source, collect([$this->game]));
    $evidence = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id);
    expect($evidence['coverage']['state'])->toBe('partial')->and($evidence['injuries']->sole()->status)->toBe('doubtful');
});

it('imports a linked absence by full name and position without requiring an ESPN injury', function (string $name, string $position, string $status) {
    $this->qb->update(['full_name' => $name, 'position' => $position]);
    $report = $this->report;
    $report['games'][0]['rows'] = [['name' => $position.' #0 '.$name, 'status' => $status]];
    app(OfficialAvailabilityIngestor::class)->ingest('regression', $report, $this->source, collect([$this->game]));
    $injuries = app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['injuries'];
    expect($injuries->sole()->player_id)->toBe($this->qb->id)->and(strtolower($injuries->sole()->status))->toBe(strtolower($status));
})->with([['Maddux Madsen', 'QB', 'Out'], ['Aidan Chiles', 'QB', 'Doubtful'], ['Malachi Toney', 'WR', 'Out']]);

it('does not convert unknown, exempt or empty report payloads into complete healthy coverage', function () {
    config()->set('cfb_availability.conferences', ['Big Ten' => $this->source]);
    Http::fake(fn () => Http::response([]));
    app(OfficialAvailabilityIngestor::class)->sync(collect([$this->game]));
    expect(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['coverage']['complete'])->toBeFalse();
    $report = $this->report;
    $report['games'][0]['rows'][0]['status'] = 'Exempt';
    app(OfficialAvailabilityIngestor::class)->ingest('exempt', $report, $this->source, collect([$this->game]));
    expect(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['coverage']['state'])->toBe('partial');
});

it('continues later reports after a malformed report and never reassigns a player from another roster', function () {
    config()->set('cfb_availability.conferences', ['Big Ten' => $this->source]);
    $other = Player::factory()->create(['espn_id' => 'duplicate', 'team_id' => $this->home->id, 'full_name' => 'Aidan Chiles']);
    Http::fake(fn () => Http::response(['bad' => ['games' => []], 'good' => $this->report]));
    $result = app(OfficialAvailabilityIngestor::class)->sync(collect([$this->game]));
    expect($result['reports'])->toBe(2)->and($result['source_failures'])->toHaveKey('Big Ten:bad')
        ->and(app(AvailabilityEvidence::class)->forGame($this->game, $this->home->id)['injuries'])->toHaveCount(0);
});

it('publishes official absences in the current injury table and does not let ESPN clear them', function () {
    app(OfficialAvailabilityIngestor::class)->ingest('board', $this->report, $this->source, collect([$this->game]));
    $row = PlayerInjury::query()->where('player_id', $this->qb->id)->sole();
    expect($row->is_active)->toBeTrue()->and($row->raw_payload['source'])->toBe('official_B10')
        ->and($row->raw_payload['game_id'])->toBe($this->game->id);
    $action = new class(new BaseEspnService('cfb')) extends SyncPlayerInjuries
    {
        protected function extractTeamInjuries(string $teamEspnId): array
        {
            $this->injuryPayloadObserved = true;
            $this->injuryPayloadReliable = true;

            return [];
        }
    };
    $action->execute('77');
    expect($row->fresh()->is_active)->toBeTrue();
});

it('reports empty successful HTTP responses as incomplete ingestion rather than success', function () {
    config()->set('cfb_availability.conferences', ['Big Ten' => $this->source]);
    Http::fake(fn () => Http::response([]));
    $this->artisan('cfb:sync-official-availability', ['--season' => 2026, '--game' => $this->game->id])->assertExitCode(2);
    expect(AvailabilityReport::count())->toBe(0);
});

it('reports complete ingestion only when both game reports are actually covered', function () {
    config()->set('cfb_availability.conferences', ['Big Ten' => $this->source]);
    Http::fake(fn () => Http::response(['report' => $this->report]));
    $this->artisan('cfb:sync-official-availability', ['--season' => 2026, '--game' => $this->game->id])->assertSuccessful();
    expect(AvailabilityReport::count())->toBe(2);
});
