<?php

namespace App\Services\Predictions;

use Illuminate\Database\Eloquent\Model;
use JsonException;

class SportsAiPredictionPayloadBuilder
{
    public function __construct(
        private readonly SportsOperationalContextBuilder $operationalContextBuilder,
        private readonly SportsExternalGameContextBuilder $externalGameContextBuilder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $sport, Model $prediction): array
    {
        $sport = strtolower($sport);
        $game = $prediction->getRelationValue('game') ?? $prediction->game;

        if ($game) {
            $game->loadMissing(['homeTeam', 'awayTeam']);
        }

        $homeTeam = $game?->homeTeam;
        $awayTeam = $game?->awayTeam;
        $homeWinProbability = $this->floatAttribute($prediction, 'win_probability');
        $pickSide = $homeWinProbability !== null && $homeWinProbability >= 0.5 ? 'home' : 'away';
        $pickTeam = $pickSide === 'home' ? $homeTeam : $awayTeam;

        $externalContext = $this->externalGameContextBuilder->build($sport, $game, $prediction);

        return [
            'schema_version' => 'sports_ai_prediction_payload_v2',
            'sport' => $sport,
            'generated_at' => now()->toIso8601String(),
            'game' => [
                'id' => $game?->id,
                'espn_id' => $this->attribute($game, 'espn_id'),
                'season' => $this->attribute($game, 'season'),
                'season_type' => $this->attribute($game, 'season_type'),
                'week' => $this->attribute($game, 'week'),
                'date' => $game?->game_date?->toDateString(),
                'time' => $this->attribute($game, 'game_time'),
                'status' => $this->attribute($game, 'status'),
                'matchup' => $this->attribute($game, 'short_name') ?: $this->attribute($game, 'name'),
                'venue' => $this->attribute($game, 'venue_name'),
                'home_team' => $this->teamPayload($homeTeam),
                'away_team' => $this->teamPayload($awayTeam),
            ],
            'calculated_model' => [
                'pick_side' => $pickSide,
                'pick_team' => $this->teamName($pickTeam),
                'predicted_spread' => $this->floatAttribute($prediction, 'predicted_spread'),
                'predicted_total' => $this->floatAttribute($prediction, 'predicted_total'),
                'home_win_probability' => $homeWinProbability,
                'pick_win_probability' => $homeWinProbability !== null ? max($homeWinProbability, 1 - $homeWinProbability) : null,
                'confidence_score' => $this->floatAttribute($prediction, 'confidence_score'),
                'vegas_spread' => $this->floatAttribute($prediction, 'vegas_spread'),
                'model_version' => $this->attribute($prediction, 'model_version'),
                'feature_version' => $this->attribute($prediction, 'feature_version'),
                'blend_version' => $this->attribute($prediction, 'blend_version'),
            ],
            'market_context' => [
                'odds_updated_at' => $this->attribute($game, 'odds_updated_at'),
                'odds_markets' => $this->summarizeOdds($this->arrayAttribute($game, 'odds_data')),
                'market_context' => $this->arrayDataGet($prediction, 'model_metadata.market_context'),
            ],
            'operational_context' => $this->operationalContextBuilder->build($sport, $game),
            'external_game_context' => $externalContext,
            'model_metadata' => $this->arrayAttribute($prediction, 'model_metadata'),
            'existing_narrative' => $this->arrayAttribute($prediction, 'narrative_json'),
            'raw_prediction_snapshot' => $this->predictionSnapshot($prediction),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hash(array $payload): string
    {
        $payload = $this->normalizeForHash($payload);

        try {
            return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return hash('sha256', serialize($payload));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function calculatedEdge(Model $prediction): array
    {
        $homeWinProbability = $this->floatAttribute($prediction, 'win_probability');
        $vegasSpread = $this->floatAttribute($prediction, 'vegas_spread');
        $predictedSpread = $this->floatAttribute($prediction, 'predicted_spread');

        return [
            'predicted_spread' => $predictedSpread,
            'predicted_total' => $this->floatAttribute($prediction, 'predicted_total'),
            'home_win_probability' => $homeWinProbability,
            'pick_win_probability' => $homeWinProbability !== null ? max($homeWinProbability, 1 - $homeWinProbability) : null,
            'confidence_score' => $this->floatAttribute($prediction, 'confidence_score'),
            'vegas_spread' => $vegasSpread,
            'spread_edge' => $predictedSpread !== null && $vegasSpread !== null
                ? round($predictedSpread + $vegasSpread, 2)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function teamPayload(?Model $team): array
    {
        return [
            'id' => $team?->id,
            'name' => $this->teamName($team),
            'abbreviation' => $this->attribute($team, 'abbreviation'),
            'record' => [
                'wins' => $this->attribute($team, 'wins'),
                'losses' => $this->attribute($team, 'losses'),
            ],
        ];
    }

    private function teamName(?Model $team): ?string
    {
        if (! $team) {
            return null;
        }

        $display = trim((string) $this->attribute($team, 'display_name'));
        if ($display !== '') {
            return $display;
        }

        return trim(((string) $this->attribute($team, 'location')).' '.((string) $this->attribute($team, 'name'))) ?: null;
    }

    /**
     * @param  array<string, mixed>|null  $oddsData
     * @return array<int, array<string, mixed>>
     */
    private function summarizeOdds(?array $oddsData): array
    {
        $bookmakers = is_array($oddsData['bookmakers'] ?? null) ? $oddsData['bookmakers'] : [];
        $markets = [];

        foreach (array_slice($bookmakers, 0, 3) as $bookmaker) {
            foreach (($bookmaker['markets'] ?? []) as $market) {
                if (! is_array($market)) {
                    continue;
                }

                $markets[] = [
                    'bookmaker' => $bookmaker['key'] ?? $bookmaker['title'] ?? null,
                    'market' => $market['key'] ?? null,
                    'outcomes' => array_slice(array_map(fn ($outcome): array => [
                        'name' => $outcome['name'] ?? null,
                        'price' => $outcome['price'] ?? null,
                        'point' => $outcome['point'] ?? null,
                    ], is_array($market['outcomes'] ?? null) ? $market['outcomes'] : []), 0, 4),
                ];
            }
        }

        return array_slice($markets, 0, 8);
    }

    /**
     * @return array<string, mixed>
     */
    private function predictionSnapshot(Model $prediction): array
    {
        return collect($prediction->getAttributes())
            ->except([
                'created_at',
                'updated_at',
                'narrative_json',
                'narrative_input_hash',
                'narrative_generated_at',
            ])
            ->all();
    }

    private function attribute(?Model $model, string $key): mixed
    {
        if (! $model || ! array_key_exists($key, $model->getAttributes())) {
            return null;
        }

        return $model->getAttribute($key);
    }

    private function floatAttribute(Model $model, string $key): ?float
    {
        $value = $this->attribute($model, $key);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayAttribute(?Model $model, string $key): ?array
    {
        $value = $this->attribute($model, $key);

        return is_array($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayDataGet(Model $model, string $key): ?array
    {
        $value = data_get($model, $key);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        unset($payload['generated_at']);

        return $payload;
    }
}
