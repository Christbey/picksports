<?php

namespace App\Services\CFB\Data;

use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\Play;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Completes an exact missing non-scoring closing possession from a reviewed school gamebook. */
class CfbOfficialPlayTailRepair
{
    public const VERSION = 'school-tail-v1';

    private const RAW_FIELDS = ['espn_play_id', 'sequence_number', 'period', 'clock', 'play_type', 'play_text',
        'down', 'distance', 'yards_to_endzone', 'yards_gained', 'home_score', 'away_score', 'possession_team_id', 'source_revision', 'source_state'];

    public function hasReference(Game $game): bool
    {
        return is_array(config('cfb_official_sources.play_tails.'.$game->espn_event_id));
    }

    public function execute(Game $game): array
    {
        $spec = config('cfb_official_sources.play_tails.'.$game->espn_event_id);
        if (! is_array($spec)) {
            throw new RuntimeException('No reviewed official closing possession is configured.');
        }
        app(CfbOfficialGamebookSource::class)->assertIdentity($game, $spec);
        $primary = GameDataCheck::where('game_id', $game->id)->where('component', 'plays')
            ->where('validator_version', CfbGameDataValidator::VERSION)->latest('updated_at')->latest('id')->firstOrFail();
        if ($primary->state !== 'partial' || $primary->discrepancies !== ['unverified_terminal_state']) {
            throw new RuntimeException('Official tail repair requires a complete prefix held only for its missing terminal state.');
        }
        $json = Storage::disk(config('cfb.data.source_disk', 'local'))->get($primary->source_path);
        if (! is_string($json) || ! hash_equals($primary->source_hash, hash('sha256', $json))) {
            throw new RuntimeException('Primary play archive hash mismatch.');
        }
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $items = $payload['items'] ?? [];
        $anchor = $items ? $items[array_key_last($items)] : [];
        if (count($items) !== ($payload['count'] ?? null)
            || count(array_unique(array_column($items, 'id'))) !== count($items)
            || (string) ($anchor['id'] ?? '') !== $spec['anchor']['id']
            || data_get($anchor, 'period.number') !== $spec['anchor']['period']
            || data_get($anchor, 'clock.displayValue') !== $spec['anchor']['clock']
            || data_get($anchor, 'type.text') !== $spec['anchor']['type']
            || (int) ($anchor['homeScore'] ?? -1) !== $game->home_score || (int) ($anchor['awayScore'] ?? -1) !== $game->away_score) {
            throw new RuntimeException('The primary prefix does not end at the exact reviewed closing-drive anchor.');
        }
        $physical = collect($spec['rows'])->filter(fn ($row) => isset($row['yards']));
        if ($physical->count() !== $spec['physical_plays'] || $physical->sum('yards') !== $spec['net_yards']
            || data_get($spec, 'rows.'.(count($spec['rows']) - 1).'.clock') !== '0:00'
            || data_get($spec, 'rows.'.(count($spec['rows']) - 1).'.type') !== 'End of Game') {
            throw new RuntimeException('Reviewed closing possession does not reconcile its observed play count, yardage and ending.');
        }
        $seconds = fn ($clock) => (int) explode(':', $clock)[0] * 60 + (int) explode(':', $clock)[1];
        $lastClock = $seconds($spec['anchor']['clock']);
        foreach ($spec['rows'] as $row) {
            if (! preg_match('/^\d{1,2}:[0-5]\d$/', $row['clock']) || $seconds($row['clock']) > $lastClock
                || ! in_array($row['type'], ['Timeout', 'Rush', 'Pass Reception', 'Kneel Down', 'End of Game'], true)
                || preg_match('/touchdown|field goal|safety|no play/i', $row['text'])) {
                throw new RuntimeException('Reviewed tail contains an unsupported score event or inconsistent clock.');
            }
            if (isset($row['yards']) && ($row['down'] < 1 || $row['down'] > 4 || $row['end_down'] < 1 || $row['end_down'] > 4
                || $row['distance'] < 0 || $row['end_distance'] < 0 || $row['yards_to_endzone'] < 1 || $row['yards_to_endzone'] > 100
                || $row['end_yards_to_endzone'] < 1 || $row['end_yards_to_endzone'] > 100
                || $row['yards'] !== $row['yards_to_endzone'] - $row['end_yards_to_endzone'])) {
                throw new RuntimeException('Reviewed tail contains inconsistent observed field positions.');
            }
            $lastClock = $seconds($row['clock']);
        }
        $reference = app(CfbOfficialGamebookSource::class)->archive($game, $spec);
        $team = $game->{$spec['offense'].'Team'};
        $sequence = max(array_map(fn ($play) => (int) ($play['sequenceNumber'] ?? 0), $items));
        foreach ($spec['rows'] as $index => $row) {
            $raw = ['id' => 'school-'.$game->espn_event_id.'-tail-'.$index, 'sequenceNumber' => (string) (++$sequence),
                'period' => ['number' => 4], 'clock' => ['displayValue' => $row['clock']],
                'type' => ['text' => $row['type']], 'text' => $row['text'],
                'homeScore' => $game->home_score, 'awayScore' => $game->away_score, 'scoringPlay' => false,
                'drive' => ['id' => 'school-'.$game->espn_event_id.'-closing'],
                'start' => ['team' => ['id' => (string) $team->espn_id]],
                'source_provider' => 'school_official', 'official_gamebook' => $reference,
                'clock_semantics' => 'observed_snap_or_timeout_time'];
            if (isset($row['yards'])) {
                $raw['statYardage'] = $row['yards'];
                $raw['start'] += ['down' => $row['down'], 'distance' => $row['distance'], 'yardsToEndzone' => $row['yards_to_endzone']];
                $raw['end'] = ['team' => ['id' => (string) $team->espn_id], 'down' => $row['end_down'],
                    'distance' => $row['end_distance'], 'yardsToEndzone' => $row['end_yards_to_endzone']];
            }
            $items[] = $raw;
        }
        $identity = $game->only(['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $payload = [...$payload, 'items' => $items, 'count' => count($items), '_reconciliation' => [
            'primary_source_hash' => $primary->source_hash, 'primary_count' => $primary->evidence['plays'] ?? count($items) - count($spec['rows']),
            'official_source_hash' => $reference['hash'], 'official_source_path' => $reference['path'],
            'official_source_url' => $reference['url'], 'official_reference' => $reference['reference'],
            'identity' => $identity, 'merged_count' => count($items), 'supplemented_rows' => count($spec['rows'])]];
        $validation = app(CfbGameDataValidator::class)->plays($payload, $game);
        if ($validation['state'] !== 'complete') {
            throw new RuntimeException('Merged official play source failed completeness validation.');
        }
        $validation['evidence'] = [...$validation['evidence'], ...$payload['_reconciliation']];
        $check = app(CfbSourceRevision::class)->record($game, 'plays', $payload, $validation,
            provider: 'school_official_espn', validatorVersion: self::VERSION);

        return DB::transaction(function () use ($game, $spec, $primary, $check, $items) {
            $locked = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            app(CfbOfficialGamebookSource::class)->assertIdentity($locked, $spec);
            $current = GameDataCheck::where('game_id', $game->id)->where('component', 'plays')
                ->where('validator_version', CfbGameDataValidator::VERSION)->latest('updated_at')->latest('id')->first();
            if ($current?->source_hash !== $primary->source_hash) {
                throw new RuntimeException('Primary play source changed during official reconciliation.');
            }
            $ids = [];
            $teams = collect([$locked->homeTeam, $locked->awayTeam])->keyBy('espn_id');
            foreach ($items as $index => $raw) {
                $dto = FootballPlayData::fromEspnResponse($raw, $index);
                $attrs = $dto->toArray();
                $attrs['possession_team_id'] = $teams->get($dto->possessionTeamEspnId)?->id;
                $attrs += ['source_revision' => $check->source_hash, 'source_state' => $raw,
                    'true_epa' => null, 'expected_points_before' => null, 'expected_points_after' => null, 'epa_calculated_at' => null];
                Play::updateOrCreate(['game_id' => $locked->id, 'espn_play_id' => $dto->espnPlayId], $attrs);
                $ids[] = $dto->espnPlayId;
            }
            Play::where('game_id', $game->id)->whereNotIn('espn_play_id', $ids)->delete();
            $check->update(['accepted_at' => now(), 'evidence' => [...$check->evidence, 'raw_fingerprint' => $this->rawFingerprint($locked)]]);

            return ['state' => 'complete', 'game_id' => $game->id, 'source_hash' => $check->source_hash,
                'provider' => 'school_official_espn', 'supplemented_rows' => count($spec['rows'])];
        });
    }

