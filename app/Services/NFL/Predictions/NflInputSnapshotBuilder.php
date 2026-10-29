<?php

namespace App\Services\NFL\Predictions;

use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\NFL\Game;
use App\Models\NFL\PlayerInjury;
use App\Models\NFL\TeamMetric;
use App\Models\SportEvent;
use App\Services\Predictions\Football\FootballInputSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class NflInputSnapshotBuilder extends FootballInputSnapshotBuilder
{
    private ?string $marketCapturedAt = null;

    private ?array $recommendationContext = null;

    public function __construct(private readonly NflPregameMarketSnapshot $marketSnapshot, private readonly NflRecommendationInputBuilder $recommendations) {}

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $this->recommendationContext = null;
        $game = $event->nflGame()->with(['homeTeam', 'awayTeam'])->first();
        if ($event->sport === 'nfl' && $release->sport === 'nfl' && $release->phase === 'pregame'
            && $game instanceof Game && $game->homeTeam !== null && $game->awayTeam !== null && in_array($game->status, ['STATUS_SCHEDULED', 'STATUS_DELAYED'], true)
            && $event->starts_at?->isFuture()) {
            $this->recommendationContext = $this->recommendations->capture($game);
        }
        try {
            return parent::build($event, $release);
        } finally {
            $this->recommendationContext = null;
        }
    }

    protected function sport(): string
    {
        return 'nfl';
    }

    protected function inputSchemaVersion(): string
    {
        return NflCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'nflGame';
    }

    /** @return class-string<Model> */
    protected function gameModel(): string
    {
        return Game::class;
    }

    /** @return class-string<Model> */
    protected function teamMetricModel(): string
    {
        return TeamMetric::class;
    }

    /** @return class-string<Model> */
    protected function playerInjuryModel(): string
    {
        return PlayerInjury::class;
    }

    /** @return list<string> */
    protected function teamMetricSeasonTypes(Model $game): array
    {
        // The regular-season sample remains the model's team-strength prior
        // throughout the playoffs, including before the first playoff game.
        if (in_array((string) $game->getAttribute('season_type'), NflPregameHorizon::seasonTypes(), true)) {
            return [(string) config('nfl.season.types.regular', 2), '2', 'regular', 'Regular Season'];
        }

        return parent::teamMetricSeasonTypes($game);
    }

    /** @return array<string, mixed> */
    protected function additionalInputs(
        SportEvent $event,
        Model $game,
        CarbonImmutable $capturedAt,
        CarbonImmutable $cutoffAt,
        CalculationReleaseData $release,
    ): array {
        /** @var Game $game */
        $market = $this->marketSnapshot->capture($game, $capturedAt, $cutoffAt);
        $this->marketCapturedAt = is_string($market['captured_at'] ?? null)
            ? $market['captured_at']
            : null;
        if (($market['available'] ?? false) !== true) {
            $reason = (string) ($market['reason'] ?? 'required_pregame_market_coverage_missing');
            $missing = implode(', ', (array) data_get($market, 'market_coverage.missing', []));

            throw new PredictionLifecycleException(sprintf(
                'NFL canonical generation requires fresh two-sided spread and total quotes (%s%s).',
                $reason,
                $missing === '' ? '' : "; missing: {$missing}",
            ));
        }

        $context = $this->recommendationContext;
        unset($context['captured_at']);

        return ['pregame_market' => $market, 'recommendation_context' => $context];
    }

    /** @return array<string, string|null> */
    protected function additionalSourceTimestamps(SportEvent $event, Model $game): array
    {
        return ['pregame_market' => $this->marketCapturedAt];
    }
}
