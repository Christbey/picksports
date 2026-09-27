<?php

use App\Models\CFB\Game;
use App\Models\CFB\Team;
use App\Models\GameOddsSnapshot;
use App\Models\MarketQuote;
use App\Models\ProviderSourceFile;
use App\Services\CFB\CfbHistoricalSpreadImporter;
use App\Services\CFB\Predictions\CfbStoredPregameQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

function historicalSpreadFixture(): array
{
    $home = Team::factory()->create(['espn_id' => '2305']);
    $away = Team::factory()->create(['espn_id' => '278']);
    $game = Game::factory()->create(['espn_event_id' => '401756847', 'season' => 2025, 'season_type' => 'regular',
        'home_team_id' => $home->id, 'away_team_id' => $away->id, 'game_date' => '2025-08-23',
        'status' => 'STATUS_FINAL', 'home_score' => 31, 'away_score' => 7, 'odds_data' => ['keep' => true]]);
    $row = ['id' => 401756847, 'season' => 2025, 'seasonType' => 'regular', 'startDate' => '2025-08-23T22:30:00Z',
        'homeTeamId' => 2305, 'awayTeamId' => 278, 'homeScore' => 31, 'awayScore' => 7,
        'lines' => [['provider' => 'DraftKings', 'spread' => -14, 'spreadOpen' => -13.5]]];

    return [$game, $row];
}

it('stores signed historical pairs without inventing prices or backdating availability', function () {
    [$game, $row] = historicalSpreadFixture();
    $result = app(CfbHistoricalSpreadImporter::class)->import([$row], 2025);
    $home = MarketQuote::where('side', 'home')->firstOrFail();
    $away = MarketQuote::where('side', 'away')->firstOrFail();
    expect($result['quotes_created'])->toBe(2)
        ->and((float) $home->line)->toBe(-14.0)->and((float) $away->line)->toBe(14.0)
        ->and((float) $home->home_margin_equivalent)->toBe(14.0)
        ->and($home->price)->toBeNull()->and($home->is_pregame)->toBeFalse()
        ->and($home->captured_at->gt($home->commence_time))->toBeTrue()
        ->and($home->metadata['closing_line_verified'])->toBeFalse()
        ->and($game->fresh()->odds_data)->toBe(['keep' => true]);
    expect(app(CfbStoredPregameQuote::class)->latest($game->id, 'spreads', 'home', CarbonImmutable::parse($row['startDate'])))->toBeNull();
});

it('deduplicates retries and preserves changed source lines as new revisions', function () {
    [$game, $row] = historicalSpreadFixture();
    $importer = app(CfbHistoricalSpreadImporter::class);
    $importer->import([$row], 2025);
    expect($importer->import([$row], 2025)['existing'])->toBe(1);
    $row['lines'][0]['spread'] = -15;
    $importer->import([$row], 2025);
    expect(GameOddsSnapshot::count())->toBe(2)->and(MarketQuote::count())->toBe(4);
});

it('rejects mismatched scores teams seasons unfinished games and invalid lines', function () {
    [$game, $row] = historicalSpreadFixture();
    $importer = app(CfbHistoricalSpreadImporter::class);
    foreach ([['homeScore' => 30], ['awayTeamId' => 9999], ['season' => 2024], ['homeScore' => null]] as $change) {
        expect($importer->import([array_replace($row, $change)], 2025)['quotes_created'])->toBe(0);
    }
    $invalid = $row;
    $invalid['lines'][0]['spread'] = null;
    expect($importer->import([$invalid], 2025)['quotes_created'])->toBe(0);
    $game->update(['status' => 'STATUS_IN_PROGRESS']);
    expect($importer->import([$row], 2025)['quotes_created'])->toBe(0)->and(MarketQuote::count())->toBe(0);
});

it('supports pickem and multiple books in dry runs without writes', function () {
    [$game, $row] = historicalSpreadFixture();
    $row['lines'][] = ['provider' => 'Bovada', 'spread' => 0];
    $importer = app(CfbHistoricalSpreadImporter::class);
    expect($importer->import([$row], 2025, true)['eligible_lines'])->toBe(2)
        ->and(GameOddsSnapshot::count())->toBe(0)->and(MarketQuote::count())->toBe(0);
    $importer->import([$row], 2025);
    expect(MarketQuote::where('bookmaker_key', 'bovada')->where('line', 0)->count())->toBe(2);
});

it('requests the selected season without writing during preview', function () {
    [$game, $row] = historicalSpreadFixture();
    config(['services.collegefootballdata.api_key' => 'test', 'services.collegefootballdata.base_url' => 'https://cfbd.test']);
    Http::fake(['*' => Http::response([$row])]);
    $this->artisan('cfb:import-historical-spreads', ['--season' => 2025, '--dry-run' => true])->assertSuccessful();
    Http::assertSent(fn ($request) => $request['year'] === 2025 && $request['seasonType'] === 'regular');
    expect(MarketQuote::count())->toBe(0)->and(ProviderSourceFile::count())->toBe(0);
});

it('reports provider failures instead of succeeding empty', function () {
    config(['services.collegefootballdata.api_key' => 'test', 'services.collegefootballdata.base_url' => 'https://cfbd.test']);
    Http::fake(['*' => Http::response(['error' => 'denied'], 403)]);
    $this->artisan('cfb:import-historical-spreads', ['--season' => 2025, '--dry-run' => true])->assertFailed();
});
