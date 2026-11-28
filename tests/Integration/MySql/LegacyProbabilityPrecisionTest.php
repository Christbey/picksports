<?php

use App\Models\NFL\Game;
use App\Models\NFL\Prediction;
use App\Models\NFL\Team;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

function legacyProbabilityModelClasses(): array
{
    return [
        'nfl' => [Team::class, Game::class, Prediction::class],
        'cfb' => [App\Models\CFB\Team::class, App\Models\CFB\Game::class, App\Models\CFB\Prediction::class],
        'cbb' => [App\Models\CBB\Team::class, App\Models\CBB\Game::class, App\Models\CBB\Prediction::class],
        'nba' => [App\Models\NBA\Team::class, App\Models\NBA\Game::class, App\Models\NBA\Prediction::class],
        'mlb' => [App\Models\MLB\Team::class, App\Models\MLB\Game::class, App\Models\MLB\Prediction::class],
        'wcbb' => [App\Models\WCBB\Team::class, App\Models\WCBB\Game::class, App\Models\WCBB\Prediction::class],
        'wnba' => [App\Models\WNBA\Team::class, App\Models\WNBA\Game::class, App\Models\WNBA\Prediction::class],
    ];
}

function legacyProbabilityFixture(string $sport, ?float $probability): Model
{
    [$teamClass, $gameClass, $predictionClass] = legacyProbabilityModelClasses()[$sport];
    $home = $teamClass::factory()->create();
    $away = $teamClass::factory()->create();
    $game = $gameClass::factory()->create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
        'season' => 2026, 'season_type' => 2, 'week' => 1, 'game_date' => '2026-09-01', 'game_time' => '18:00:00']);

    return $predictionClass::query()->create(['game_id' => $game->id, 'win_probability' => $probability]);
}

function legacyProbabilityExpansion(): Migration
{
    return require database_path('migrations/2026_11_26_140000_expand_legacy_prediction_probability_precision.php');
}

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    expect(DB::connection()->getDatabaseName())->toBe('picksports_test_readiness');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Http::preventStrayRequests();
});

it('preserves old values and attributes while retaining future three-decimal probabilities for all seven sports', function () {
    $old = [];
    $indexes = [];
    foreach (array_keys(legacyProbabilityModelClasses()) as $sport) {
        // Trusted isolated fixture recreates the known pre-expansion column shape.
        DB::statement("ALTER TABLE `{$sport}_predictions` MODIFY `win_probability` DECIMAL(5,2) NULL DEFAULT NULL");
        $old[$sport] = legacyProbabilityFixture($sport, 0.55)->fresh();
        $indexes[$sport] = Schema::getIndexes($sport.'_predictions');
    }
    legacyProbabilityExpansion()->up();
    foreach (array_keys(legacyProbabilityModelClasses()) as $sport) {
        $table = $sport.'_predictions';
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        expect($columns['win_probability']['type'])->toBe('decimal(6,3)')
            ->and($columns['win_probability']['nullable'])->toBeTrue()
            ->and($columns['win_probability']['default'])->toBeNull()
            ->and($indexes[$sport])->toBe(Schema::getIndexes($table))
            ->and(BigDecimal::of($old[$sport]->getRawOriginal('win_probability'))->isEqualTo(
                $old[$sport]->fresh()->getRawOriginal('win_probability')
            ))->toBeTrue()
            ->and($old[$sport]->fresh()->getRawOriginal('updated_at'))->toBe($old[$sport]->getRawOriginal('updated_at'))
            ->and(BigDecimal::of('0.547')->isEqualTo(legacyProbabilityFixture($sport, 0.547)->fresh()->getRawOriginal('win_probability')))->toBeTrue()
            ->and(legacyProbabilityFixture($sport, null)->fresh()->win_probability)->toBeNull();
        $oldFields = $old[$sport]->getRawOriginal();
        $newFields = $old[$sport]->fresh()->getRawOriginal();
        unset($oldFields['win_probability'], $newFields['win_probability']);
        expect($newFields)->toBe($oldFields);
        // Domain-invalid fixtures test retained SQL signed/integer capacity, not valid model probabilities.
        expect(BigDecimal::of('999.99')->isEqualTo(legacyProbabilityFixture($sport, 999.99)->fresh()->getRawOriginal('win_probability')))->toBeTrue()
            ->and(BigDecimal::of('-1.25')->isEqualTo(legacyProbabilityFixture($sport, -1.25)->fresh()->getRawOriginal('win_probability')))->toBeTrue();
    }
    legacyProbabilityExpansion()->up();
    foreach ($old as $prediction) {
        expect(BigDecimal::of($prediction->getRawOriginal('win_probability'))->isEqualTo(
            $prediction->fresh()->getRawOriginal('win_probability')
        ))->toBeTrue();
    }
});

it('refuses last-table drift before changing any earlier known column', function () {
    foreach (array_keys(legacyProbabilityModelClasses()) as $sport) {
        DB::statement("ALTER TABLE `{$sport}_predictions` MODIFY `win_probability` DECIMAL(5,2) NULL DEFAULT NULL");
    }
    DB::statement('ALTER TABLE wnba_predictions MODIFY win_probability DECIMAL(5,1) NULL DEFAULT NULL');
    expect(fn () => legacyProbabilityExpansion()->up())->toThrow(RuntimeException::class, 'Refusing unknown');
    foreach (array_keys(legacyProbabilityModelClasses()) as $sport) {
        $column = collect(Schema::getColumns($sport.'_predictions'))->firstWhere('name', 'win_probability');
        expect($column['type'])->toBe($sport === 'wnba' ? 'decimal(5,1)' : 'decimal(5,2)');
    }
});

it('refuses rollback without erasing newly retained precision', function () {
    $prediction = legacyProbabilityFixture('nfl', 0.547);
    expect(fn () => legacyProbabilityExpansion()->down())->toThrow(RuntimeException::class, 'Forward-only');
    expect(BigDecimal::of('0.547')->isEqualTo($prediction->fresh()->getRawOriginal('win_probability')))->toBeTrue();
});
