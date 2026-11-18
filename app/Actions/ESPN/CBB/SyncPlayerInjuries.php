<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlayerInjuries;
use App\Models\CBB\Player;
use App\Models\CBB\Team;

class SyncPlayerInjuries extends AbstractSyncPlayerInjuries
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const INJURY_TABLE = 'cbb_player_injuries';

    public function execute(string $teamEspnId): int
    {
        $this->injuryPayloadObserved = false;
        $this->injuryPayloadReliable = false;

        return parent::execute($teamEspnId);
    }

    protected function extractTeamInjuries(string $teamEspnId): array
    {
        $this->injuryPayloadObserved = false;
        $this->injuryPayloadReliable = true;
        $response = $this->espnService->getTeamInjuries($teamEspnId);
        if (is_array($response) && collect(['items', 'injuries', 'entries'])->contains(fn ($key) => isset($response[$key]) && is_array($response[$key]))) {
            $this->injuryPayloadObserved = true;
            $injuries = $this->extractInjuriesFromTeamInjuriesResponse($response);
            $entryCount = collect(['items', 'injuries', 'entries'])->sum(fn ($key) => is_array($response[$key] ?? null) ? count($response[$key]) : 0);
            if (! $this->collectionComplete($response, $entryCount)
                || collect(['items', 'injuries', 'entries'])->contains(fn ($key) => array_key_exists($key, $response) && (! is_array($response[$key]) || ! array_is_list($response[$key])))
                || count($injuries) !== $entryCount || collect($injuries)->contains(fn ($injury) => $this->extractAthleteEspnId($injury) === null)) {
                $this->injuryPayloadReliable = false;
            }
            if ($injuries !== []) {
                return $injuries;
            }
        }
        $roster = $this->espnService->getRoster($teamEspnId);
        if (is_array($roster) && isset($roster['athletes']) && is_array($roster['athletes'])) {
            $this->injuryPayloadObserved = true;
            if (! $this->rosterComplete($roster)) {
                $this->injuryPayloadReliable = false;

                return [];
            }

            return $this->extractInjuriesFromRosterResponse($roster);
        }

        return [];
    }

    private function collectionComplete(array $payload, int $entries): bool
    {
        foreach (['count', 'total', 'totalCount'] as $key) {
            if (array_key_exists($key, $payload) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false || (int) $payload[$key] !== $entries)) {
                return false;
            }
        }
        foreach (['pageCount', 'pageIndex', 'page'] as $key) {
            if (array_key_exists($key, $payload) && (filter_var($payload[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false || (int) $payload[$key] > 1)) {
                return false;
            }
        }
        foreach (['next', 'nextPage', 'nextPageUrl', 'links.next', 'hasMore', 'hasNext', 'hasNextPage'] as $key) {
            if (data_get($payload, $key)) {
                return false;
            }
        }
        foreach ((array) ($payload['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next') {
                return false;
            }
        }

        return true;
    }

    private function rosterComplete(array $roster): bool
    {
        if (! array_is_list($roster['athletes'])) {
            return false;
        }
        $entryCount = 0;
        foreach ($roster['athletes'] as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            $athletes = [$entry];
            if (array_key_exists('items', $entry)) {
                if (! is_array($entry['items']) || ! array_is_list($entry['items']) || ! $this->collectionComplete($entry, count($entry['items']))) {
                    return false;
                }
                $athletes = $entry['items'];
            }
            $entryCount += count($athletes);
            foreach ($athletes as $athlete) {
                if (! is_array($athlete) || $this->extractAthleteIdFromEntity($athlete) === null
                    || (array_key_exists('injuries', $athlete) && (! is_array($athlete['injuries']) || ! array_is_list($athlete['injuries'])))) {
                    return false;
                }
                foreach ($athlete['injuries'] ?? [] as $injury) {
                    if (! is_array($injury) || $injury === []) {
                        return false;
                    }
                }
            }
        }

        return $this->collectionComplete($roster, $entryCount);
    }

    public function lastSyncReliable(): bool
    {
        return $this->injuryPayloadObserved && $this->injuryPayloadReliable;
    }
}
