<?php

use App\Actions\ESPN\WCBB\SyncPlays as WcbbSyncPlays;
use App\Actions\ESPN\WNBA\SyncPlays as WnbaSyncPlays;
use App\DataTransferObjects\ESPN\BasketballPlayData;
use App\Services\Api\V2\SportContextResolver;
use App\Services\ESPN\WCBB\EspnService as WcbbEspnService;
use App\Services\ESPN\WNBA\EspnService as WnbaEspnService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['cache.default' => 'array', 'queue.default' => 'sync', 'session.driver' => 'array', 'mail.default' => 'array']);
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
});

function womenPlayFixture(string $sport): array
{
    $ns = strtoupper($sport);
    $team = 'App\\Models\\'.$ns.'\\Team';
    $game = 'App\\Models\\'.$ns.'\\Game';
    $play = 'App\\Models\\'.$ns.'\\Play';
    $home = $team::factory()->create(['espn_id' => '991001']);
    $away = $team::factory()->create(['espn_id' => '991002']);
    $game = $game::factory()->create(['espn_event_id' => $sport === 'wnba' ? '401990001' : '401990002', 'home_team_id' => $home->id, 'away_team_id' => $away->id, 'season' => 2026, 'game_date' => '2026-11-01', 'game_time' => '12:00:00', 'status' => 'STATUS_IN_PROGRESS', 'home_score' => 0, 'away_score' => 0]);

    return [$game, $home, $away, $play];
}
function womenPlayRaw(string $id, string $team, array $extra = []): array
{
    return array_replace_recursive(['id' => $id, 'type' => ['id' => '99', 'text' => 'Observed play'], 'text' => 'Synthetic same common fields', 'period' => ['number' => 1], 'clock' => ['displayValue' => '09:59'], 'scoreValue' => 0, 'shootingPlay' => false, 'scoringPlay' => false, 'homeScore' => 0, 'awayScore' => 0, 'team' => ['id' => $team]], $extra);
}
function womenPlayAction(string $sport, object $game, array $rows): object
{
    $service = Mockery::mock($sport === 'wnba' ? WnbaEspnService::class : WcbbEspnService::class);
    $service->shouldReceive('getGame')->once()->with($game->espn_event_id)->andReturn(['competitions' => [['id' => $game->espn_event_id]]]);
    $service->shouldReceive('getPlays')->once()->with($game->espn_event_id, $game->espn_event_id)->andReturn(['count' => count($rows), 'items' => $rows]);

    // For WCBB this explicitly injects a synthetic provider; default acquisition remains disabled.
    return $sport === 'wnba' ? new WnbaSyncPlays($service) : new WcbbSyncPlays($service);
}
function womenPlayRows(string $sport, object $game): array
{
    return DB::table($sport.'_plays')->where('game_id', $game->id)->orderBy('sequence_number')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
}
function womenPlayTrace(string $case, string $sport, object $game, array $input, array $extra = []): void
{
    $dir = getenv('PICKSPORTS_WOMEN_PLAYS_DIAGNOSTIC_DIR');
    if (! $dir) {
        return;
    }
    if (! is_dir($dir) || is_link($dir) || ! str_starts_with((string) realpath($dir), '/private/tmp/') || (fileperms($dir) & 0077)) {
        throw new RuntimeException('Private diagnostic fixture directory required.');
    }
    $rows = womenPlayRows($sport, $game);
    if (count($rows) > 64) {
        throw new RuntimeException('Diagnostic row bound.');
    }
    $record = ['case' => $case, 'sport' => $sport, 'input' => $input, 'dto' => array_map(fn ($r, $i) => BasketballPlayData::fromEspnResponse($r, $i)->toArray(), $input, array_keys($input)), 'persisted_rows' => $rows, ...$extra];
    $f = fopen($dir.'/'.$case.'-'.$sport.'.json', 'xb');
    if (! $f) {
        throw new RuntimeException('Diagnostic overwrite refused.');
    }chmod($dir.'/'.$case.'-'.$sport.'.json', 0600);
    fwrite($f, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fclose($f);
}
function womenPlayHttp(string $sport, string $event, array $plays): void
{
    $league = $sport === 'wnba' ? 'wnba' : 'womens-college-basketball';
    Http::fake(function ($request) use ($league, $event, $plays) {
        $url = $request->url();
        if ($url === 'https://site.api.espn.com/apis/site/v2/sports/basketball/'.$league.'/summary?event='.$event) {
            return Http::response(['header' => ['competitions' => [['id' => $event]]]]);
        }
        if ($url === 'https://sports.core.api.espn.com/v2/sports/basketball/leagues/'.$league.'/events/'.$event.'/competitions/'.$event.'/plays?limit=300') {
            return Http::response(['count' => count($plays), 'items' => $plays]);
        }
        throw new RuntimeException('Unexpected synthetic ESPN endpoint; no network fallback.');
    });
}
function womenPlayRichBatch(object $home, object $away): array
{
    return [womenPlayRaw('observed-shot', (string) $home->espn_id, ['type' => ['id' => '1', 'text' => 'Made shot with assist'], 'shootingPlay' => true, 'scoringPlay' => true, 'scoreValue' => 2, 'homeScore' => 2]), womenPlayRaw('observed-turnover', (string) $away->espn_id, ['type' => ['id' => '10', 'text' => 'Turnover'], 'homeScore' => 2]), womenPlayRaw('observed-foul', (string) $home->espn_id, ['type' => ['id' => '6', 'text' => 'Foul'], 'homeScore' => 2])];
}
it('persists observed WNBA play identities and all basketball flags through the real CLI sync job', function () {
    [$game,$home,$away,$model] = womenPlayFixture('wnba');
    $input = womenPlayRichBatch($home, $away);
    womenPlayHttp('wnba', $game->espn_event_id, $input);
    $code = Artisan::call('espn:sync-wnba-plays', ['eventId' => $game->espn_event_id]);
    $resource = app(SportContextResolver::class)->resolve('wnba')->resources['play'];
    $stored = $model::where('game_id', $game->id)->orderBy('sequence_number')->get();
    $payloads = $stored->map(fn ($row) => (new $resource($row))->resolve(Request::create('/synthetic-resource-contract')))->all();
    womenPlayTrace('active-cli', 'wnba', $game, $input, ['exit_code' => $code, 'registered_resource_payloads' => $payloads]);
    expect($code)->toBe(0);
    Http::assertSentCount(2);
    $rows = womenPlayRows('wnba', $game);
    expect(array_column($rows, 'espn_play_id'))->toBe(['observed-shot', 'observed-turnover', 'observed-foul']);
    expect((int) $rows[0]['shooting_play'])->toBe(1)->and((int) $rows[0]['made_shot'])->toBe(1)->and((int) $rows[0]['assist'])->toBe(1)->and((int) $rows[1]['is_turnover'])->toBe(1)->and((int) $rows[2]['is_foul'])->toBe(1);
    expect($model::where('game_id', $game->id)->firstOrFail()->shooting_play)->toBeTrue();
    expect($payloads[0]['espn_id'])->toBe('observed-shot')->and($payloads[0]['scoring_play'])->toBeTrue();
});
it('refreshes WNBA changed observed identity and missed-shot flags when common fields are unchanged', function () {
    [$game,$home] = womenPlayFixture('wnba');
    $first = [womenPlayRaw('before-id', (string) $home->espn_id)];
    $second = [womenPlayRaw('after-id', (string) $home->espn_id, ['shootingPlay' => true])];
    womenPlayAction('wnba', $game, $first)->execute($game->espn_event_id);
    $before = womenPlayRows('wnba', $game);
    $count = womenPlayAction('wnba', $game, $second)->execute($game->espn_event_id);
    womenPlayTrace('changed-observation', 'wnba', $game, $second, ['before' => $before, 'count' => $count]);
    $rows = womenPlayRows('wnba', $game);
    expect($count)->toBe(1)->and($rows[0]['espn_play_id'])->toBe('after-id')->and((int) $rows[0]['shooting_play'])->toBe(1)->and((int) $rows[0]['made_shot'])->toBe(0);
});
it('preserves observed zero scores and false WNBA flags as stored values', function () {
    [$game,$home] = womenPlayFixture('wnba');
    $input = [womenPlayRaw('zero-false', (string) $home->espn_id)];
    $count = womenPlayAction('wnba', $game, $input)->execute($game->espn_event_id);
    womenPlayTrace('zero-false', 'wnba', $game, $input, ['count' => $count]);
    $rows = womenPlayRows('wnba', $game);
    expect($count)->toBe(1)->and($rows)->toHaveCount(1)->and($rows[0]['score_value'])->toBe(0)->and($rows[0]['home_score'])->toBe(0)->and($rows[0]['away_score'])->toBe(0);
    foreach (['shooting_play', 'made_shot', 'assist', 'is_turnover', 'is_foul'] as $field) {
        expect((int) $rows[0][$field])->toBe(0);
    }
});
it('keeps the complete stored WNBA row unchanged on a same-feed replay', function () {
    [$game,$home] = womenPlayFixture('wnba');
    $input = [womenPlayRaw('same-feed', (string) $home->espn_id)];
    womenPlayAction('wnba', $game, $input)->execute($game->espn_event_id);
    $before = womenPlayRows('wnba', $game);
    $count = womenPlayAction('wnba', $game, $input)->execute($game->espn_event_id);
    womenPlayTrace('replay', 'wnba', $game, $input, ['before' => $before, 'count' => $count]);
    expect($count)->toBe(1)->and(womenPlayRows('wnba', $game))->toBe($before);
});
it('rolls back the entire WNBA replacement when the second play save throws', function () {
    [$game,$home] = womenPlayFixture('wnba');
    $first = [womenPlayRaw('original', (string) $home->espn_id)];
    womenPlayAction('wnba', $game, $first)->execute($game->espn_event_id);
    $before = womenPlayRows('wnba', $game);
    $next = [womenPlayRaw('new-one', (string) $home->espn_id, ['text' => 'Replacement first']), womenPlayRaw('new-two', (string) $home->espn_id, ['text' => 'Synthetic second write failure'])];
    $original = Model::getEventDispatcher();
    $scoped = clone $original;
    Model::setEventDispatcher($scoped);
    $scoped->listen('eloquent.creating: App\\Models\\WNBA\\Play', function ($play) {
        if ($play->play_text === 'Synthetic second write failure') {
            throw new RuntimeException('Synthetic second play save refused.');
        }
    });
    $error = null;
    try {
        womenPlayAction('wnba', $game, $next)->execute($game->espn_event_id);
    } catch (RuntimeException $e) {
        $error = $e;
    } finally {
        Model::setEventDispatcher($original);
    }
    womenPlayTrace('replacement-rollback', 'wnba', $game, $next, ['before' => $before, 'error_class' => $error ? get_class($error) : null, 'error_message' => $error?->getMessage()]);
    expect($error)->toBeInstanceOf(RuntimeException::class)->and($error->getMessage())->toBe('Synthetic second play save refused.')->and(womenPlayRows('wnba', $game))->toBe($before);
});
it('keeps default WCBB play acquisition disabled without replacing existing rows', function () {
    [$game,$home] = womenPlayFixture('wcbb');
    DB::table('wcbb_plays')->insert(['game_id' => $game->id, 'possession_team_id' => $home->id, 'espn_play_id' => 'retained-observed', 'sequence_number' => 1, 'period' => 1, 'clock' => '09:59', 'play_text' => 'Retained original', 'home_score' => 0, 'away_score' => 0]);
    $before = womenPlayRows('wcbb', $game);
    womenPlayHttp('wcbb', $game->espn_event_id, []);
    $count = (new WcbbSyncPlays(new WcbbEspnService))->execute($game->espn_event_id);
    womenPlayTrace('disabled-default', 'wcbb', $game, [], ['before' => $before, 'count' => $count]);
    expect($count)->toBe(0)->and(womenPlayRows('wcbb', $game))->toBe($before);
    Http::assertSentCount(1);
});
it('preserves WCBB writer compatibility with an explicitly injected synthetic provider only', function () {
    [$game,$home,$away] = womenPlayFixture('wcbb');
    $input = womenPlayRichBatch($home, $away);
    $count = womenPlayAction('wcbb', $game, $input)->execute($game->espn_event_id);
    womenPlayTrace('injected-compatibility', 'wcbb', $game, $input, ['count' => $count]);
    $rows = womenPlayRows('wcbb', $game);
    expect($count)->toBe(3)->and(array_column($rows, 'espn_play_id'))->toBe(['observed-shot', 'observed-turnover', 'observed-foul'])->and((int) $rows[0]['made_shot'])->toBe(1)->and((int) $rows[1]['is_turnover'])->toBe(1)->and((int) $rows[2]['is_foul'])->toBe(1);
});
it('keeps existing women basketball resource wire aliases bound to actual stored columns', function (string $sport) {
    [$game,$home,,$model] = womenPlayFixture($sport);
    $id = DB::table($sport.'_plays')->insertGetId(['game_id' => $game->id, 'possession_team_id' => $home->id, 'espn_play_id' => 'persisted-reader-id', 'sequence_number' => 1, 'period' => 1, 'clock' => '09:59', 'play_text' => 'Explicit stored reader fixture', 'score_value' => 2, 'shooting_play' => 1, 'made_shot' => 1, 'assist' => 1, 'is_turnover' => 0, 'is_foul' => 0, 'home_score' => 2, 'away_score' => 0]);
    // This SQL fixture isolates serialization; it is NOT a provider-writer qualification.
    $row = $model::findOrFail($id)->load('possessionTeam');
    $resource = app(SportContextResolver::class)->resolve($sport)->resources['play'];
    $payload = (new $resource($row))->resolve(Request::create('/synthetic-resource-contract'));
    womenPlayTrace('resource-only', $sport, $game, [], ['resource_payload' => $payload, 'reader_fixture_only' => true]);
    expect($payload)->toHaveKeys(['espn_id', 'scoring_play'])->and($payload['espn_id'])->toBe('persisted-reader-id')->and($payload['scoring_play'])->toBeTrue()->and($payload)->not->toHaveKey('espn_play_id');
})->with(['wnba', 'wcbb']);

it('refreshes same-ID WNBA observed flags from true to false without changing common fields', function () {
    [$game,$home] = womenPlayFixture('wnba');
    $first = [womenPlayRaw('same-observed-id', (string) $home->espn_id, ['shootingPlay' => true, 'scoringPlay' => true])];
    $second = [womenPlayRaw('same-observed-id', (string) $home->espn_id, ['shootingPlay' => false, 'scoringPlay' => false])];
    // A valid retained observed row isolates refresh from initial-writer admission.
    DB::table('wnba_plays')->insert([...BasketballPlayData::fromEspnResponse($first[0], 0)->toArray(), 'game_id' => $game->id, 'possession_team_id' => $home->id]);
    $before = womenPlayRows('wnba', $game);
    $count = womenPlayAction('wnba', $game, $second)->execute($game->espn_event_id);
    $after = womenPlayRows('wnba', $game);
    womenPlayTrace('same-id-true-false', 'wnba', $game, $second, ['before' => $before, 'count' => $count]);
    expect((int) $before[0]['shooting_play'])->toBe(1)->and((int) $before[0]['made_shot'])->toBe(1);
    expect($count)->toBe(1)->and($after[0]['espn_play_id'])->toBe('same-observed-id')->and((int) $after[0]['shooting_play'])->toBe(0)->and((int) $after[0]['made_shot'])->toBe(0);
});
it('preserves a stored null women basketball play identity in existing resource aliases', function (string $sport) {
    [$game,$home,,$model] = womenPlayFixture($sport);
    $id = DB::table($sport.'_plays')->insertGetId(['game_id' => $game->id, 'possession_team_id' => $home->id, 'espn_play_id' => null, 'sequence_number' => 1, 'period' => 1, 'clock' => '09:59', 'play_text' => 'Retained null identity', 'shooting_play' => 0, 'made_shot' => 0, 'assist' => 0, 'is_turnover' => 0, 'is_foul' => 0, 'score_value' => 0, 'home_score' => 0, 'away_score' => 0]);
    $row = $model::findOrFail($id);
    $resource = app(SportContextResolver::class)->resolve($sport)->resources['play'];
    $payload = (new $resource($row))->resolve(Request::create('/synthetic-resource-contract'));
    womenPlayTrace('resource-null', $sport, $game, [], ['resource_payload' => $payload, 'reader_fixture_only' => true]);
    expect($payload)->toHaveKeys(['espn_id', 'scoring_play'])->and($payload['espn_id'])->toBeNull()->and($payload['scoring_play'])->toBeFalse()->and($payload)->not->toHaveKey('espn_play_id');
})->with(['wnba', 'wcbb']);
