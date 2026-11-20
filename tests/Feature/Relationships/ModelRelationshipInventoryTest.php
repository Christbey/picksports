<?php

use App\Models\MLB\StartingPitcherForecast;
use App\Models\NFL\Game;
use App\Models\NFL\GameContextFact;
use App\Models\NFL\Prediction;
use App\Models\NFL\ResearchDocument;
use App\Models\NFL\ResearchSource;
use App\Models\NFL\Team;
use App\Models\PlayerProp;
use App\Models\PredictionFeatureSnapshot;
use App\Models\UserBet;
use App\Services\Relationships\RelationshipInventory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('names either-side prediction builders explicitly across sports', function (string $sport) {
    $teamClass = 'App\\Models\\'.$sport.'\\Team';
    $team = new $teamClass;
    $team->id = 17;

    expect($team->queryPredictions())->toBeInstanceOf(Builder::class)
        ->and(method_exists($team, 'predictions'))->toBeFalse()
        ->and($team->queryPredictions()->toSql())->toContain('home_team_id', 'away_team_id');
})->with(['NFL', 'NBA', 'CBB', 'CFB', 'WCBB', 'WNBA']);

it('returns forecasts for either side but excludes other teams', function () {
    $teams = Team::factory()->count(3)->create();
    foreach ([[0, 1], [1, 0], [1, 2]] as [$home, $away]) {
        $game = Game::factory()->create(['home_team_id' => $teams[$home]->id, 'away_team_id' => $teams[$away]->id]);
        Prediction::factory()->create(['game_id' => $game->id]);
    }

    expect($teams[0]->queryPredictions()->count())->toBe(2);
});

it('uses explicitly loaded teams without convenience accessor queries', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $prediction = Prediction::factory()->create(['game_id' => $game->id]);
    $loaded = Prediction::with(['game.homeTeam', 'game.awayTeam'])->findOrFail($prediction->id);

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($loaded->game->homeTeam->is($teams[0]))->toBeTrue()
        ->and($loaded->game->awayTeam->is($teams[1]))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([])
        ->and(method_exists($loaded, 'getHomeTeamAttribute'))->toBeFalse()
        ->and(method_exists($loaded, 'getAwayTeamAttribute'))->toBeFalse();
    DB::disableQueryLog();
});

it('inventories all concrete model declarations against the migrated test schema', function () {
    $inventory = app(RelationshipInventory::class)->inspect();
    expect(count($inventory))->toBeGreaterThan(150)
        ->and($inventory[Game::class]['relations']['homeTeam']['foreign_key'])->toBe('home_team_id')
        ->and($inventory[Game::class]['relations']['homeTeam']['owner_key'])->toBe('id')
        ->and($inventory[StartingPitcherForecast::class]['relations']['predictedPitcher']['owner_key'])->toBe('espn_id')
        ->and($inventory[UserBet::class]['relations']['prediction']['morph_type'])->toBe('prediction_type');

    foreach ($inventory as $modelClass => $definition) {
        expect($definition['table_exists'])->toBeTrue($modelClass);
        $columns = array_column($definition['columns'], 'name');
        foreach ($definition['relations'] as $name => $relation) {
            if ($relation['requires_context'] ?? false) {
                expect($modelClass)->toBe(PlayerProp::class);

                continue;
            }
            if ($relation['kind'] === 'many_to_many') {
                expect(Schema::getColumnListing($relation['pivot_table']))
                    ->toContain($relation['foreign_key'], $relation['related_key']);
            } elseif (in_array($relation['kind'], ['belongs_to', 'morph_to'], true)) {
                if (($relation['foreign_key_source'] ?? null) === 'accessor') {
                    expect($modelClass)->toBe(PredictionFeatureSnapshot::class)
                        ->and($name)->toBe('metadataPayload')
                        ->and($relation['foreign_key'])->toBe('metadata_payload_hash');
                } else {
                    expect($columns)->toContain($relation['foreign_key']);
                }
                if ($relation['kind'] === 'belongs_to') {
                    expect(Schema::getColumnListing($relation['related_table']))
                        ->toContain($relation['owner_key']);
                    foreach ($definition['foreign_keys'] as $foreign) {
                        if ($foreign['columns'] === [$relation['foreign_key']]) {
                            expect($relation['related_table'])->toBe($foreign['foreign_table'], $modelClass.'::'.$name)
                                ->and([$relation['owner_key']])->toBe($foreign['foreign_columns'], $modelClass.'::'.$name);
                        }
                    }
                }
            } elseif ($relation['kind'] === 'has_one_or_many') {
                expect($columns)->toContain($relation['local_key']);
                expect(Schema::getColumnListing($relation['related_table']))
                    ->toContain($relation['foreign_key']);
            }
        }
    }
});

it('eager loads recorded game fact ownership and document sources', function () {
    $teams = Team::factory()->count(2)->create();
    $game = Game::factory()->create(['home_team_id' => $teams[0]->id, 'away_team_id' => $teams[1]->id]);
    $fact = GameContextFact::query()->create([
        'game_id' => $game->id, 'team_id' => $teams[0]->id, 'kind' => 'availability',
        'subject' => 'Recorded team context', 'source_url' => 'https://example.test/fact',
        'recorded_at' => now(), 'evidence_hash' => hash('sha256', 'fact'), 'evidence' => [],
    ]);
    $source = ResearchSource::query()->create([
        'key' => 'relationship-fixture', 'team' => 'NFL', 'url' => 'https://example.test/source', 'kind' => 'official',
    ]);
    $document = new ResearchDocument(['source_id' => $source->id]);
    $document->load('source');
    $fact->load('game', 'team');

    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($fact->game->is($game))->toBeTrue()
        ->and($fact->team->is($teams[0]))->toBeTrue()
        ->and($document->source->is($source))->toBeTrue()
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});
