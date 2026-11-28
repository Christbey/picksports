<?php

namespace App\Services\Sports;

use App\Models\MLB\Game;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Services\Relationships\SportRecordResolver;
use App\Services\Sports\Exceptions\SportEventIdentityConflict;
use App\Services\Sports\Exceptions\SportEventIdentityRetry;
use App\Support\CFB\CfbGameStart;
use App\Support\MLB\MlbGameStart;
use App\Support\Sports\GameDateTimePresenter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class SportEventIdentitySynchronizer
{
    public function __construct(private readonly SportRecordResolver $records) {}

    public function sync(string $sport, Model $game, ?bool $reportedNeutralSite = null): SportEvent
    {
        return $this->mutateAndSync($sport, $game, static fn (Model $locked) => null, $reportedNeutralSite);
    }

    /** The callback may perform local writes only; provider work and dispatch belong after return. */
    public function mutateAndSync(string $sport, Model $game, Closure $mutate, ?bool $reportedNeutralSite = null): SportEvent
    {
        $sport = strtolower(trim($sport));
        $this->records->assertGameType($sport, $game);
        if ($sport === '' || ! $game->exists) {
            throw new InvalidArgumentException('A sport and persisted game are required.');
        }
        $connection = $this->coherentConnection($game);
        $inherited = $connection->transactionLevel() > 0;
        $expectedIdentity = $this->primaryIdentity($game);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            // Discovery is only a hint. Current locking reads below must agree before mutation.
            $discovered = $game->newQuery()->findOrFail($game->getKey());
            $candidates = $this->candidateIds($discovered, false);
            try {
                $event = $connection->transaction(function () use ($sport, $game, $mutate, $reportedNeutralSite, $candidates, $expectedIdentity): SportEvent {
                    if (SportEvent::query()->whereKey($candidates)->orderBy('id')->lockForUpdate()->get()->count() !== count($candidates)) {
                        throw new SportEventIdentityConflict('Discovered canonical event is missing.');
                    }
                    $locked = $game->newQuery()->lockForUpdate()->findOrFail($game->getKey());
                    $this->assertPrimaryIdentity($expectedIdentity, $locked);
                    if ($this->candidateIds($locked, true) !== $candidates) {
                        throw new SportEventIdentityRetry('Canonical identity changed during discovery; retry the owning transaction.');
                    }
                    $beforeIdentity = $this->primaryIdentity($locked);
                    $mutate($locked);
                    $locked = $game->newQuery()->lockForUpdate()->findOrFail($game->getKey());
                    $this->assertPrimaryIdentity($beforeIdentity, $locked);
                    // The callback cannot redirect identity to an event we did not lock first.
                    if ($this->candidateIds($locked, true) !== $candidates) {
                        throw new SportEventIdentityConflict('Local mutation cannot change canonical provider identity.');
                    }

                    return $this->syncLocked($sport, $locked, $reportedNeutralSite);
                });
                $game->setAttribute('sport_event_id', $event->getKey());
                $game->setRelation('sportEvent', $event);

                return $event;
            } catch (SportEventIdentityRetry|QueryException $exception) {
                $retryable = $exception instanceof SportEventIdentityRetry
                    || in_array((string) $exception->getCode(), ['19', '23000', '40001'], true);
                if ($inherited || ! $retryable || $attempt === 3) {
                    throw $exception;
                }
            }
        }
        throw new SportEventIdentityConflict('Unable to synchronize the sport event identity.');
    }

    /** A concurrent create collision refuses rather than applying stale attributes to its winner. */
    public function createAndSync(string $sport, string $gameClass, array $attributes, ?bool $reportedNeutralSite = null): Model
    {
        $prototype = new $gameClass;
        $this->records->assertGameType($sport, $prototype);
        $connection = $this->coherentConnection($prototype);
        $prototype->fill($attributes);
        $candidates = $this->candidateIds($prototype, false);

        return $connection->transaction(function () use ($sport, $gameClass, $attributes, $reportedNeutralSite, $candidates): Model {
            if (SportEvent::query()->whereKey($candidates)->orderBy('id')->lockForUpdate()->get()->count() !== count($candidates)) {
                throw new SportEventIdentityConflict('Discovered canonical event is missing.');
            }
            // This instance is local to this attempt and never escapes on rollback.
            $game = new $gameClass;
            $game->fill($game instanceof Game ? MlbGameStart::prepare($game, $attributes) : $attributes);
            if ($this->candidateIds($game, true) !== $candidates) {
                throw new SportEventIdentityRetry('Provider identity changed before create; retry the owning operation.');
            }
            $game->save();
            $event = $this->syncLocked($sport, $game, $reportedNeutralSite);
            $game->setAttribute('sport_event_id', $event->getKey());
            $game->setRelation('sportEvent', $event);

            return $game;
        });
    }

    private function coherentConnection(Model $game): Connection
    {
        $connection = $game->getConnection();
        foreach ([new SportEvent, new SportEventProviderMapping] as $model) {
            if ($model->getConnection()->getName() !== $connection->getName()) {
                throw new SportEventIdentityConflict('Canonical identity models must share one database connection.');
            }
        }

        return $connection;
    }

    private function primaryIdentity(Model $game): array
    {
        $identity = [];
        foreach (['espn_event_id', 'odds_api_event_id', 'nflverse_game_id'] as $field) {
            $value = $game->getAttribute($field);
            if ($value !== null && trim((string) $value) !== '') {
                $identity[$field] = (string) $value;
            }
        }

        return $identity;
    }

    private function assertPrimaryIdentity(array $expected, Model $game): void
    {
        foreach ($expected as $field => $value) {
            if ((string) $game->getAttribute($field) !== $value) {
                throw new SportEventIdentityRetry('Primary provider identity changed; retry the owning operation.');
            }
        }
    }

    /** @return list<int> */
    private function candidateIds(Model $game, bool $locking): array
    {
        $references = $this->providerReferences($game);
        $ids = $references === [] ? [] : $this->existingMappings($references, $locking)->pluck('sport_event_id')->all();
        if ($game->getAttribute('sport_event_id') !== null) {
            $ids[] = $game->getAttribute('sport_event_id');
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function syncLocked(string $sport, Model $game, ?bool $reportedNeutralSite): SportEvent
    {
        $game = $game->newQuery()->lockForUpdate()->findOrFail($game->getKey());
        $references = $this->providerReferences($game);

        if ($references === []) {
            throw new InvalidArgumentException('The game has no supported provider identity.');
        }

        $mappings = $this->existingMappings($references);
        $mappedEventIds = $mappings->pluck('sport_event_id')->unique()->values();
        $linkedEventId = $game->getAttribute('sport_event_id');

        if ($mappedEventIds->count() > 1
            || ($linkedEventId !== null && $mappedEventIds->isNotEmpty() && ! $mappedEventIds->contains($linkedEventId))) {
            throw new SportEventIdentityConflict('Provider identities resolve to conflicting canonical events.');
        }

        $event = $linkedEventId !== null
            ? SportEvent::query()->lockForUpdate()->find($linkedEventId)
            : ($mappedEventIds->isNotEmpty()
                ? SportEvent::query()->lockForUpdate()->find($mappedEventIds->first())
                : null);

        if ($event !== null && $event->sport !== $sport) {
            throw new SportEventIdentityConflict('The canonical event belongs to a different sport.');
        }

        if ($linkedEventId === null
            && $event !== null
            && $game->newQuery()
                ->where('sport_event_id', $event->getKey())
                ->where($game->getKeyName(), '!=', $game->getKey())
                ->exists()) {
            throw new SportEventIdentityConflict('The canonical event is already linked to another sport detail row.');
        }

        if ($event === null) {
            $event = SportEvent::query()->create($this->eventAttributes($sport, $game, $reportedNeutralSite));
        } else {
            $attributes = array_filter($this->eventAttributes($sport, $game, $reportedNeutralSite),
                static fn (mixed $value): bool => $value !== null);
            if ($game instanceof \App\Models\CFB\Game && $game->getRawOriginal('kickoff_observation') !== null
                && CfbGameStart::for($game) === null) {
                // An explicit current CFB unknown observation invalidates its old canonical instant.
                $attributes['starts_at'] = null;
            }
            $event->forceFill($attributes)->saveQuietly();
        }

        foreach ($references as $reference) {
            $mapping = $mappings->first(fn (SportEventProviderMapping $mapping): bool => $mapping->provider === $reference['provider']
                && $mapping->provider_event_id === $reference['provider_event_id']
            );

            if ($mapping !== null) {
                if ($mapping->sport_event_id !== $event->getKey()) {
                    throw new SportEventIdentityConflict('A provider identity is linked to another canonical event.');
                }

                if ($mapping->provider_uid !== null
                    && $reference['provider_uid'] !== null
                    && $mapping->provider_uid !== $reference['provider_uid']) {
                    throw new SportEventIdentityConflict('A provider UID conflicts with its existing event mapping.');
                }

                if ($mapping->provider_uid === null && $reference['provider_uid'] !== null) {
                    $mapping->forceFill(['provider_uid' => $reference['provider_uid']])->saveQuietly();
                }

                continue;
            }

            $uidMapping = $reference['provider_uid'] === null
                ? null
                : $mappings->first(fn (SportEventProviderMapping $mapping): bool => $mapping->provider === $reference['provider']
                    && $mapping->provider_uid === $reference['provider_uid']
                );

            if ($uidMapping !== null) {
                throw new SportEventIdentityConflict('A provider UID is already assigned to another event ID.');
            }

            $event->providerMappings()->create($reference);
        }

        if ($linkedEventId === null) {
            $game->forceFill(['sport_event_id' => $event->getKey()])->saveQuietly();
        }

        return $event->fresh('providerMappings');
    }

    /**
     * @param  list<array{provider:string,provider_event_id:string,provider_uid:?string}>  $references
     * @return Collection<int, SportEventProviderMapping>
     */
    private function existingMappings(array $references, bool $locking = true): Collection
    {
        return SportEventProviderMapping::query()
            ->where(function (Builder $query) use ($references): void {
                foreach ($references as $reference) {
                    $query->orWhere(function (Builder $query) use ($reference): void {
                        $query->where('provider', $reference['provider'])
                            ->where(function (Builder $query) use ($reference): void {
                                $query->where('provider_event_id', $reference['provider_event_id']);

                                if ($reference['provider_uid'] !== null) {
                                    $query->orWhere('provider_uid', $reference['provider_uid']);
                                }
                            });
                    });
                }
            })
            ->when($locking, fn (Builder $query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * @return list<array{provider:string,provider_event_id:string,provider_uid:?string}>
     */
    private function providerReferences(Model $game): array
    {
        $references = [];
        $espnEventId = $this->stringAttribute($game, 'espn_event_id');

        if ($espnEventId !== null && ! str_starts_with($espnEventId, 'nflverse:')) {
            $references[] = [
                'provider' => 'espn',
                'provider_event_id' => $espnEventId,
                'provider_uid' => $this->stringAttribute($game, 'espn_uid'),
            ];
        }

        $oddsApiEventId = $this->stringAttribute($game, 'odds_api_event_id');
        if ($oddsApiEventId !== null && ! str_starts_with($oddsApiEventId, 'nflverse:')) {
            $references[] = [
                'provider' => 'odds_api',
                'provider_event_id' => $oddsApiEventId,
                'provider_uid' => null,
            ];
        }

        $nflverseGameId = $this->stringAttribute($game, 'nflverse_game_id');
        if ($nflverseGameId !== null) {
            $references[] = [
                'provider' => 'nflverse',
                'provider_event_id' => $nflverseGameId,
                'provider_uid' => null,
            ];
        }

        return $references;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventAttributes(string $sport, Model $game, ?bool $reportedNeutralSite): array
    {
        $neutralSite = $game->getAttribute('neutral_site') ?? $reportedNeutralSite;

        $attributes = [
            'sport' => $sport,
            'season' => is_numeric($game->getAttribute('season')) ? (int) $game->getAttribute('season') : null,
            'season_type' => $this->stringAttribute($game, 'season_type'),
            'week' => is_numeric($game->getAttribute('week')) ? (int) $game->getAttribute('week') : null,
            'starts_at' => $this->startsAt($game),
            'name' => $this->stringAttribute($game, 'name'),
            'short_name' => $this->stringAttribute($game, 'short_name'),
            'status' => $this->stringAttribute($game, 'status'),
            'neutral_site' => $neutralSite === null ? null : (bool) $neutralSite,
        ];
        if ($game instanceof Game && MlbGameStart::for($game) === null) {
            // Unobserved legacy time is unavailable evidence, not an instruction to erase stored facts.
            unset($attributes['starts_at']);
        }

        return $attributes;
    }

    private function startsAt(Model $game): ?CarbonImmutable
    {
        if ($game instanceof Game) {
            return MlbGameStart::canonical($game);
        }

        $start = GameDateTimePresenter::currentStoredStartsAt($game);

        return $start === null ? null : CarbonImmutable::parse($start, 'UTC')
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    private function stringAttribute(Model $model, string $attribute): ?string
    {
        $value = $model->getAttribute($attribute);
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
