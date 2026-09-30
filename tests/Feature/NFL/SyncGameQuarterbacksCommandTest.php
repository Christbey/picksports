<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use App\Services\NFL\NflGameQuarterbackIdentitySync;
use Illuminate\Support\Facades\Http;

function qbIdentityFixture(): array
{
    $game = Game::factory()->create(['espn_event_id' => '401872656', 'home_team_id' => Team::factory()->create(['abbreviation' => 'SEA'])->id,
        'away_team_id' => Team::factory()->create(['abbreviation' => 'NE'])->id, 'season' => 2026, 'season_type' => '2', 'week' => 1,
        'game_date' => '2026-09-11', 'status' => 'STATUS_FINAL', 'home_score' => 20, 'away_score' => 13,
        'home_qb_name' => null, 'away_qb_name' => null, 'home_qb_id' => null, 'away_qb_id' => null]);
    $csv = "game_id,espn,season,game_type,week,gameday,home_team,away_team,home_score,away_score,home_qb_id,away_qb_id,home_qb_name,away_qb_name\n2026_01_NE_SEA,401872656,2026,REG,1,2026-09-10,SEA,NE,20,13,00-0035228,00-0039917,Sam Darnold,Drake Maye\n";

    return [$game, $csv];
}

it('defaults to an audited dry run and repairs only quarterback fields when applied', function () {
    [$game, $csv] = qbIdentityFixture();
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $original = $prediction->fresh()->getRawOriginal();
    Http::fake([NflGameQuarterbackIdentitySync::SOURCE_URL => Http::response($csv)]);
    $this->artisan('nfl:sync-game-quarterbacks', ['--season' => 2026])->assertSuccessful();
    expect($game->fresh()->home_qb_name)->toBeNull();
    $this->artisan('nfl:sync-game-quarterbacks', ['--season' => 2026, '--apply' => true])->assertSuccessful();
    $game->refresh();
    expect($game->home_qb_name)->toBe('Sam Darnold')
        ->and($game->away_qb_name)->toBe('Drake Maye')
        ->and($game->quarterback_identity_evidence['home']['pregame_observed'])->toBeFalse()
        ->and($game->quarterback_identity_evidence['home']['source_sha256'])->toBe(hash('sha256', $csv))
        ->and($game->home_score)->toBe(20)
        ->and($prediction->fresh()->getRawOriginal())->toBe($original);
    expect(app(NflGameQuarterbackIdentitySync::class)->sync($csv, 2026, true)['changed_games'])->toBe(0);
});

it('holds conflicting identities and mismatched source games without partial side updates', function (string $change) {
    [$game, $csv] = qbIdentityFixture();
    match ($change) {
        'name_conflict' => $game->update(['home_qb_name' => 'Other QB']),
        'score' => $csv = str_replace('20,13,', '27,13,', $csv),
        'team' => $csv = str_replace(',SEA,NE,', ',NE,SEA,', $csv),
        'date' => $csv = str_replace('2026-09-10', '2026-09-08', $csv),
        'duplicate' => $csv .= explode("\n", $csv)[1]."\n",
        'missing_qb' => $csv = str_replace('Sam Darnold', '', $csv),
    };
    $result = app(NflGameQuarterbackIdentitySync::class)->sync($csv, 2026, true);
    expect($result['unresolved_games'])->toBe(1)->and($result['changed_games'])->toBe(0)
        ->and($game->fresh()->away_qb_name)->toBeNull();
})->with(['name_conflict', 'score', 'team', 'date', 'duplicate', 'missing_qb']);

it('does not label a scheduled game from a future schedule starter', function () {
    [$game, $csv] = qbIdentityFixture();
    $game->update(['status' => 'STATUS_SCHEDULED']);
    expect(app(NflGameQuarterbackIdentitySync::class)->sync($csv, 2026, true)['games'])->toBe([])
        ->and($game->fresh()->home_qb_name)->toBeNull();
});

it('matches postseason event identity without equating ESPN and nflverse week numbering', function () {
    [$game, $csv] = qbIdentityFixture();
    $game->update(['season_type' => '3']);
    $csv = str_replace(',REG,1,', ',WC,19,', $csv);
    expect(app(NflGameQuarterbackIdentitySync::class)->sync($csv, 2026, true)['unresolved_games'])->toBe(0)
        ->and($game->fresh()->home_qb_name)->toBe('Sam Darnold');
});
