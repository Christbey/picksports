<?php

namespace App\Console\Commands\CBB;

use App\Actions\CBB\CalculateBettingValue;
use App\Console\Commands\Sports\AbstractCollegeGeneratePredictionsCommand;
use Illuminate\Support\Collection;

class GeneratePredictionsCommand extends AbstractCollegeGeneratePredictionsCommand
{
    protected const COMMAND_NAME = 'cbb:generate-predictions';

    protected const COMMAND_DESCRIPTION = 'Generate CBB game predictions based on Elo ratings and team metrics';

    protected const GENERATE_ACTION_CLASS = \App\Actions\CBB\GeneratePrediction::class;

    protected const GAME_MODEL_CLASS = \App\Models\CBB\Game::class;

    protected const PREDICTION_MODEL_CLASS = \App\Models\CBB\Prediction::class;

    protected const USES_EASTERN_DATE_WINDOW = true;

    protected function topPredictionsHeading(): string
    {
        return 'Top 10 CBB Totals by Edge:';
    }

    protected function topPredictionHeaders(): array
    {
        return ['Game', 'Pick', 'Model', 'Market', 'Edge', 'Confidence'];
    }

    protected function topPredictions(): Collection
    {
        /** @var class-string<\App\Models\CBB\Prediction> $predictionModel */
        $predictionModel = $this->predictionModelClass();
        $calculator = app(CalculateBettingValue::class);

        return $predictionModel::query()
            ->with(['game.homeTeam', 'game.awayTeam'])
            ->when(
                $this->generatedGameIds() !== [],
                fn ($query) => $query->whereIn('game_id', $this->generatedGameIds())
            )
            ->latest()
            ->get()
            ->map(function ($prediction) use ($calculator) {
                $recommendations = $calculator->execute($prediction->game);
                $totalRecommendation = collect($recommendations)->firstWhere('type', 'total');

                if ($totalRecommendation === null) {
                    return null;
                }

                $prediction->command_total_recommendation = $totalRecommendation;

                return $prediction;
            })
            ->filter()
            ->sortByDesc(fn ($prediction) => [
                (float) data_get($prediction, 'command_total_recommendation.edge', 0.0),
                (float) data_get($prediction, 'command_total_recommendation.confidence', 0.0),
            ])
            ->take(10)
            ->values();
    }

    protected function topPredictionRow(mixed $prediction): array
    {
        $game = $prediction->game;
        $homeTeam = $this->formatTeamName($game->homeTeam);
        $awayTeam = $this->formatTeamName($game->awayTeam);
        $recommendation = (array) ($prediction->command_total_recommendation ?? []);

        return [
            "{$awayTeam} @ {$homeTeam}",
            (string) ($recommendation['recommendation'] ?? 'N/A'),
            (string) ($recommendation['model_line'] ?? 'N/A'),
            (string) ($recommendation['market_line'] ?? 'N/A'),
            (string) ($recommendation['edge'] ?? 'N/A'),
            (string) ($recommendation['confidence'] ?? 'N/A'),
        ];
    }
}
