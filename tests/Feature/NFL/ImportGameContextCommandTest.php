<?php

use App\Models\NFL\Game;
use App\Models\NFL\GameContextFact;
use App\Models\NFL\Team;
use Carbon\CarbonImmutable;

use function Pest\Laravel\artisan;

beforeEach(function () {
    $this->home = Team::factory()->create(['abbreviation' => 'LAR']);
    $this->away = Team::factory()->create(['abbreviation' => 'SF']);
    $this->game = Game::factory()->create([
        'season' => 2025, 'season_type' => '2', 'week' => 5,
        'home_team_id' => $this->home->id, 'away_team_id' => $this->away->id,
        'game_date' => '2025-10-03', 'game_time' => '00:15:00',
    ]);
    $this->schedule = tempnam(sys_get_temp_dir(), 'context-schedule');
    $this->input = tempnam(sys_get_temp_dir(), 'context-input');
    file_put_contents($this->schedule, "season,game_type,week,gameday,home_team,away_team,home_coach,away_coach\n2025,REG,5,2025-10-02,LA,SF,Sean McVay,Kyle Shanahan\n");
    $this->contextOptions = ['--schedules' => $this->schedule, '--source-url' => 'https://example.com/archive.csv'];
});

afterEach(function () {
    unlink($this->schedule);
    unlink($this->input);
});

it('matches UTC games and team aliases, stores coaches without mutating the game, and is idempotent', function () {
    $before = $this->game->fresh()->getAttributes();
    $args = ['dataset' => 'schedules', 'file' => $this->schedule, '--source-url' => 'https://example.com/schedules.csv'];
    artisan('nfl:import-game-context', $args)->assertSuccessful();
    artisan('nfl:import-game-context', $args)->assertSuccessful();
    expect(GameContextFact::count())->toBe(2)
        ->and($this->game->fresh()->getAttributes())->toBe($before)
        ->and($this->game->contextFacts()->where('team_id', $this->away->id)->first()->subject)->toBe('Kyle Shanahan');
});

it('preserves questionable separately from participation and prevents historical lookahead', function () {
    file_put_contents($this->input, json_encode([[
        'season' => 2025, 'game_type' => 'REG', 'week' => 5, 'team' => 'SF',
        'kind' => 'injury_report', 'subject' => 'Brock Purdy', 'injury' => 'Toe',
        'designation' => 'Questionable', 'published_at' => '2025-10-01T20:50:00Z',
        'source_url' => 'https://example.com/report',
    ]]));
    artisan('nfl:import-game-context', ['dataset' => 'evidence', 'file' => $this->input, ...$this->contextOptions])->assertSuccessful();
    $fact = GameContextFact::first();
    expect($fact->designation)->toBe('Questionable')->and($fact->participation)->toBeNull()
        ->and(GameContextFact::knownAt(CarbonImmutable::parse('2025-10-02T23:00:00Z'))->count())->toBe(0);
    expect(GameContextFact::knownAt(now()->addSecond())->count())->toBe(1);
});

it('retains practice-only injuries without interpreting a blank designation as healthy or out', function () {
    file_put_contents($this->input, "season,game_type,week,team,full_name,gsis_id,position,report_status,report_primary_injury,practice_primary_injury,date_modified\n2025,REG,5,SF,Example Player,00-123,QB,,,Toe,2025-10-01T20:50:00Z\n");
    artisan('nfl:import-game-context', ['dataset' => 'injuries', 'file' => $this->input, ...$this->contextOptions])->assertSuccessful();
    expect(GameContextFact::first()->injury)->toBe('Toe')->and(GameContextFact::first()->designation)->toBeNull();
});

it('rejects ambiguous games and does not guess an injury link', function () {
    $copy = $this->game->replicate(['espn_event_id', 'espn_uid']);
    $copy->espn_event_id = 'duplicate';
    $copy->save();
    artisan('nfl:import-game-context', ['dataset' => 'schedules', 'file' => $this->schedule, ...$this->contextOptions])
        ->expectsOutputToContain('1 unmatched')->assertSuccessful();
    expect(GameContextFact::count())->toBe(0);
});

it('dry runs without writes and refuses evidence without attribution atomically', function () {
    artisan('nfl:import-game-context', ['dataset' => 'schedules', 'file' => $this->schedule, '--dry-run' => true, ...$this->contextOptions])->assertSuccessful();
    expect(GameContextFact::count())->toBe(0);
    $row = ['season' => 2025, 'game_type' => 'REG', 'week' => 5, 'team' => 'SF', 'kind' => 'injury_report', 'subject' => 'Brock Purdy'];
    file_put_contents($this->input, json_encode([['source_url' => 'https://example.com/report', ...$row], $row]));
    artisan('nfl:import-game-context', ['dataset' => 'evidence', 'file' => $this->input, ...$this->contextOptions])->assertFailed();
    expect(GameContextFact::count())->toBe(0);
});

it('uses the archive postseason round rather than ESPN postseason week numbering', function () {
    $this->game->update(['season_type' => '3', 'week' => 1, 'game_date' => '2026-01-11']);
    file_put_contents($this->schedule, "season,game_type,week,gameday,home_team,away_team,home_coach,away_coach\n2025,WC,19,2026-01-11,LA,SF,Sean McVay,Kyle Shanahan\n");
    file_put_contents($this->input, "season,game_type,week,team,full_name,gsis_id,position,report_status,report_primary_injury,practice_primary_injury,date_modified\n2025,WC,19,SF,Example Player,00-123,QB,Out,Toe,Toe,2026-01-09T20:50:00Z\n");
    artisan('nfl:import-game-context', ['dataset' => 'injuries', 'file' => $this->input, ...$this->contextOptions])->assertSuccessful();
    expect(GameContextFact::first()->game_id)->toBe($this->game->id);
});

it('does not duplicate unchanged facts when unrelated source HTML changes', function () {
    $row = ['season' => 2025, 'game_type' => 'REG', 'week' => 5, 'team' => 'SF', 'kind' => 'injury_report', 'subject' => 'Brock Purdy', 'designation' => 'Out', 'source_url' => 'https://www.nfl.com/news/report', 'source_sha256' => 'first-html-hash'];
    file_put_contents($this->input, json_encode([$row]));
    $args = ['dataset' => 'evidence', 'file' => $this->input, ...$this->contextOptions];
    artisan('nfl:import-game-context', $args)->assertSuccessful();
    $recordedAt = GameContextFact::first()->recorded_at;
    $row['source_sha256'] = 'changed-widget-hash';
    file_put_contents($this->input, json_encode([$row]));
    artisan('nfl:import-game-context', $args)->assertSuccessful();
    expect(GameContextFact::count())->toBe(1)->and(GameContextFact::first()->recorded_at->eq($recordedAt))->toBeTrue();
});
