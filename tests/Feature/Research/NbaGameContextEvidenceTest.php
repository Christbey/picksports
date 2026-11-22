<?php

use App\Models\NBA\Game;
use App\Models\NBA\Player;
use App\Models\NBA\PlayerStat;
use App\Models\NBA\ResearchContextSnapshot;
use App\Models\NBA\Team;
use App\Models\ResearchSource;
use App\Services\NBA\Research\CaptureGameContext;
use App\Services\NBA\Research\GameContextEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PredictionReviewFixtures;

beforeEach(function () {
    $this->travelTo('2026-11-21 12:00:00 America/Chicago');
    config(['app.url' => 'https://picksports.test', 'research.enabled' => true, 'research.sports.nba.enabled' => true,
        'nba.injuries.research_adapter_enabled' => true, 'nba.injuries.observations_enabled' => true, 'espn.cache.enabled' => false]);
    Http::preventStrayRequests();
    Queue::fake();
    [$this->game] = PredictionReviewFixtures::game('nba');
    $this->game->sportEvent->update(['season' => $this->game->season, 'season_type' => $this->game->season_type]);
    $this->game->update(['venue_city' => 'Target City']);
    config(['nba.injuries.canary_team_ids' => [$this->game->home_team_id, $this->game->away_team_id], 'nba.injuries.canary_expires_at' => now()->addDay()->toIso8601String()]);
    $this->players = [];
    foreach (['home', 'away'] as $side) {
        $teamId = $this->game->{$side.'_team_id'};
        $this->players[$side] = collect(range(1, 5))->map(fn ($i) => Player::factory()->create(['team_id' => $teamId, 'espn_id' => $teamId.'000'.$i]));
        foreach ([1, 3, 5] as $days) {
            $prior = Game::factory()->create(['season' => $this->game->season, 'season_type' => $this->game->season_type,
                'home_team_id' => $teamId, 'away_team_id' => Team::factory(), 'status' => 'STATUS_FINAL', 'period' => 4, 'game_date' => now()->subDays($days)->utc()->toDateString(),
                'game_time' => '01:00:00', 'venue_city' => 'Prior City']);
            foreach ($this->players[$side] as $player) {
                PlayerStat::factory()->create(['game_id' => $prior->id, 'team_id' => $teamId, 'player_id' => $player->id, 'minutes_played' => '48:00']);
            }
        }
    }
    $this->source = ResearchSource::create(['sport' => 'nba', 'source_key' => CaptureGameContext::sourceKey($this->game),
        'kind' => 'structured_espn', 'team_id' => null, 'url' => CaptureGameContext::summaryUrl($this->game),
        'allowed_hosts' => ['site.api.espn.com', 'sports.core.api.espn.com'], 'enabled' => true]);
    $responses = [];
    foreach (['home', 'away'] as $side) {
        $team = Team::findOrFail($this->game->{$side.'_team_id'});
        $positions = [];
        foreach ($this->players[$side] as $i => $player) {
            $positions['slot'.$i] = ['athletes' => [['rank' => 1, 'athlete' => ['id' => $player->espn_id]]]];
        }
        $responses[$side.'_depth'] = ['url' => 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/'.$this->game->season.'/teams/'.$team->espn_id.'/depthcharts',
            'fetched_at' => now()->toIso8601String(), 'error_code' => null, 'payload' => ['count' => 1, 'pageCount' => 1, 'items' => [['positions' => $positions]]]];
    }
    $this->packet = ResearchContextSnapshot::create(['game_id' => $this->game->id, 'source_id' => $this->source->id,
        'identity_hash' => CaptureGameContext::identity($this->game), 'capture_hash' => hash('sha256', 'test-packet'), 'responses' => $responses, 'captured_at' => now()]);
});

afterEach(function () {
    $this->travelBack();
    Queue::assertNothingPushed();
});

it('provides projected lineup historical rotation and venue sequence without inventing a confirmed five', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $result = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at);
    expect($result['coverage'])->toBe(['lineup' => ['home' => true, 'away' => true], 'rotation' => ['home' => true, 'away' => true], 'rest_travel' => ['home' => true, 'away' => true]])
        ->and(array_column($result['unresolved'], 'reason_code'))->toContain('game_starting_five_not_confirmed')
        ->and(collect($result['facts'])->where('topic', 'lineup')->first()['source_scope'])->toBe('projected_depth_chart')
        ->and(collect($result['facts'])->where('topic', 'rotation')->first()['claim'])->toContain('48 minutes/team game');
    expect(collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query'])))->toHaveCount(0);
    DB::disableQueryLog();
    Http::assertNothingSent();
});

it('does not call stale depth data current and leaves historical facts independently available', function () {
    $this->travel(121)->minutes();
    $result = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at);
    expect($result['coverage']['lineup']['home'])->toBeFalse()->and($result['coverage']['rotation']['home'])->toBeTrue();
});

