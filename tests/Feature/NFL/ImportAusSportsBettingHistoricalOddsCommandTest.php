<?php

use App\Models\GameOddsSnapshot;
use App\Models\NFL\Game;
use App\Models\NFL\Team;

it('imports australia sports betting nfl csv odds with moneyline spread and total markets', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'PHI',
        'location' => 'Philadelphia',
        'name' => 'Eagles',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'DAL',
        'location' => 'Dallas',
        'name' => 'Cowboys',
    ]);

    $game = Game::query()->create([
        'espn_event_id' => 'aus-sports-betting-game',
        'espn_uid' => 'aus-sports-betting-game-uid',
        'season' => 2025,
        'week' => 1,
        'season_type' => 'regular',
        'game_date' => '2025-09-04',
        'game_time' => '20:20:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 24,
        'away_score' => 20,
        'status' => 'STATUS_FINAL',
    ]);

    $file = tempnam(sys_get_temp_dir(), 'aus-nfl-').'.csv';
    file_put_contents($file, implode("\n", [
        'Date,Home Team,Away Team,Home Score,Away Score,Overtime?,Playoff Game?,Neutral Venue?,Home Odds,Away Odds,Home Line,Over/Under Market,Notes',
        '2025-09-04,Philadelphia Eagles,Dallas Cowboys,24,20,N,N,N,1.62,2.36,-3,47.5,',
    ]));

    $this->artisan('nfl:import-aus-sports-betting-odds', [
        'file' => $file,
        '--season' => 2025,
    ])
        ->expectsOutputToContain('1 matched, 1 written')
        ->assertSuccessful();

    $snapshot = GameOddsSnapshot::query()->sole();

    expect($snapshot->game_id)->toBe($game->id)
        ->and($snapshot->source)->toBe('aus_sports_betting')
        ->and($snapshot->bookmaker_key)->toBe('aus_sports_betting')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.key'))->toBe('h2h')
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.outcomes.0.price'))->toBe(136)
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.0.outcomes.1.price'))->toBe(-161)
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.1.outcomes.0.point'))->toEqual(-3.0)
        ->and(data_get($snapshot->odds_data, 'bookmakers.0.markets.2.outcomes.0.point'))->toBe(47.5)
        ->and(data_get($snapshot->market_context, 'has_h2h'))->toBeTrue();
});

it('derives january and february games as the prior nfl season', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'KC',
        'location' => 'Kansas City',
        'name' => 'Chiefs',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'BUF',
        'location' => 'Buffalo',
        'name' => 'Bills',
    ]);

    Game::query()->create([
        'espn_event_id' => 'aus-sports-betting-playoff-game',
        'espn_uid' => 'aus-sports-betting-playoff-game-uid',
        'season' => 2025,
        'week' => 21,
        'season_type' => 'postseason',
        'game_date' => '2026-01-18',
        'game_time' => '18:30:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 27,
        'away_score' => 24,
        'status' => 'STATUS_FINAL',
    ]);

    $file = tempnam(sys_get_temp_dir(), 'aus-nfl-').'.csv';
    file_put_contents($file, implode("\n", [
        'Date,Home Team,Away Team,Home Score,Away Score,Playoff Game?,Home Odds,Away Odds,Home Line,Over/Under Market',
        '2026-01-18,Kansas City Chiefs,Buffalo Bills,27,24,Y,1.80,2.05,-2.5,48',
    ]));

    $this->artisan('nfl:import-aus-sports-betting-odds', [
        'file' => $file,
        '--season' => 2025,
    ])
        ->expectsOutputToContain('1 matched, 1 written')
        ->assertSuccessful();

    expect(GameOddsSnapshot::query()->count())->toBe(1)
        ->and(data_get(GameOddsSnapshot::query()->sole()->market_context, 'playoff_game'))->toBeTrue();
});

it('imports australia sports betting nfl xlsx workbooks', function () {
    $home = Team::factory()->create([
        'abbreviation' => 'SEA',
        'location' => 'Seattle',
        'name' => 'Seahawks',
    ]);
    $away = Team::factory()->create([
        'abbreviation' => 'SF',
        'location' => 'San Francisco',
        'name' => '49ers',
    ]);

    Game::query()->create([
        'espn_event_id' => 'aus-sports-betting-xlsx-game',
        'espn_uid' => 'aus-sports-betting-xlsx-game-uid',
        'season' => 2025,
        'week' => 10,
        'season_type' => 'regular',
        'game_date' => '2025-11-09',
        'game_time' => '16:25:00',
        'home_team_id' => $home->id,
        'away_team_id' => $away->id,
        'home_score' => 17,
        'away_score' => 27,
        'status' => 'STATUS_FINAL',
    ]);

    $file = ausSportsBettingXlsxFixture([
        ['Date', 'Home Team', 'Away Team', 'Home Score', 'Away Score', 'Playoff Game?', 'Home Odds', 'Away Odds', 'Home Line', 'Over/Under Market'],
        ['2025-11-09', 'Seattle Seahawks', 'San Francisco 49ers', '17', '27', 'N', '2.10', '1.76', '1.5', '44.5'],
    ]);

    $this->artisan('nfl:import-aus-sports-betting-odds', [
        'file' => $file,
        '--season' => 2025,
    ])
        ->expectsOutputToContain('1 matched, 1 written')
        ->assertSuccessful();

    expect(GameOddsSnapshot::query()->count())->toBe(1)
        ->and(data_get(GameOddsSnapshot::query()->sole()->odds_data, 'bookmakers.0.markets.0.outcomes.0.price'))->toBe(-132)
        ->and(data_get(GameOddsSnapshot::query()->sole()->odds_data, 'bookmakers.0.markets.0.outcomes.1.price'))->toBe(110);
});

function ausSportsBettingXlsxFixture(array $rows): string
{
    $file = tempnam(sys_get_temp_dir(), 'aus-nfl-').'.xlsx';
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
    $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);
    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

    $sheetRows = '';
    foreach ($rows as $rowIndex => $row) {
        $cells = '';
        foreach ($row as $columnIndex => $value) {
            $reference = chr(ord('A') + $columnIndex).($rowIndex + 1);
            $escaped = htmlspecialchars((string) $value, ENT_XML1);
            $cells .= "<c r=\"{$reference}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
        }
        $sheetRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
    }

    $zip->addFromString('xl/worksheets/sheet1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>{$sheetRows}</sheetData>
</worksheet>
XML);
    $zip->close();

    return $file;
}
