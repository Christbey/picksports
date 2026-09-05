<?php

namespace App\Services\Sports;

use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Services\Sports\Exceptions\SportEventIdentityConflict;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SportEventIdentitySynchronizer
{
    public function sync(string $sport, Model $game): SportEvent
    {
        $sport = strtolower(trim($sport));
        if ($sport === '' || ! $game->exists) {
            throw new InvalidArgumentException('A sport and persisted game are required.');
        }

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $event = DB::transaction(
                    fn (): SportEvent => $this->syncLocked($sport, $game),
                    attempts: 3,
                );
                $game->setAttribute('sport_event_id', $event->getKey());
                $game->setRelation('sportEvent', $event);

                return $event;
            } catch (QueryException $exception) {
                if ($attempt === 2 || ! in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                    throw $exception;
                }
            }
        }

        throw new SportEventIdentityConflict('Unable to synchronize the sport event identity.');
    }

    private function syncLocked(string $sport, Model $game): SportEvent
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
            $event = SportEvent::query()->create($this->eventAttributes($sport, $game));
        } else {
            $event->forceFill(array_filter(
                $this->eventAttributes($sport, $game),
                static fn (mixed $value): bool => $value !== null,
            ))->saveQuietly();
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
    private function existingMappings(array $references): Collection
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
            ->lockForUpdate()
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
    private function eventAttributes(string $sport, Model $game): array
    {
        return [
            'sport' => $sport,
            'season' => is_numeric($game->getAttribute('season')) ? (int) $game->getAttribute('season') : null,
            'season_type' => $this->stringAttribute($game, 'season_type'),
            'week' => is_numeric($game->getAttribute('week')) ? (int) $game->getAttribute('week') : null,
            'starts_at' => $this->startsAt($game),
            'name' => $this->stringAttribute($game, 'name'),
            'short_name' => $this->stringAttribute($game, 'short_name'),
            'status' => $this->stringAttribute($game, 'status'),
            'neutral_site' => $game->getAttribute('neutral_site') === null
                ? null
                : (bool) $game->getAttribute('neutral_site'),
        ];
    }

    private function startsAt(Model $game): ?CarbonImmutable
    {
        $date = $this->stringAttribute($game, 'game_date');
        $time = $this->stringAttribute($game, 'game_time');

        if ($date === null || $time === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse("{$date} {$time}", 'UTC');
        } catch (\Throwable) {
            return null;
        }
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
