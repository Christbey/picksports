<?php

use App\Models\NFL\Game;
use App\Models\NFL\Player;
use App\Models\NFL\PlayerStat;
use App\Services\BettingRecommendations\NflPropRoleHistory;
use Tests\TestCase;

uses(TestCase::class);

function roleHistoryRow(int $id, ?string $quarterback): PlayerStat
{
    $stat = new PlayerStat(['game_id' => $id, 'team_id' => 1, 'passing_attempts' => 5]);
    $stat->setRelation('game', new Game(['home_team_id' => 1, 'away_team_id' => 2, 'home_qb_name' => $quarterback]));

    return $stat;
}

it('excludes known relief appearances but does not invent an identity for unknown starters', function () {
    $result = (new NflPropRoleHistory)->select(collect([
        roleHistoryRow(1, 'Drake Maye'), roleHistoryRow(2, 'Jacoby Brissett'), roleHistoryRow(3, null),
    ]), new Player(['position' => 'QB', 'full_name' => 'Drake Maye']));
    expect($result['history']->pluck('game_id')->all())->toBe([1, 3])
        ->and($result['evidence']['excluded_game_ids'])->toBe([2])
        ->and($result['evidence']['unknown_starter_games'])->toBe(1);
});

it('requires three matching quarterback games before using a receiver split', function () {
    $history = collect([roleHistoryRow(1, 'QB One'), roleHistoryRow(2, 'QB One'), roleHistoryRow(3, 'QB Two')]);
    $player = new Player(['position' => 'WR']);
    $result = (new NflPropRoleHistory)->select($history, $player, 'QB One');
    expect($result['history'])->toHaveCount(3)
        ->and($result['evidence']['reason'])->toBe('insufficient_same_quarterback_history');
    $history->push(roleHistoryRow(4, 'QB One'));
    $result = (new NflPropRoleHistory)->select($history, $player, 'QB One');
    expect($result['history']->pluck('game_id')->all())->toBe([1, 2, 4]);
});
