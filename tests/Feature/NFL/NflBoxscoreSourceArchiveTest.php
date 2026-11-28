<?php

use App\Actions\ESPN\NFL\SyncGameDetails;
use App\Actions\ESPN\NFL\SyncPlayerStats;
use App\Actions\ESPN\NFL\SyncPlays;
use App\Actions\ESPN\NFL\SyncTeamStats;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Models\ProviderSourceFile;
use App\Services\ESPN\NFL\EspnService;
use App\Services\NFL\NflBoxscoreSourceArchive;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    $this->game = Game::factory()->create(['season' => 2026, 'season_type' => 2, 'espn_event_id' => '401000001',
        'home_team_id' => Team::factory()->create(['espn_id' => '10'])->id,
        'away_team_id' => Team::factory()->create(['espn_id' => '20'])->id]);
    $this->summary = ['header' => ['id' => '401000001', 'season' => ['year' => 2026],
        'competitions' => [['status' => ['type' => ['completed' => true]]]]],
        'boxscore' => ['players' => [
            ['team' => ['id' => '10'], 'statistics' => [['name' => 'receiving', 'labels' => ['REC', 'YDS', 'TD', 'TGTS'],
                'athletes' => [['athlete' => ['id' => '100'], 'stats' => ['0', '0', '0', '1']]]]]],
            ['team' => ['id' => '20'], 'statistics' => [['name' => 'rushing', 'labels' => ['CAR', 'YDS'],
                'athletes' => [['athlete' => ['id' => '200'], 'stats' => ['2', '7']]]]]],
        ]], 'plays' => [['unneeded' => 'large']], 'news' => ['unneeded']];
});

it('preserves explicit zeros and absent categories without creating player stat observations', function () {
    $archive = app(NflBoxscoreSourceArchive::class);
    $source = $archive->record($this->game, $this->summary);
    $restored = $archive->read($source, $this->game);
    expect($restored['boxscore'])->toBe($this->summary['boxscore'])
        ->and($restored)->not->toHaveKeys(['plays', 'news'])
        ->and($restored['boxscore']['players'][1]['statistics'])->toHaveCount(1)
        ->and(PlayerStat::count())->toBe(0)
        ->and($source->metadata['purpose'])->toBe('postgame_actual_evidence');
});

it('deduplicates repeated unchanged final box scores despite observation time changes', function () {
    $archive = app(NflBoxscoreSourceArchive::class);
    $first = $archive->record($this->game, $this->summary);
    $this->travel(1)->hours();
    $second = $archive->record($this->game, $this->summary);
    expect($first->id)->toBe($second->id)->and(ProviderSourceFile::count())->toBe(1);
});

it('rejects wrong event season or team identity before archiving', function () {
    $archive = app(NflBoxscoreSourceArchive::class);
    foreach (['header.id' => 'wrong', 'header.season.year' => 2025, 'boxscore.players.0.team.id' => '30'] as $path => $value) {
        $bad = $this->summary;
        data_set($bad, $path, $value);
        expect(fn () => $archive->record($this->game, $bad))->toThrow(RuntimeException::class);
    }
    expect(ProviderSourceFile::count())->toBe(0);
});

it('refuses corrupted objects and cross-game replay', function () {
    $archive = app(NflBoxscoreSourceArchive::class);
    $source = $archive->record($this->game, $this->summary);
    $other = $this->game->replicate();
    $other->espn_event_id = 'different';
    expect(fn () => $archive->read($source, $other))->toThrow(RuntimeException::class);
    Storage::disk($source->disk)->put($source->object_key, 'corrupt');
    expect(fn () => $archive->read($source, $this->game))->toThrow(RuntimeException::class, 'checksum');
});

