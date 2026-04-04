<?php

use App\Models\NFL\Team;
use App\Models\Sports\FuturesOddsSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('imports nfl team win total snapshots from sports odds history', function () {
    Team::factory()->create([
        'name' => 'Chiefs',
        'location' => 'Kansas City',
        'abbreviation' => 'KC',
    ]);

    Http::fake([
        'https://www.covers.com/sportsoddshistory/nfl-team/*' => Http::response(<<<'HTML'
            <html>
                <body>
                    <h2>Regular Season Win Totals Odds</h2>
                    <table class="soh1">
                        <tr>
                            <th>Year</th>
                            <th>Line</th>
                            <th>Over</th>
                            <th>Under</th>
                            <th>Week Bet Settled</th>
                            <th>Wins</th>
                            <th>Result</th>
                        </tr>
                        <tr>
                            <td>2025</td>
                            <td>11.5</td>
                            <td>+118</td>
                            <td>-140</td>
                            <td>Week 13</td>
                            <td>6</td>
                            <td>Under</td>
                        </tr>
                    </table>
                </body>
            </html>
            HTML),
    ]);

    Artisan::call('sports:import-soh-nfl-team-win-totals', [
        '--season' => [2025],
        '--team' => ['KC'],
    ]);

    $rows = FuturesOddsSnapshot::query()
        ->where('sport', 'nfl')
        ->where('market_key', 'season_wins')
        ->orderBy('outcome_name')
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('outcome_name')->all())->toBe(['Over', 'Under'])
        ->and($rows->pluck('price')->all())->toBe([118, -140])
        ->and($rows->pluck('outcome_point')->map(fn ($value) => (float) $value)->all())->toBe([11.5, 11.5])
        ->and($rows->pluck('bookmaker')->unique()->all())->toBe(['sportsoddshistory'])
        ->and($rows->pluck('nfl_team_id')->unique()->filter()->count())->toBe(1)
        ->and($rows->first()?->season)->toBe(2025)
        ->and($rows->first()?->raw_data['source'] ?? null)->toBe('SportsOddsHistory');
});