it('refuses incomplete minutes and unverified previous games rather than hiding them', function () {
    PlayerStat::query()->where('team_id', $this->game->home_team_id)->first()->update(['minutes_played' => '--']);
    Game::query()->where('home_team_id', $this->game->away_team_id)->where('id', '!=', $this->game->id)->orderByDesc('game_date')->first()->update(['status' => 'STATUS_SCHEDULED']);
    $r = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at);
    expect($r['coverage']['rotation']['home'])->toBeFalse()->and($r['coverage']['rest_travel']['away'])->toBeFalse();
});

it('retains unknown venue travel and excludes future game observations', function () {
    $this->game->update(['venue_city' => null]);
    Game::query()->where('home_team_id', $this->game->away_team_id)->where('id', '!=', $this->game->id)->orderByDesc('game_date')->first()->update(['game_date' => now()->addDay()->toDateString()]);
    $r = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at);
    expect($r['coverage']['rest_travel'])->toBe(['home' => false, 'away' => false])->and($r['coverage']['rotation']['away'])->toBeFalse();
});

it('invalidates revoked packet sources and out-of-scope games', function () {
    $this->source->update(['enabled' => false]);
    expect(app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at)['coverage']['lineup']['home'])->toBeFalse();
    config(['nba.injuries.canary_team_ids' => []]);
    expect(app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at)['facts'])->toBe([]);
});

it('preserves immutable packets', function () {
    expect(fn () => $this->packet->update(['identity_hash' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => $this->packet->delete())->toThrow(LogicException::class);
});

it('captures only three explicit scoped sources without dispatching assessment or reviews', function () {
    Http::fake(fn () => Http::response(['header' => ['id' => $this->game->espn_event_id]]));
    app(CaptureGameContext::class)->capture($this->game);
    Http::assertSentCount(3);
    expect(DB::table('analysis_work_items')->count())->toBe(0)->and(DB::table('game_research_assessments')->count())->toBe(0);
});

it('requires matching event and team identity before accepting explicit starter flags', function (bool $correctIdentity) {
    $responses = $this->packet->responses;
    $sections = [];
    $competitors = [];
    foreach (['home', 'away'] as $side) {
        $team = Team::findOrFail($this->game->{$side.'_team_id'});
        $competitors[] = ['team' => ['id' => $team->espn_id]];
        $sections[] = ['team' => ['id' => $team->espn_id], 'statistics' => [['athletes' => $this->players[$side]->map(fn ($p) => ['athlete' => ['id' => $p->espn_id], 'starter' => true])->all()]]];
    }
    $responses['summary'] = ['url' => CaptureGameContext::summaryUrl($this->game), 'fetched_at' => now()->toIso8601String(), 'error_code' => null,
        'payload' => ['header' => ['id' => $correctIdentity ? $this->game->espn_event_id : 'wrong-event', 'competitions' => [['date' => $this->game->sportEvent->starts_at->toIso8601String(), 'competitors' => $competitors]]], 'boxscore' => ['players' => $sections]]];
    ResearchContextSnapshot::create(['game_id' => $this->game->id, 'source_id' => $this->source->id, 'identity_hash' => CaptureGameContext::identity($this->game),
        'capture_hash' => hash('sha256', 'starters-packet'), 'responses' => $responses, 'captured_at' => now()]);
    $r = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at);
    expect(collect($r['facts'])->where('topic', 'lineup')->first()['source_scope'])->toBe($correctIdentity ? 'event_reported_lineup' : 'projected_depth_chart');
    expect(in_array('game_starting_five_not_confirmed', array_column($r['unresolved'], 'reason_code'), true))->toBe(! $correctIdentity);
})->with([true, false]);

it('flags projected starters with verified availability conflicts without changing projection claims', function () {
    $r = app(GameContextEvidence::class)->build($this->game, $this->game->sportEvent->starts_at, [[
        'team_side' => 'home', 'espn_athlete_id' => (string) $this->players['home'][0]->espn_id, 'normalized_status' => 'reported_out',
        'raw_status' => 'Out', 'evidence_reference' => 'nba_player_injury_snapshot_entries:123',
    ]]);
    expect($r['concerns'])->toHaveCount(1)->and(array_column($r['unresolved'], 'reason_code'))->toContain('projected_lineup_availability_conflict');
});

it('round trips the additive context table without touching NFL schema', function () {
    $schema = Schema::getColumns('nfl_player_injuries');
    $migration = require database_path('migrations/2026_11_22_001000_create_nba_research_context_snapshots.php');
    $migration->down();
    $migration->up();
    expect(Schema::hasTable('nba_research_context_snapshots'))->toBeTrue()
        ->and(Schema::getColumns('nfl_player_injuries'))->toBe($schema);
});