it('does not archive live summaries or manufacture a missing final box score', function () {
    $archive = app(NflBoxscoreSourceArchive::class);
    $live = $this->summary;
    data_set($live, 'header.competitions.0.status.type.completed', false);
    expect($archive->record($this->game, $live))->toBeNull();
    unset($this->summary['boxscore']);
    expect($archive->record($this->game, $this->summary))->toBeNull()->and(ProviderSourceFile::count())->toBe(0);
});

it('rejects ambiguous completion evidence on an already final game', function () {
    $this->game->update(['status' => 'STATUS_FINAL']);
    unset($this->summary['header']['competitions'][0]['status']['type']['completed']);
    expect(fn () => app(NflBoxscoreSourceArchive::class)->record($this->game, $this->summary))
        ->toThrow(RuntimeException::class, 'confirmed completion');
    expect(ProviderSourceFile::count())->toBe(0);
});

it('stops the NFL import before stat replacement when source retention fails', function () {
    $archive = Mockery::mock(NflBoxscoreSourceArchive::class);
    $archive->shouldReceive('record')->once()->andThrow(new RuntimeException('Archive unavailable'));
    app()->instance(NflBoxscoreSourceArchive::class, $archive);
    $service = Mockery::mock(EspnService::class);
    $service->shouldReceive('getGame')->once()->andReturn($this->summary);
    $players = Mockery::mock(SyncPlayerStats::class);
    $players->shouldNotReceive('execute');
    $teams = Mockery::mock(SyncTeamStats::class);
    $teams->shouldNotReceive('execute');
    $plays = Mockery::mock(SyncPlays::class);
    $plays->shouldNotReceive('execute');
    $before = $this->game->fresh()->getAttributes();
    $action = new SyncGameDetails($service, $players, $teams, $plays);
    expect(fn () => $action->execute('401000001'))->toThrow(RuntimeException::class, 'Archive unavailable');
    expect($this->game->fresh()->getAttributes())->toBe($before);
});

it('refuses allocation-heavy archived JSON before decoding despite valid object checksums', function (string $kind) {
    $archive = app(NflBoxscoreSourceArchive::class);
    $source = $archive->record($this->game, $this->summary);
    $payload = $archive->read($source, $this->game);
    $padding = $kind === 'tokens' ? '['.str_repeat('0,', 500100).'0]' : str_repeat('[', 80).'0'.str_repeat(']', 80);
    $raw = substr(json_encode($payload, JSON_THROW_ON_ERROR), 0, -1).',"padding":'.$padding.'}';
    expect(strlen($raw))->toBeLessThan(8 * 1024 * 1024);
    $compressed = gzencode($raw, 6);
    Storage::disk($source->disk)->put($source->object_key, $compressed);
    $source->size_bytes = strlen($compressed);
    $source->sha256 = hash('sha256', $compressed);
    $source->metadata = array_merge($source->metadata, ['json_sha256' => hash('sha256', $raw)]);

    expect(fn () => $archive->read($source, $this->game))->toThrow(RuntimeException::class, 'budget');
})->with(['tokens', 'depth']);

it('refuses allocation-heavy provider payloads before archive persistence', function (string $kind) {
    $this->summary['boxscore']['padding'] = $kind === 'tokens' ? array_fill(0, 500100, 0) : array_reduce(range(1, 80), fn ($value) => [$value], 0);
    expect(fn () => app(NflBoxscoreSourceArchive::class)->record($this->game, $this->summary))
        ->toThrow(RuntimeException::class, 'budget');
    expect(ProviderSourceFile::count())->toBe(0);
})->with(['tokens', 'depth']);

it('retains a valid dense archive and the exact admitted structural depth', function (string $kind) {
    $this->summary['boxscore']['padding'] = $kind === 'tokens' ? array_fill(0, 499000, 0) : array_reduce(range(1, 62), fn ($value) => [$value], 0);
    $archive = app(NflBoxscoreSourceArchive::class);
    $source = $archive->record($this->game, $this->summary);
    expect($archive->read($source, $this->game)['boxscore'])->toBe($this->summary['boxscore']);
})->with(['tokens', 'depth']);
