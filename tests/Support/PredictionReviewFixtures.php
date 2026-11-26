<?php

namespace Tests\Support;

use App\Models\BetDecision;
use App\Models\CalculationRelease;
use App\Models\CalculationRun;
use App\Models\CanonicalPrediction;
use App\Models\EventInputSnapshot;
use App\Models\PredictionMarket;
use App\Models\SportEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PredictionReviewFixtures
{
    /** @return array<string, mixed> */
    public static function baseline(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/prediction-review-baselines.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{Model, Model, CanonicalPrediction} */
    public static function game(string $sport, array $inputs = []): array
    {
        $baseline = self::baseline();
        $definition = $baseline['sports'][$sport];
        $namespace = strtoupper($sport);
        $gameClass = "App\\Models\\{$namespace}\\Game";
        $teamClass = "App\\Models\\{$namespace}\\Team";
        $predictionClass = "App\\Models\\{$namespace}\\Prediction";
        $event = SportEvent::factory()->create(['sport' => $sport, 'starts_at' => now()->addHours(4)]);
        $game = $gameClass::factory()->create([
            'sport_event_id' => $event->id, 'season' => $definition['season'], 'season_type' => $definition['season_type'],
            'home_team_id' => $teamClass::factory(), 'away_team_id' => $teamClass::factory(),
            'game_date' => $event->starts_at->utc()->toDateString(), 'game_time' => $event->starts_at->utc()->toTimeString(),
            'status' => 'STATUS_SCHEDULED', 'odds_data' => ['bookmakers' => []],
        ]);
        if ($sport === 'cbb') {
            $inputs['home']['team_id'] ??= $game->home_team_id;
            $inputs['away']['team_id'] ??= $game->away_team_id;
        }
        $legacyAttributes = [
            'game_id' => $game->id,
            'predicted_spread' => $baseline['projection']['legacy_spread'],
            'predicted_total' => $definition['total'],
            'win_probability' => $baseline['projection']['home_probability'],
            'confidence_score' => $baseline['projection']['confidence'],
        ];
        $legacy = $sport === 'mlb'
            ? $predictionClass::query()->create(['season' => $definition['season'], 'season_type' => $definition['season_type'], ...$legacyAttributes])
            : $predictionClass::factory()->create($legacyAttributes);
        $release = CalculationRelease::factory()->approved()->create(['sport' => $sport, 'phase' => 'pregame']);
        $snapshot = EventInputSnapshot::factory()->create(['sport' => $sport, 'sport_event_id' => $event->id, 'inputs' => $inputs]);
        $run = CalculationRun::factory()->create(['output_hash' => str_repeat('a', 64),
            'sport_event_id' => $event->id, 'event_input_snapshot_id' => $snapshot->id,
            'calculation_release_id' => $release->id, 'status' => 'succeeded',
        ]);
        $canonical = CanonicalPrediction::factory()->create(['output_hash' => str_repeat('a', 64),
            'sport' => $sport, 'sport_event_id' => $event->id, 'calculation_run_id' => $run->id,
            'phase' => 'pregame', 'publication_state' => 'draft', 'published_at' => null,
            'detail_source' => null, 'detail_sport' => null, 'detail_id' => null, 'revision' => 1,
        ]);
        foreach ([
            ['market_type' => 'moneyline', 'selection' => 'home', 'probability' => $baseline['projection']['home_probability']],
            ['market_type' => 'spread', 'selection' => 'home', 'projected_line' => $baseline['projection']['canonical_home_line']],
            ['market_type' => 'total', 'selection' => 'combined', 'projected_line' => $definition['total']],
        ] as $market) {
            PredictionMarket::factory()->create(['prediction_id' => $canonical->id, 'confidence_score' => 68, ...$market]);
        }
        $canonical->update(['publication_state' => 'published', 'published_at' => now()]);

        return [$game, $legacy, $canonical->refresh()];
    }

    public static function approval(Model $game, CanonicalPrediction $prediction, array $overrides = []): BetDecision
    {
        return BetDecision::query()->create([
            'decision_run_id' => (string) Str::uuid(), 'decision_hash' => hash('sha256', Str::uuid()),
            'source_table' => 'predictions', 'source_id' => $prediction->id,
            'prediction_table' => 'predictions', 'prediction_id' => $prediction->id,
            'game_table' => $game->getTable(), 'game_id' => $game->id, 'sport' => $prediction->sport,
            'market_type' => 'spread', 'market_key' => 'spreads', 'side' => 'home', 'line' => -3.5,
            'price' => -110, 'bookmaker' => 'fixture-book', 'edge' => 1.0,
            'status' => 'released', 'recommendation_label' => 'model_bet',
            'is_public' => true, 'is_tracking_only' => false, 'is_bet' => true, 'pregame_safe' => true,
            'market_snapshot' => ['quote' => ['captured_at' => now()->toIso8601String()]],
            'decided_at' => now(), 'game_start_at' => now()->addHours(4),
            ...$overrides,
        ]);
    }
}
