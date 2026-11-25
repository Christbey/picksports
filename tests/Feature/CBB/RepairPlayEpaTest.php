<?php

use App\Actions\ESPN\CBB\SyncPlays;
use App\DataTransferObjects\ESPN\CbbPlayData;
use App\Models\CBB\Game;
use App\Models\CBB\Play;
use App\Models\CBB\Team;
use App\Models\CBB\TeamMetric;
use App\Models\CBB\TeamStat;
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
        TeamStat::factory()->create(['game_id' => $this->game->id, 'team_id' => $team->id]);
        TeamMetric::factory()->create(['team_id' => $team->id, 'season' => 2027, 'net_true_epa_per_play' => null]);
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
