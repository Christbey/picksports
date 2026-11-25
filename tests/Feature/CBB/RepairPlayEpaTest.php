<?php

use App\Actions\CBB\CalculateTeamMetrics;
use App\Actions\CBB\RefreshTeamPlayEpa;
use App\Actions\ESPN\CBB\SyncPlays;
use App\DataTransferObjects\ESPN\CbbPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
use App\Services\CBB\PlayScoreReconciler;
use Illuminate\Support\Facades\Http;

function cbbRepairPlay(string $id, string $team, int $home, int $away): array
{
    return ['id' => $id, 'team' => ['$ref' => "http://sports.core.api.espn.com/v2/sports/basketball/leagues/mens-college-basketball/seasons/2027/teams/{$team}?lang=en"], 'type' => ['text' => 'JumpShot'], 'shootingPlay' => true, 'scoringPlay' => true, 'scoreValue' => 2, 'text' => 'Made jump shot', 'homeScore' => $home, 'awayScore' => $away, 'period' => ['number' => 2], 'clock' => ['displayValue' => '1:00']];
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->home = Team::factory()->create(['espn_id' => '130']);
    $this->away = Team::factory()->create(['espn_id' => '41']);
    $this->game = Game::factory()->create(['espn_event_id' => 'repair-event', 'season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'home_score' => 2, 'away_score' => 2]);
    $this->oldPlay = Play::factory()->create(['game_id' => $this->game->id, 'possession_team_id' => null, 'true_epa' => null]);
    foreach ([$this->home, $this->away] as $team) {
        TeamStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $team->id, 'points' => 2, 'field_goals_made' => 1, 'field_goals_attempted' => 3, 'three_point_made' => 0, 'three_point_attempted' => 0, 'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
        TeamMetric::factory()->create(['team_id' => $team->id, 'season' => 2027, 'games_played' => 1, 'net_true_epa_per_play' => null]);
    }
});

function fakeCbbRepairFeed(bool $failSecond = false, bool $badFinal = false): void
{
    Http::fake(function ($request) use ($failSecond, $badFinal) {
        if (str_contains($request->url(), '/summary')) {
            return Http::response(['header' => ['competitions' => [['id' => 'repair-event']]]]);
        }
        $second = str_contains($request->url(), '&page=2');
        if ($second && $failSecond) {
            return Http::response([], 503);
        }

        return Http::response(['count' => 2, 'pageCount' => 2, 'pageIndex' => $second ? 2 : 1, 'items' => [$second ? cbbRepairPlay('p2', '41', 2, $badFinal ? 0 : 2) : cbbRepairPlay('p1', '130', 2, 0)]]);
    });
}

test('repair fetches every page, attributes offense, calculates EPA and updates only team EPA', function () {
    fakeCbbRepairFeed();
    $before = TeamMetric::query()->first()->offensive_efficiency;
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--limit' => 1, '--apply' => true])->assertSuccessful();
    expect(Play::query()->count())->toBe(2)
        ->and(Play::query()->whereNotNull('true_epa')->count())->toBe(2)
        ->and(Play::query()->where('espn_play_id', 'p1')->value('possession_team_id'))->toBe($this->home->id)
        ->and(Play::query()->where('espn_play_id', 'p2')->value('possession_team_id'))->toBe($this->away->id)
        ->and(TeamMetric::query()->whereNotNull('net_true_epa_per_play')->count())->toBe(2)
        ->and(TeamMetric::query()->first()->offensive_efficiency)->toBe($before);
    Http::assertSentCount(3);
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--apply' => true])->assertSuccessful();
    Http::assertSentCount(3);
});

test('preview neither fetches providers nor writes plays', function () {
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027])->assertSuccessful();
    Http::assertNothingSent();
    expect($this->oldPlay->fresh())->not->toBeNull();
});

test('failed pagination preserves existing plays', function () {
    fakeCbbRepairFeed(failSecond: true);
    expect(fn () => app(SyncPlays::class)->execute('repair-event'))->toThrow(RuntimeException::class);
    expect($this->oldPlay->fresh())->not->toBeNull();
});

