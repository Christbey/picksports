<?php

namespace App\Actions\ESPN\CBB;

use App\Actions\ESPN\AbstractSyncPlayers;
use App\DataTransferObjects\ESPN\CollegePlayerData;
use App\Models\CBB\Player;
use App\Models\CBB\RosterSnapshot;
use App\Models\CBB\Team;
use App\Services\ESPN\CBB\EspnService;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Services\SportsAssetStorage;
use App\Support\BasketballSeason;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class SyncPlayers extends AbstractSyncPlayers
{
    protected const PLAYER_MODEL_CLASS = Player::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAYER_DTO_CLASS = CollegePlayerData::class;

    public function __construct(EspnService $espnService, ?SportsAssetStorage $sportsAssetStorage = null)
    {
        parent::__construct($espnService, $sportsAssetStorage);
    }

    public function execute(string $teamEspnId, ?int $season = null): int
    {
        $season ??= BasketballSeason::forDate();
        $team = Team::query()->where('espn_id', $teamEspnId)->firstOrFail();
        $response = $this->espnService->getRosterForSeason($teamEspnId, $season);
        if ($response === null || ($response['athletes'] ?? []) === []) {
            return 0;
        }
        $athletes = $this->extractAthletes($response);
        $ids = array_map(fn (array $athlete): string => (string) ($athlete['id'] ?? ''), $athletes);
        if ((int) data_get($response, 'season.year') !== $season || (string) data_get($response, 'team.id') !== $teamEspnId
            || count($ids) < 5 || count(array_unique($ids)) !== count($ids)
            || collect($ids)->contains(fn (string $id): bool => ! ctype_digit($id) || (int) $id <= 0)
            || (int) ($response['count'] ?? count($ids)) > count($ids) || ! empty($response['nextPage'])) {
            throw new UnexpectedValueException('CBB roster season, team, membership or completeness validation failed.');
        }
        $profiles = collect($athletes)->map(fn (array $athlete): array => CollegePlayerData::fromEspnResponse($athlete)->toArray())->sortBy('espn_id')->values();
        if ($profiles->contains(fn (array $profile): bool => trim($profile['full_name']) === '')) {
            throw new UnexpectedValueException('CBB roster contains an unidentified athlete.');
        }
        $observedAt = now()->toImmutable();

        return DB::transaction(function () use ($team, $season, $profiles, $response, $observedAt): int {
            Team::query()->whereKey($team->id)->lockForUpdate()->firstOrFail();
            $latest = RosterSnapshot::knownAt($team->id, $season, $observedAt);
            if ($latest && $profiles->count() < ceil($latest->entries()->count() / 2)) {
                throw new UnexpectedValueException('CBB roster unexpectedly lost more than half its members; previous observation retained.');
            }
            $hash = app(CanonicalPayloadHasher::class)->hash(['team_id' => $team->id, 'season' => $season, 'profiles' => $profiles->all()]);
            $snapshot = RosterSnapshot::query()->firstOrCreate(['team_id' => $team->id, 'season' => $season, 'observed_at' => $observedAt, 'content_hash' => $hash], ['source' => 'espn_season_roster', 'provider_metadata' => ['season' => $response['season'], 'team_id' => (string) data_get($response, 'team.id'), 'timestamp' => $response['timestamp'] ?? null, 'athlete_count' => $profiles->count()]]);
            if (! $snapshot->wasRecentlyCreated) {
                return $profiles->count();
            }
            foreach ($profiles as $profile) {
                $memberProfile = $profile;
                $player = Player::query()->where('espn_id', $profile['espn_id'])->first();
                if ($season === BasketballSeason::forDate()) {
                    $profile['headshot_url'] = $player?->headshot_url ?: $profile['headshot_url'];
                    $player = Player::query()->updateOrCreate(['espn_id' => $profile['espn_id']], [...$profile, 'team_id' => $team->id]);
                } else {
                    $player ??= Player::query()->create([...$profile, 'team_id' => $team->id]);
                }
                $snapshot->entries()->create(['player_id' => $player->id, 'attributes' => $memberProfile]);
            }

            return $profiles->count();
        });
    }
}
