<?php

namespace App\Services\CFB\Data;

use App\Actions\ESPN\CFB\SyncPlayerStats;
use App\Actions\ESPN\CFB\SyncTeamStats;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\PlayerStat;
use App\Models\CFB\TeamStat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Reconciles a quarantined team total only against an exact reviewed school gamebook. */
class CfbOfficialBoxscoreRepair
{
    public const VERSION = 'school-box-v1';

    public function hasReference(Game $game): bool
    {
        return is_array(config('cfb_official_sources.boxscores.'.$game->espn_event_id));
    }

    public function execute(Game $game): array
    {
        $spec = config('cfb_official_sources.boxscores.'.$game->espn_event_id);
        if (! is_array($spec)) {
            throw new RuntimeException('No reviewed official gamebook is configured for this event.');
        }
        $game->loadMissing(['homeTeam', 'awayTeam']);
        $this->assertIdentity($game, $spec);
        $primary = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')
            ->where('validator_version', CfbGameDataValidator::VERSION)->latest('updated_at')->latest('id')->firstOrFail();
        if ($primary->state === 'complete' || collect($primary->discrepancies)->contains(fn ($error) => ! str_ends_with($error, 'team_yards_do_not_reconcile'))) {
            throw new RuntimeException('Official repair is limited to independently verified team-yard inconsistencies.');
        }
        $disk = Storage::disk(config('cfb.data.source_disk', 'local'));
        $json = $disk->get($primary->source_path);
        if (! is_string($json) || ! hash_equals($primary->source_hash, hash('sha256', $json))) {
            throw new RuntimeException('Primary boxscore archive hash mismatch.');
        }
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if ((string) data_get($payload, 'header.id') !== (string) $game->espn_event_id
            || data_get($payload, 'header.competitions.0.status.type.name') !== 'STATUS_FINAL') {
            throw new RuntimeException('Primary summary does not identify the matching final event.');
        }
        $reference = app(CfbOfficialGamebookSource::class)->archive($game, $spec);
        $path = $reference['path'];
        foreach (['home', 'away'] as $side) {
            $team = $game->{$side.'Team'};
            $competitor = collect(data_get($payload, 'header.competitions.0.competitors', []))->firstWhere('homeAway', $side);
            if ((string) data_get($competitor, 'team.id') !== (string) $team->espn_id
                || (int) ($competitor['score'] ?? -1) !== $spec[$side.'_score']) {
                throw new RuntimeException('Official and primary final team identities or scores disagree.');
            }
            $observed = $spec['stats'][$side];
            if ($observed['totalYards'] !== $observed['netPassingYards'] + $observed['rushingYards']) {
                throw new RuntimeException('Reviewed official yard values do not reconcile.');
            }
            $found = false;
            foreach ($payload['boxscore']['teams'] as &$row) {
                if ((string) data_get($row, 'team.id') !== (string) $team->espn_id) {
                    continue;
                }
                $found = true;
                foreach ($observed as $name => $value) {
                    $matches = 0;
                    foreach ($row['statistics'] as &$stat) {
                        if ($stat['name'] === $name) {
                            $stat['displayValue'] = (string) $value;
                            if (isset($stat['value']) && is_numeric($stat['value'])) {
                                $stat['value'] = $value;
                            }
                            $matches++;
                        }
                    }
                    unset($stat);
                    if ($matches !== 1) {
                        throw new RuntimeException('Primary team field is missing or duplicated.');
                    }
                }
            }
            unset($row);
            if (! $found) {
                throw new RuntimeException('Primary boxscore is missing a game team.');
            }
        }
        $identity = $game->only(['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $payload['_reconciliation'] = ['primary_source_hash' => $primary->source_hash,
            'official_source_hash' => $spec['sha256'], 'official_source_path' => $path, 'official_source_url' => $spec['url'],
            'official_reference' => $spec['reference'], 'observed_team_stats' => $spec['stats'], 'identity' => $identity];
        $validation = app(CfbGameDataValidator::class)->boxscore($payload, $game);
        if ($validation['state'] !== 'complete') {
            throw new RuntimeException('Official totals do not reconcile the primary player boxscores: '.implode(', ', $validation['discrepancies']));
        }
        $validation['evidence'] = [...$validation['evidence'], ...$payload['_reconciliation']];
        $check = app(CfbSourceRevision::class)->record($game, 'boxscore', $payload, $validation,
            provider: 'school_official_espn', validatorVersion: self::VERSION);

        return DB::transaction(function () use ($game, $spec, $payload, $primary, $check) {
            $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $this->assertIdentity($locked, $spec);
            $current = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')
                ->where('validator_version', CfbGameDataValidator::VERSION)->latest('updated_at')->latest('id')->first();
            if ($current?->source_hash !== $primary->source_hash) {
                throw new RuntimeException('Primary source changed during official reconciliation.');
            }
            $players = app(SyncPlayerStats::class)->execute($payload, $locked, recordSource: false);
            $teams = app(SyncTeamStats::class)->execute($payload, $locked);
            if ($players === 0 || $teams !== 2) {
                throw new RuntimeException('Official reconciliation did not import both teams and the player boxscores.');
            }
            $stats = TeamStat::where('game_id', $game->id)->get(['team_id', 'total_yards', 'passing_yards', 'rushing_yards'])->keyBy('team_id')->toArray();
            if (count($stats) !== 2) {
                throw new RuntimeException('Unexpected team rows remain after official reconciliation.');
            }
            $check->update(['accepted_at' => now(), 'evidence' => [...$check->evidence,
                'team_stats' => $stats, 'player_fingerprint' => $this->playerFingerprint($game)]]);

            return ['state' => 'complete', 'game_id' => $game->id, 'provider' => 'school_official_espn',
                'source_hash' => $check->source_hash, 'official_source_url' => $spec['url'], 'player_stats' => $players];
        });
    }

    public function currentProof(Game $game, ?GameDataCheck $primary): ?GameDataCheck
    {
        if ($game->status !== 'STATUS_FINAL') {
            return null;
        }
        $check = GameDataCheck::where('game_id', $game->id)->where('component', 'boxscore')->where('validator_version', self::VERSION)
            ->where('state', 'complete')->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
        $spec = config('cfb_official_sources.boxscores.'.$game->espn_event_id);
        if (! is_array($spec) || ! $check || data_get($check->evidence, 'official_source_hash') !== $spec['sha256']
            || data_get($check->evidence, 'primary_source_hash') !== $primary?->source_hash) {
            return null;
        }
        foreach (['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
            if ((string) data_get($check->evidence, 'identity.'.$field) !== (string) $game->{$field}) {
                return null;
            }
        }
        $stats = data_get($check->evidence, 'team_stats', []);
        if (count($stats) !== 2) {
            return null;
        }
        foreach ($stats as $teamId => $expected) {
            $actual = TeamStat::where('game_id', $game->id)->where('team_id', $teamId)->first();
            foreach ($expected as $field => $value) {
                if (! $actual || (string) $actual->{$field} !== (string) $value) {
                    return null;
                }
            }
        }

        return hash_equals((string) data_get($check->evidence, 'player_fingerprint'), $this->playerFingerprint($game)) ? $check : null;
    }

    private function playerFingerprint(Game $game): string
    {
        return hash('sha256', json_encode(PlayerStat::where('game_id', $game->id)->orderBy('player_id')
            ->get((new PlayerStat)->getFillable())->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function assertIdentity(Game $game, array $spec): void
    {
        app(CfbOfficialGamebookSource::class)->assertIdentity($game, $spec);
    }
}
