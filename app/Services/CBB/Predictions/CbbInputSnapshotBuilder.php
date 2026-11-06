<?php

namespace App\Services\CBB\Predictions;

use App\Actions\CBB\GeneratePrediction;
use App\Application\Predictions\Data\CalculationReleaseData;
use App\Application\Predictions\Data\EventInputSnapshotData;
use App\Exceptions\Predictions\PredictionLifecycleException;
use App\Models\CBB\Game;
use App\Models\CBB\PlayerInjury;
use App\Models\CBB\TeamMetric;
use App\Models\SportEvent;
use App\Services\CBB\CbbPregameEligibility;
use App\Services\Predictions\Basketball\BasketballInputSnapshotBuilder;
use App\Services\Predictions\EstablishedModelTransport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class CbbInputSnapshotBuilder extends BasketballInputSnapshotBuilder
{
    private ?string $marketCapturedAt = null;

    public function build(SportEvent $event, CalculationReleaseData $release): EventInputSnapshotData
    {
        $game = $event->cbbGame()->with(['homeTeam', 'awayTeam'])->first();
        if ($game === null || ! app(CbbPregameEligibility::class)->teamsAreKnown($game->homeTeam, $game->awayTeam)) {
            throw new PredictionLifecycleException('CBB pregame forecasts require known teams; placeholder matchups remain held.');
        }

        $game->setRelation('sportEvent', $event);
        if (data_get($release->configuration, 'forecast.source') === CbbFullModelReleaseDefinition::FORECAST_SOURCE
            && ! app(CbbPregameEligibility::class)->identityMatches($game)) {
            throw new PredictionLifecycleException('CBB canonical kickoff and neutral-site identity must match the linked game.');
        }

        $snapshot = parent::build($event, $release);
        if (data_get($release->configuration['forecast']['configuration']['cbb.prediction'] ?? [], 'score_margin.enabled') === true) {
            $game = $this->withFrozenMarket($game, $snapshot);
        }

        return app(EstablishedModelTransport::class)->capture(
            $snapshot, $release, 'cbb', CbbFullModelReleaseDefinition::INPUT_SCHEMA_VERSION,
            CbbFullModelReleaseDefinition::FORECAST_SOURCE, CbbFullModelReleaseDefinition::configurationKeys(),
            fn () => app(GeneratePrediction::class)->preview($game),
        );
    }

    private function withFrozenMarket(Game $game, EventInputSnapshotData $snapshot): Game
    {
        $game = clone $game;
        $market = (array) data_get($snapshot->inputs, 'pregame_market', []);
        $consensus = (array) ($market['consensus'] ?? []);
        $home = trim($game->homeTeam->school.' '.$game->homeTeam->mascot);
        $away = trim($game->awayTeam->school.' '.$game->awayTeam->mascot);
        $markets = [];
        foreach (['spread' => 'spreads', 'total' => 'totals'] as $kind => $key) {
            $quote = $consensus[$kind] ?? null;
            if (! is_array($quote)) {
                continue;
            }
            $markets[] = ['key' => $key, 'outcomes' => [
                ['name' => $kind === 'spread' ? $home : 'Over', 'point' => $quote['line'], 'price' => $quote['price']],
                ['name' => $kind === 'spread' ? $away : 'Under', 'point' => data_get($quote, 'opposite_quote.line'), 'price' => data_get($quote, 'opposite_quote.price')],
            ]];
        }
        $game->odds_data = ['home_team' => $home, 'away_team' => $away,
            'bookmakers' => [['key' => data_get($consensus, 'spread.bookmaker_key'), 'markets' => $markets]]];
        $observedAt = data_get($consensus, 'spread.freshness_observed_at');
        $game->odds_updated_at = is_string($observedAt) ? CarbonImmutable::parse($observedAt) : null;

        return $game;
    }

    protected function additionalInputs(SportEvent $event, Model $game, CarbonImmutable $capturedAt, CarbonImmutable $cutoffAt, CalculationReleaseData $release): array
    {
        $market = app(CbbPregameMarketSnapshot::class)->capture($game, $capturedAt, $cutoffAt);
        $this->marketCapturedAt = $market['captured_at'] ?? null;

        return ['pregame_market' => $market];
    }

    protected function additionalSourceTimestamps(SportEvent $event, Model $game): array
    {
        return ['pregame_market' => $this->marketCapturedAt];
    }

    protected function sport(): string
    {
        return 'cbb';
    }

    protected function inputSchemaVersion(): string
    {
        return CbbCalculationReleaseDefinition::INPUT_SCHEMA_VERSION;
    }

    protected function gameRelation(): string
    {
        return 'cbbGame';
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

    protected function teamMetricsUseSeasonType(): bool
    {
        return false;
    }
}
