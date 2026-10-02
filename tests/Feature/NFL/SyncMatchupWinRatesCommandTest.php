<?php

use App\Models\NFL\Game;
use App\Models\NFL\Team;
use App\Services\NFL\Matchups\NflMatchupWinRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function winRateHtml(int $count = 32): string
{
    $rows = '';
    for ($rank = 1; $rank <= $count; $rank++) {
        $value = 80 - intdiv($rank, 2);
        $rows .= '<tr><td>Team '.$rank.'</td>'.str_repeat('<td>'.$value.'% ('.$rank.')</td>', 4).'</tr>';
    }

    return '<html><h1>2026 NFL win rate rankings</h1><p>Last updated: Through Week 3 games, Sept. 29 at 10 a.m. ET</p><table><thead><tr><th>team</th><th>PRWR</th><th>RSWR</th><th>PBWR</th><th>RBWR</th></tr></thead><tbody>'.$rows.'</tbody></table></html>';
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Storage::fake('provider-local');
    config(['provider-data.storage.disk' => 'provider-local']);
    foreach (range(1, 32) as $rank) {
        Team::factory()->create(['location' => 'Team', 'name' => (string) $rank]);
    }
});

it('imports a dated complete snapshot retaining source ranks despite rounded percentages and remains idempotent', function () {
    Http::fake(['*' => Http::sequence()->push(winRateHtml())->push(winRateHtml())->push(str_replace('80% (1)', '81% (1)', winRateHtml()))]);
    $this->artisan('nfl:sync-matchup-win-rates --season=2026')->assertSuccessful();
    $first = DB::table('nfl_matchup_win_rate_snapshots')->first();
    $this->travel(1)->hours();
    $this->artisan('nfl:sync-matchup-win-rates --season=2026')->assertSuccessful();
    $snapshot = DB::table('nfl_matchup_win_rate_snapshots')->sole();
    $rows = array_values(json_decode($snapshot->teams, true));
    expect($snapshot->source_updated_at)->toBe('2026-09-29 14:00:00')
        ->and($snapshot->observed_at)->toBe($first->observed_at)
        ->and($snapshot->through_week)->toBe(3)
        ->and($rows[1]['PBWR']['value'])->toBe($rows[2]['PBWR']['value'])
        ->and($rows[1]['PBWR']['rank'])->toBe(2)
        ->and($rows[2]['PBWR']['rank'])->toBe(3);
    $this->artisan('nfl:sync-matchup-win-rates --season=2026')->assertSuccessful();
    expect(DB::table('nfl_matchup_win_rate_snapshots')->count())->toBe(2);
});

it('rejects malformed incomplete and wrong-period sources without partial writes', function (string $kind) {
    $html = match ($kind) {
        'missing' => winRateHtml(31),
        'duplicate' => str_replace('Team 32', 'Team 31', winRateHtml()),
        'unknown' => str_replace('Team 32', 'Unknown Team', winRateHtml()),
        'percentage' => str_replace('80% (1)', '101% (1)', winRateHtml()),
        'rank' => str_replace('80% (1)', '80% (2)', winRateHtml()),
        'season' => str_replace('2026 NFL', '2025 NFL', winRateHtml()),
        'future' => str_replace('Sept. 29', 'Oct. 2', winRateHtml()),
        'date' => str_replace('Sept. 29', 'Sept. 31', winRateHtml()),
        'ambiguous' => winRateHtml().winRateHtml(),
        default => '<html>Challenge</html>',
    };
    Http::fake(['*' => Http::response($html)]);
    $this->artisan('nfl:sync-matchup-win-rates --season=2026')->assertFailed();
    expect(DB::table('nfl_matchup_win_rate_snapshots')->count())->toBe(0);
})->with(['missing', 'duplicate', 'unknown', 'percentage', 'rank', 'season', 'future', 'date', 'ambiguous', 'challenge']);

it('does not fetch a different season or an unconfigured source', function () {
    $this->artisan('nfl:sync-matchup-win-rates --season=2025')->assertFailed();
    $this->artisan('nfl:sync-matchup-win-rates')->assertFailed();
    Http::assertNothingSent();
});

it('uses only a complete baseline snapshot observed before cutoff and excludes mismatched or stale periods', function () {
    Http::fake(['*' => Http::response(winRateHtml())]);
    $this->artisan('nfl:sync-matchup-win-rates --season=2026')->assertSuccessful();
    $teams = Team::query()->get();
    $games = collect();
    foreach ([1, 2, 3] as $week) {
        foreach (range(0, 15) as $index) {
            $game = Game::factory()->create(['season' => 2026, 'season_type' => '2', 'week' => $week, 'game_date' => '2026-09-'.(10 + $week),
                'status' => 'STATUS_FINAL', 'home_team_id' => $teams[$index]->id, 'away_team_id' => $teams[31 - $index]->id]);
            $games[$game->id] = $game;
        }
    }
    $this->travel(1)->hours();
    $service = app(NflMatchupWinRates::class);
    $cutoff = CarbonImmutable::parse('2026-10-04 17:00:00');
    $metrics = $service->metrics($games, $cutoff, 2026);
    expect($metrics['pass_block_win_rate']['offense'])->toHaveCount(32)
        ->and($metrics['pass_block_win_rate']['offense'][$teams[1]->id]['rank'])->toBe(2)
        ->and($metrics['pass_block_win_rate']['offense'][$teams[1]->id]['rank_end'])->toBe(2)
        ->and($metrics['pass_block_win_rate']['offense'][$teams[1]->id]['plays'])->toBeNull()
        ->and($service->metrics($games, CarbonImmutable::parse('2026-10-01 11:00:00', 'UTC'), 2026))->toBe([])
        ->and($service->metrics($games, $cutoff, 2025))->toBe([])
        ->and($service->metrics($games->take(32), $cutoff, 2026))->toBe([]);
    $late = Game::factory()->create(['season' => 2026, 'season_type' => '2', 'week' => 3, 'game_date' => '2026-10-01', 'status' => 'STATUS_SCHEDULED', 'home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    expect($service->metrics($games, $cutoff, 2026))->toBe([]);
    $late->delete();
    $this->travelTo(CarbonImmutable::parse('2026-10-20'));
    expect($service->metrics($games, CarbonImmutable::parse('2026-10-21'), 2026))->toBe([]);
});
