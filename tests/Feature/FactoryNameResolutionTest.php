<?php

use App\Models\CalculationRun;
use App\Models\CFB\FpiRating;
use App\Models\EventInputSnapshot;
use App\Models\NFL\PlayerStat;
use App\Models\User;
use App\Models\WNBA\TeamStat;
use Database\Factories\CalculationRunFactory;
use Database\Factories\CfbFpiRatingFactory;
use Database\Factories\EventInputSnapshotFactory;
use Database\Factories\NflPlayerStatFactory;
use Database\Factories\UserFactory;
use Database\Factories\WnbaTeamStatFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

it('preserves camel case in factory names on case sensitive filesystems', function (string $model, string $factory) {
    expect(Factory::resolveFactoryName($model))->toBe($factory);
})->with([
    [CalculationRun::class, CalculationRunFactory::class],
    [EventInputSnapshot::class, EventInputSnapshotFactory::class],
    [PlayerStat::class, NflPlayerStatFactory::class],
    [FpiRating::class, CfbFpiRatingFactory::class],
    [TeamStat::class, WnbaTeamStatFactory::class],
    [User::class, UserFactory::class],
]);