    public function currentProof(Game $game, ?GameDataCheck $primary): ?GameDataCheck
    {
        $check = GameDataCheck::where('game_id', $game->id)->where('component', 'plays')->where('validator_version', self::VERSION)
            ->where('state', 'complete')->whereNotNull('accepted_at')->latest('accepted_at')->latest('id')->first();
        $spec = config('cfb_official_sources.play_tails.'.$game->espn_event_id);
        if ($game->status !== 'STATUS_FINAL' || ! is_array($spec) || ! $check
            || data_get($check->evidence, 'official_source_hash') !== $spec['sha256']
            || data_get($check->evidence, 'primary_source_hash') !== $primary?->source_hash) {
            return null;
        }
        foreach (['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
            if ((string) data_get($check->evidence, 'identity.'.$field) !== (string) $game->{$field}) {
                return null;
            }
        }
        $rows = Play::where('game_id', $game->id);
        if ((clone $rows)->count() !== data_get($check->evidence, 'merged_count')
            || (clone $rows)->where('source_revision', $check->source_hash)->count() !== data_get($check->evidence, 'merged_count')) {
            return null;
        }

        return hash_equals((string) data_get($check->evidence, 'raw_fingerprint'), $this->rawFingerprint($game)) ? $check : null;
    }

    private function rawFingerprint(Game $game): string
    {
        return hash('sha256', json_encode(Play::where('game_id', $game->id)->orderBy('espn_play_id')
            ->get(self::RAW_FIELDS)->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
