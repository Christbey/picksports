<?php

namespace App\Services\Sports;

use App\Models\CBB\Game as CbbGame;
use App\Models\CFB\Game as CfbGame;
use App\Models\MLB\Game as MlbGame;
use App\Models\NBA\Game as NbaGame;
use App\Models\NFL\Game as NflGame;
use App\Models\SportEvent;
use App\Models\SportEventProviderMapping;
use App\Models\WCBB\Game as WcbbGame;
use App\Models\WNBA\Game as WnbaGame;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SportEventIdentityBackfill
{
    /**
     * @var array<string, class-string<Model>>
     */
    private const GAME_MODELS = [
        'cbb' => CbbGame::class,
        'cfb' => CfbGame::class,
        'mlb' => MlbGame::class,
        'nba' => NbaGame::class,
        'nfl' => NflGame::class,
        'wcbb' => WcbbGame::class,
        'wnba' => WnbaGame::class,
    ];

    /**
     * @param  list<string>  $sports
     * @return array{games_scanned:int,events_created:int,mappings_created:int,games_linked:int,already_linked:int,conflicts:int}
     */
    public function backfill(array $sports, int $chunkSize = 500, int $limit = 0, bool $dryRun = false): array
    {
        $report = [
            'games_scanned' => 0,
            'events_created' => 0,
            'mappings_created' => 0,
            'games_linked' => 0,
            'already_linked' => 0,
            'conflicts' => 0,
        ];
        $dryRunClaims = [];

        foreach ($sports as $sport) {
            $modelClass = self::GAME_MODELS[$sport];
            $remaining = $limit > 0 ? $limit - $report['games_scanned'] : null;

            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $query = $modelClass::query()
                ->whereNotNull('espn_event_id')
                ->orderBy('id');

            if ($remaining !== null) {
                $query->limit($remaining);
            }

            $query->chunkById($chunkSize, function (Collection $games) use (
                $sport,
                $dryRun,
                $limit,
                &$dryRunClaims,
                &$report,
            ): bool {
                foreach ($games as $game) {
                    if ($limit > 0 && $report['games_scanned'] >= $limit) {
                        return false;
                    }

                    $result = $dryRun
                        ? $this->inspectGame($sport, $game, $dryRunClaims)
                        : DB::transaction(
                            fn (): array => $this->backfillGame($sport, $game),
                            attempts: 3,
                        );

                    $report['games_scanned']++;
                    foreach ($result as $key => $amount) {
                        $report[$key] += $amount;
                    }
                }

                return true;
            });
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    public function supportedSports(): array
    {
        return array_keys(self::GAME_MODELS);
    }

    /**
     * @return array{events_created:int,mappings_created:int,games_linked:int,already_linked:int,conflicts:int}
     */
    private function backfillGame(string $sport, Model $game): array
    {
        $game = $game->newQuery()->lockForUpdate()->findOrFail($game->getKey());

        return $this->resolveGame($sport, $game, false);
    }

    /**
     * @return array{events_created:int,mappings_created:int,games_linked:int,already_linked:int,conflicts:int}
     */
    private function inspectGame(string $sport, Model $game, array &$claims): array
    {
        $references = $this->providerReferences($game);
        $owner = $sport.':'.$game->getTable().':'.$game->getKey();

        foreach ($this->claimKeys($references) as $key) {
            if (isset($claims[$key]) && $claims[$key] !== $owner) {
                return $this->conflictResult();
            }
        }

        $result = $this->resolveGame($sport, $game, true);

        if ($result['conflicts'] === 0) {
            foreach ($this->claimKeys($references) as $key) {
                $claims[$key] = $owner;
            }
        }

        return $result;
    }

    /**
     * @return array{events_created:int,mappings_created:int,games_linked:int,already_linked:int,conflicts:int}
     */
    private function resolveGame(string $sport, Model $game, bool $dryRun): array
    {
        $result = [
            'events_created' => 0,
            'mappings_created' => 0,
            'games_linked' => 0,
            'already_linked' => 0,
            'conflicts' => 0,
        ];
        $references = $this->providerReferences($game);
        $mappings = $this->existingMappings($references, ! $dryRun);
        $mappedEventIds = $mappings->pluck('sport_event_id')->unique()->values();
        $linkedEventId = $game->getAttribute('sport_event_id');
        $uidCollision = $mappings->contains(function (SportEventProviderMapping $mapping) use ($references): bool {
            foreach ($references as $reference) {
                if ($reference['provider_uid'] !== null
                    && $mapping->provider === $reference['provider']
                    && $mapping->provider_uid === $reference['provider_uid']
                    && $mapping->provider_event_id !== $reference['provider_event_id']) {
                    return true;
                }
            }

            return false;
        });

        if ($uidCollision
            || $mappedEventIds->count() > 1
            || ($linkedEventId !== null && $mappedEventIds->isNotEmpty() && ! $mappedEventIds->contains($linkedEventId))) {
            $result['conflicts']++;

            return $result;
        }

        $event = $linkedEventId !== null
            ? SportEvent::query()->find($linkedEventId)
            : ($mappedEventIds->isNotEmpty() ? SportEvent::query()->find($mappedEventIds->first()) : null);

        if (($linkedEventId !== null && $event === null) || ($event !== null && $event->sport !== $sport)) {
            $result['conflicts']++;

            return $result;
        }

        if ($linkedEventId === null
            && $event !== null
            && $game->newQuery()
                ->where('sport_event_id', $event->getKey())
                ->where($game->getKeyName(), '!=', $game->getKey())
                ->exists()) {
            $result['conflicts']++;

            return $result;
        }

        if ($event === null) {
            $result['events_created']++;

            if (! $dryRun) {
                $event = SportEvent::query()->create($this->eventAttributes($sport, $game));
            }
        }

        foreach ($references as $reference) {
            $mapping = $mappings->first(fn (SportEventProviderMapping $mapping): bool => $mapping->provider === $reference['provider']
                && $mapping->provider_event_id === $reference['provider_event_id']
            );

            if ($mapping !== null) {
                if ($event !== null && $mapping->sport_event_id !== $event->getKey()) {
                    $result['conflicts']++;

                    return $result;
                }

                if (! $dryRun && $mapping->provider_uid === null && $reference['provider_uid'] !== null) {
                    $mapping->update(['provider_uid' => $reference['provider_uid']]);
                }

                continue;
            }

            $result['mappings_created']++;

            if (! $dryRun) {
                $event->providerMappings()->create($reference);
            }
        }

        if ($linkedEventId === null) {
            $result['games_linked']++;

            if (! $dryRun) {
                $game->forceFill(['sport_event_id' => $event->getKey()])->saveQuietly();
            }
        } else {
            $result['already_linked']++;
        }

        return $result;
    }

    /**
     * @param  list<array{provider:string,provider_event_id:string,provider_uid:?string}>  $references
     * @return Collection<int, SportEventProviderMapping>
     */
    private function existingMappings(array $references, bool $lock): Collection
    {
        $query = SportEventProviderMapping::query()
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
            });

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
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

        foreach (['odds_api' => 'odds_api_event_id', 'nflverse' => 'nflverse_game_id'] as $provider => $column) {
            $providerEventId = $this->stringAttribute($game, $column);
            if ($providerEventId === null || ($provider === 'odds_api' && str_starts_with($providerEventId, 'nflverse:'))) {
                continue;
            }

            $references[] = [
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'provider_uid' => null,
            ];
        }

        return $references;
    }

    /**
     * @param  list<array{provider:string,provider_event_id:string,provider_uid:?string}>  $references
     * @return list<string>
     */
    private function claimKeys(array $references): array
    {
        $keys = [];

        foreach ($references as $reference) {
            $keys[] = "event:{$reference['provider']}:{$reference['provider_event_id']}";

            if ($reference['provider_uid'] !== null) {
                $keys[] = "uid:{$reference['provider']}:{$reference['provider_uid']}";
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array{events_created:int,mappings_created:int,games_linked:int,already_linked:int,conflicts:int}
     */
    private function conflictResult(): array
    {
        return [
            'events_created' => 0,
            'mappings_created' => 0,
            'games_linked' => 0,
            'already_linked' => 0,
            'conflicts' => 1,
        ];
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
        $dateValue = $game->getAttribute('game_date');
        $date = $dateValue instanceof DateTimeInterface
            ? $dateValue->format('Y-m-d')
            : $this->stringAttribute($game, 'game_date');
        $time = $this->stringAttribute($game, 'game_time');

        if ($date === null || $time === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse("{$date} {$time}", 'UTC')
                ->setTimezone((string) config('app.timezone', 'UTC'));
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

        return $value !== '' ? $value : null;
    }
}
