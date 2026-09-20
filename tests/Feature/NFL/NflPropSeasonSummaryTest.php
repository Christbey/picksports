<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerProp;
use App\Models\NFL\PlayerStat;
use App\Models\NFL\Team;
use App\Services\BettingRecommendations\NflPropSeasonSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

function seasonSummaryFixture(): array
{
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $home->id]);
    $game = Game::factory()->create([
        'home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'status' => 'STATUS_SCHEDULED',
        'game_date' => '2026-09-20', 'game_time' => '17:00:00',
    ]);
    $prop = PlayerProp::create([
        'game_id' => $game->id, 'player_id' => $player->id, 'player_name' => 'Test Player',
        'market' => 'player_rush_attempts', 'line' => 10, 'bookmaker' => 'test',
        'recommended_side' => 'Under', 'over_price' => -110, 'under_price' => -110,
        'confidence_score' => 75, 'confidence_decomposition' => ['stat_summary' => ['season_avg' => 99]],
    ]);

    return [$prop, $game, $player];
}

function seasonSummaryStat(Game $target, Player $player, string $date, ?int $value, array $overrides = []): void
{
    $game = Game::factory()->create([
        'home_team_id' => $target->home_team_id, 'away_team_id' => $target->away_team_id,
        'season' => $target->season, 'season_type' => 2, 'status' => 'STATUS_FINAL',
        'game_date' => $date, 'game_time' => '17:00:00', ...$overrides,
    ]);
    PlayerStat::factory()->create(['player_id' => $player->id, 'team_id' => $player->team_id, 'game_id' => $game->id, 'rushing_attempts' => $value]);
}

test('NFL season evidence excludes other seasons preseason future unfinished and missing stats without rewriting predictions', function () {
    [$prop, $game, $player] = seasonSummaryFixture();
    seasonSummaryStat($game, $player, '2026-09-06', 10);
    seasonSummaryStat($game, $player, '2026-09-10', 20);
    seasonSummaryStat($game, $player, '2026-09-13', 0);
    seasonSummaryStat($game, $player, '2026-09-14', null);
    seasonSummaryStat($game, $player, '2025-09-13', 100, ['season' => 2025]);
    seasonSummaryStat($game, $player, '2026-08-13', 200, ['season_type' => 1]);
    seasonSummaryStat($game, $player, '2026-09-15', 300, ['season_type' => 3]);
    seasonSummaryStat($game, $player, '2026-09-21', 400);
    seasonSummaryStat($game, $player, '2026-09-16', 600, ['status' => 'STATUS_IN_PROGRESS']);
    PlayerStat::factory()->create(['player_id' => $player->id, 'team_id' => $player->team_id, 'game_id' => $game->id, 'rushing_attempts' => 500]);
    $game->update(['status' => 'STATUS_FINAL']);
    $before = $prop->fresh()->getAttributes();
    $summary = app(NflPropSeasonSummary::class)->forProps(new Collection([$prop]))[$prop->id];
    expect($summary['season'])->toBe(2026)
        ->and($summary['average'])->toBe(10.0)
        ->and($summary['games'])->toBe(3)
        ->and($summary['missing_stat_games'])->toBe(1)
        ->and($summary['cover_record']['recommendation_record'])->toBe('1-1-1')
        ->and($summary['cover_record']['win_rate'])->toBe(50.0)
        ->and($prop->fresh()->getAttributes())->toBe($before);
});

test('NFL season evidence returns unknown rather than historical values when no current season stats exist', function () {
    [$prop, $game, $player] = seasonSummaryFixture();
    seasonSummaryStat($game, $player, '2025-09-13', 100, ['season' => 2025]);
    $summary = app(NflPropSeasonSummary::class)->forProps(new Collection([$prop]))[$prop->id];
    expect($summary['average'])->toBeNull()->and($summary['games'])->toBe(0)->and($summary['cover_record'])->toBeNull();
});

test('NFL season comes from the matchup rather than the calendar year and batches all markets', function () {
    [$prop, $game, $player] = seasonSummaryFixture();
    $game->update(['season' => 2025, 'game_date' => '2026-01-11']);
    seasonSummaryStat($game, $player, '2026-01-03', 12);
    $other = $prop->replicate();
    $other->market = 'player_rush_yds';
    $other->save();
    $props = new Collection([$prop, $other]);
    $props->load('game');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summaries = app(NflPropSeasonSummary::class)->forProps($props);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)
        ->and($summaries[$prop->id]['season'])->toBe(2025)
        ->and($summaries[$prop->id]['average'])->toBe(12.0)
        ->and($summaries[$other->id]['average'])->toBeNull();
});
