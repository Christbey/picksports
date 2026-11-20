<?php

namespace App\Services\Relationships;

use App\Enums\PredictionSport;
use App\Models\CanonicalPrediction;
use App\Models\NBA\Game;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Models\UserBet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Identity resolution only: publication eligibility remains the owning reader's responsibility. */
final class SportRecordResolver
{
    /** @var array<string, class-string<Model>> */
    private const GAME_MODELS = [
        'nba' => Game::class,
        'wnba' => \App\Models\WNBA\Game::class,
        'nfl' => \App\Models\NFL\Game::class,
        'mlb' => \App\Models\MLB\Game::class,
        'cfb' => \App\Models\CFB\Game::class,
        'cbb' => \App\Models\CBB\Game::class,
        'wcbb' => \App\Models\WCBB\Game::class,
    ];

    public function game(string $sport, string $table, int|string $id): ?Model
    {
        $class = self::GAME_MODELS[$sport] ?? throw new InvalidArgumentException('Unsupported game sport.');
        $this->assertTable($class, $table);
        $this->assertInternalId($id);

        return $class::query()->where(fn ($query) => $query->whereNull('sport_event_id')
            ->orWhereHas('sportEvent', fn ($event) => $event->where('sport', $sport)))->find($id);
    }

    public function assertGameType(string $sport, Model $game): void
    {
        $expected = self::GAME_MODELS[$sport] ?? null;
        if ($expected === null || ! $game instanceof $expected || $game->getTable() !== (new $expected)->getTable()) {
            throw new InvalidArgumentException('Game model and sport do not match.');
        }
    }

    public function eventForProvider(string $sport, string $provider, string $eventId): ?SportEvent
    {
        if (PredictionSport::tryFrom($sport) === null || ! in_array($provider, ['espn', 'odds_api', 'nflverse'], true)) {
            throw new InvalidArgumentException('Unsupported sport or provider identity.');
        }
        $matches = SportEventProviderMapping::query()->where('provider', $provider)
            ->where('provider_event_id', $eventId)->with('sportEvent')->limit(2)->get();
        if ($matches->count() !== 1) {
            return null;
        }
        $event = $matches->first()->sportEvent;

        return $event?->sport === $sport ? $event : null;
    }

    public function legacyDetail(CanonicalPrediction $prediction): ?Model
    {
        if ($prediction->detail_source === null) {
            return null;
        }
        if ($prediction->detail_source !== CanonicalPrediction::DETAIL_SOURCE_LEGACY_SPORT_PREDICTION
            || $prediction->detail_sport !== $prediction->sport || $prediction->detail_id === null) {
            throw new InvalidArgumentException('Canonical legacy-detail identity is inconsistent.');
        }
        $sport = PredictionSport::tryFrom($prediction->sport) ?? throw new InvalidArgumentException('Unsupported prediction sport.');
        $class = $sport->predictionModelClass();

        $legacy = $this->prediction($sport->value, (new $class)->getTable(), $prediction->detail_id);
        $game = $legacy?->loadMissing('game:id,sport_event_id')->getRelation('game');

        return $game !== null && (int) $game->sport_event_id === (int) $prediction->sport_event_id ? $legacy : null;
    }

    public function gameReference(Model $reference): ?Model
    {
        $attributes = $reference->getAttributes();
        if (($attributes['game_id'] ?? null) === null || ($attributes['game_table'] ?? null) === null) {
            return null;
        }

        return $this->game((string) $reference->getAttribute('sport'), (string) $reference->getAttribute('game_table'), $reference->getAttribute('game_id'));
    }

    public function predictionReference(Model $reference): ?Model
    {
        $attributes = $reference->getAttributes();
        $sport = (string) $reference->getAttribute('sport');
        if (($attributes['canonical_prediction_id'] ?? null) !== null) {
            return $this->prediction($sport, 'predictions', $reference->getAttribute('canonical_prediction_id'));
        }
        if (($attributes['prediction_id'] ?? null) === null || ($attributes['prediction_table'] ?? null) === null) {
            return null;
        }

        return $this->prediction($sport, (string) $reference->getAttribute('prediction_table'), $reference->getAttribute('prediction_id'));
    }

    public function predictionSource(string $sport, ?string $type, int|string|null $id): ?Model
    {
        $class = UserBet::supportedPredictionModel($type);
        if ($class === null || $id === null) {
            return null;
        }
        if ($class !== CanonicalPrediction::class && PredictionSport::fromLegacyModelClass($class)?->value !== $sport) {
            throw new InvalidArgumentException('Forecast source type and sport do not match.');
        }

        return $this->prediction($sport, (new $class)->getTable(), $id);
    }

    public function prediction(string $sport, string $table, int|string $id): ?Model
    {
        $predictionSport = PredictionSport::tryFrom($sport) ?? throw new InvalidArgumentException('Unsupported prediction sport.');
        $this->assertInternalId($id);
        if ($table === (new CanonicalPrediction)->getTable()) {
            return CanonicalPrediction::query()->where('sport', $sport)
                ->whereHas('sportEvent', fn ($event) => $event->where('sport', $sport))->find($id);
        }
        $class = $predictionSport->predictionModelClass();
        $this->assertTable($class, $table);

        return $class::query()->find($id);
    }

    public function publicPrediction(string $sport, string $publicId): ?CanonicalPrediction
    {
        if (PredictionSport::tryFrom($sport) === null || ! Str::isUlid($publicId)) {
            throw new InvalidArgumentException('A supported sport and canonical public ULID are required.');
        }

        return CanonicalPrediction::query()->where('sport', $sport)->where('public_id', $publicId)
            ->whereHas('sportEvent', fn ($event) => $event->where('sport', $sport))->first();
    }

    /** @param class-string<Model> $class */
    private function assertTable(string $class, string $table): void
    {
        if ((new $class)->getTable() !== $table) {
            throw new InvalidArgumentException('Sport and record table do not match.');
        }
    }

    private function assertInternalId(int|string $id): void
    {
        if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new InvalidArgumentException('An internal numeric record ID is required; public IDs must use an explicit public lookup.');
        }
    }
}