test('a feed that misses the final score preserves existing plays', function () {
    fakeCbbRepairFeed(badFinal: true);
    expect(fn () => app(SyncPlays::class)->execute('repair-event'))->toThrow(RuntimeException::class);
    expect($this->oldPlay->fresh())->not->toBeNull();
});

test('defensive actors and administrative events are not assigned offensive possession', function (string $type) {
    $raw = cbbRepairPlay('p1', '130', 2, 0);
    $raw['shootingPlay'] = false;
    $raw['type']['text'] = $type;
    expect(CbbPlayData::fromEspnResponse($raw, 0)->possessionTeamEspnId)->toBeNull();
})->with(['Defensive Rebound', 'Steal', 'Block Shot', 'PersonalFoul', 'Substitution', 'Jumpball']);

test('identical CBB refresh preserves calculated EPA and play identities', function () {
    fakeCbbRepairFeed();
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--limit' => 1, '--apply' => true])->assertSuccessful();
    $before = Play::query()->orderBy('id')->get(['id', 'true_epa', 'epa_calculated_at'])->toArray();
    app(SyncPlays::class)->execute('repair-event');
    expect(Play::query()->orderBy('id')->get(['id', 'true_epa', 'epa_calculated_at'])->toArray())->toBe($before);
});

test('team EPA remains unavailable until every complete game in its denominator has EPA', function () {
    fakeCbbRepairFeed();
    $extra = Game::factory()->create(['season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'home_score' => 2, 'away_score' => 2]);
    foreach ([$this->home, $this->away] as $team) {
        TeamStat::factory()->create(['game_id' => $extra->id, 'team_id' => $team->id, 'points' => 2, 'field_goals_made' => 1, 'field_goals_attempted' => 3, 'three_point_made' => 0, 'three_point_attempted' => 0, 'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
    }
    TeamMetric::query()->update(['games_played' => 2]);
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--game-id' => $this->game->id, '--apply' => true])->assertSuccessful();
    expect(Play::query()->whereNotNull('true_epa')->count())->toBe(2)
        ->and(TeamMetric::query()->whereNotNull('net_true_epa_per_play')->count())->toBe(0);
});

test('repair can recover a completed box-scored game with no stored plays', function () {
    fakeCbbRepairFeed();
    $this->oldPlay->delete();
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--limit' => 1, '--apply' => true])->assertSuccessful();
    expect(Play::query()->whereNotNull('true_epa')->count())->toBe(2);
});

test('an exact recovery allowlist excludes other newly eligible games', function () {
    fakeCbbRepairFeed();
    $extra = Game::factory()->create(['season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'home_score' => 2, 'away_score' => 2]);
    foreach ([$this->home, $this->away] as $team) {
        TeamStat::factory()->create(['game_id' => $extra->id, 'team_id' => $team->id, 'points' => 2, 'field_goals_made' => 1, 'field_goals_attempted' => 3, 'three_point_made' => 0, 'three_point_attempted' => 0, 'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
    }
    $this->artisan('cbb:repair-play-epa', ['--season' => 2027, '--limit' => 1, '--game-ids' => (string) $this->game->id, '--apply' => true])->assertSuccessful();
    expect(Play::query()->where('game_id', $extra->id)->count())->toBe(0)
        ->and(Play::query()->where('game_id', $this->game->id)->whereNotNull('true_epa')->count())->toBe(2);
    Http::assertSentCount(3);
});

test('normal CBB metrics and EPA refresh use the same reconciled sample', function () {
    fakeCbbRepairFeed();
    app(SyncPlays::class)->execute('repair-event');
    $bad = Game::factory()->create(['season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'home_score' => 90, 'away_score' => 80]);
    foreach ([$this->home, $this->away] as $team) {
        TeamStat::factory()->create(['game_id' => $bad->id, 'team_id' => $team->id, 'points' => 2]);
    }
    $metric = app(CalculateTeamMetrics::class)->execute($this->home, 2027);
    expect($metric->games_played)->toBe(1)->and($metric->net_true_epa_per_play)->not->toBeNull();
    expect(app(RefreshTeamPlayEpa::class)->execute($this->home, 2027))->toBeTrue();
    expect($metric->fresh()->net_true_epa_per_play)->not->toBeNull();
});

test('normal CBB metric generation cannot publish EPA from a partial game sample', function () {
    fakeCbbRepairFeed();
    app(SyncPlays::class)->execute('repair-event');
    $extra = Game::factory()->create(['season' => 2027, 'season_type' => 2, 'status' => 'STATUS_FINAL', 'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id, 'home_score' => 2, 'away_score' => 2]);
    foreach ([$this->home, $this->away] as $team) {
        TeamStat::factory()->create(['game_id' => $extra->id, 'team_id' => $team->id, 'points' => 2, 'field_goals_made' => 1, 'field_goals_attempted' => 3, 'three_point_made' => 0, 'three_point_attempted' => 0, 'free_throws_made' => 0, 'free_throws_attempted' => 0, 'possessions' => 10]);
    }
    $metric = app(CalculateTeamMetrics::class)->execute($this->home, 2027);
    expect($metric->games_played)->toBe(2)->and($metric->net_true_epa_per_play)->toBeNull();
    Play::factory()->create(['game_id' => $extra->id, 'possession_team_id' => $this->home->id, 'true_epa' => 0.2]);
    $metric = app(CalculateTeamMetrics::class)->execute($this->home, 2027);
    expect($metric->net_true_epa_per_play)->toBeNull();
    Play::factory()->create(['game_id' => $extra->id, 'possession_team_id' => $this->away->id, 'true_epa' => -0.1]);
    $metric = app(CalculateTeamMetrics::class)->execute($this->home, 2027);
    expect($metric->net_true_epa_per_play)->not->toBeNull();
});

test('final CBB play ingestion scores derived EPA before returning', function () {
    fakeCbbRepairFeed();
    app(SyncPlays::class)->execute('repair-event');
    expect(Play::query()->whereNotNull('true_epa')->count())->toBe(2)
        ->and(Play::query()->whereNull('epa_calculated_at')->count())->toBe(0);
});

test('CBB can reconcile an erroneous scoreboard only from a complete explicit scoring sequence', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/summary')) {
            return Http::response(['header' => ['competitions' => [['id' => 'repair-event']]]]);
        }
        $start = ['id' => 'start', 'type' => ['text' => 'Jumpball'], 'text' => 'Start game', 'homeScore' => 0, 'awayScore' => 0, 'period' => ['number' => 1], 'clock' => ['displayValue' => '20:00']];
        $home = cbbRepairPlay('p1', '130', 0, 2);
        $away = cbbRepairPlay('p2', '41', 3, 2);
        $end = ['id' => 'end', 'type' => ['text' => 'End Game'], 'text' => 'End of Game', 'homeScore' => 3, 'awayScore' => 2, 'period' => ['number' => 2], 'clock' => ['displayValue' => '0:00']];

        return Http::response(['count' => 4, 'pageCount' => 1, 'items' => [$start, $home, $away, $end]]);
    });
    app(SyncPlays::class)->execute('repair-event');
    expect(Play::query()->where('espn_play_id', 'p1')->first())->home_score->toBe(2)->away_score->toBe(0);
    expect(Play::query()->where('espn_play_id', 'end')->first())->home_score->toBe(2)->away_score->toBe(2);
    expect(Play::query()->whereNotNull('true_epa')->count())->toBe(2);
});

test('score reconstruction rejects missing scoring points and unknown scorers', function () {
    $game = $this->game->load(['homeTeam', 'awayTeam']);
    $start = ['id' => 'start', 'period' => ['number' => 1], 'clock' => ['displayValue' => '20:00'], 'homeScore' => 0, 'awayScore' => 0];
    $end = ['id' => 'end', 'period' => ['number' => 2], 'clock' => ['displayValue' => '0:00'], 'homeScore' => 1, 'awayScore' => 1];
    $plays = [$start, cbbRepairPlay('p1', '130', 1, 0), $end];
    expect(app(PlayScoreReconciler::class)->reconcile($plays, $game))->toBeNull();
    $plays = [$start, cbbRepairPlay('p1', '130', 1, 0), cbbRepairPlay('p2', '999999', 1, 1), $end];
    expect(app(PlayScoreReconciler::class)->reconcile($plays, $game))->toBeNull();
});
