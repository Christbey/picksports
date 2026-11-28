<?php

namespace App\Actions\ESPN\CFB;

use App\Actions\ESPN\AbstractSyncPlays;
use App\DataTransferObjects\ESPN\FootballPlayData;
use App\Models\CFB\Game;
use App\Models\CFB\Play;
use App\Models\CFB\SourceAssessmentRevision;
use App\Models\CFB\Team;
use App\Services\CFB\Data\CfbGameDataValidator;
use App\Services\CFB\Data\CfbSourceAssessmentRecorder;
use App\Services\CFB\Data\CfbSourceRevision;
use App\Services\ESPN\CFB\EspnService;
use App\Services\Predictions\CanonicalPayloadHasher;

class SyncPlays extends AbstractSyncPlays
{
    protected const GAME_MODEL_CLASS = Game::class;

    protected const PLAY_MODEL_CLASS = Play::class;

    protected const TEAM_MODEL_CLASS = Team::class;

    protected const PLAY_DTO_CLASS = FootballPlayData::class;

    public function __construct(EspnService $espnService)
    {
        parent::__construct($espnService);
    }

    public function execute(string $eventId): int
    {
        $game = Game::where('espn_event_id', $eventId)->first();
        if (! $game) {
            return 0;
        }
        $identity = $this->capturePlayContext($game);
        $response = $this->espnService->getPlays($eventId, $eventId);
        if (! is_array($response)) {
            return 0;
        }
        $validation = app(CfbGameDataValidator::class)->plays($response, $game);
        $version = isset($validation['evidence']['score_snapshot_reconciliation'])
            ? CfbGameDataValidator::RECONCILED_PLAYS_VERSION.':'.substr(hash('sha256', json_encode($validation['evidence']['score_snapshot_reconciliation'], JSON_THROW_ON_ERROR)), 0, 20)
            : CfbGameDataValidator::VERSION;
        $check = app(CfbSourceRevision::class)->record($game, 'plays', $response, $validation, validatorVersion: $version);
        if ($validation['state'] !== 'complete' || $check->state !== 'complete') {
            return 0;
        }
        // Parse the whole payload before any play replacement. Assessments/archive
        // evidence above are distinct from acceptance or business play mutations.
        $dtos = [];
        foreach ($response['items'] as $index => $raw) {
            $dtos[] = FootballPlayData::fromEspnResponse($raw, $index);
        }

        return $game->getConnection()->transaction(function () use ($game, $response, $identity, $dtos) {
            $locked = $this->lockPlayGame($game, $identity);
            try {
                $this->preparePlayTeams($locked, $dtos, $response['items'], $identity);
                $validation = app(CfbGameDataValidator::class)->plays($response, $locked);
                $version = isset($validation['evidence']['score_snapshot_reconciliation'])
                    ? CfbGameDataValidator::RECONCILED_PLAYS_VERSION.':'.substr(hash('sha256', json_encode($validation['evidence']['score_snapshot_reconciliation'], JSON_THROW_ON_ERROR)), 0, 20)
                    : CfbGameDataValidator::VERSION;
                $check = app(CfbSourceRevision::class)->record($locked, 'plays', $response, $validation, validatorVersion: $version);
                if ($validation['state'] !== 'complete' || $check->state !== 'complete') {
                    return 0;
                }
                $rows = [];
                foreach ($dtos as $index => $dto) {
                    $attrs = $dto->toArray();
                    $attrs['possession_team_id'] = null;
                    $this->applyTeamRelations($attrs, $dto);
                    $rows[$dto->espnPlayId] = [...$attrs, 'source_revision' => $check->source_hash, 'source_state' => $response['items'][$index]];
                }
                $connection = $locked->getConnection()->getName();
                $stored = Play::on($connection)->where('game_id', $locked->id)->orderBy('id')->lockForUpdate()->get()->keyBy('espn_play_id');
                $same = $stored->count() === count($rows);
                foreach ($rows as $id => $attrs) {
                    $prior = $stored->get($id);
                    if (! $prior) {
                        $same = false;
                        break;
                    }
                    $dirty = (clone $prior)->fill($attrs)->getDirty();
                    // MySQL JSON may reorder object keys; preserve scalar types and list order.
                    if (array_key_exists('source_state', $dirty)
                        && $this->canonicalPlaySourceState($prior->source_state) === $this->canonicalPlaySourceState($attrs['source_state'])) {
                        unset($dirty['source_state']);
                    }
                    if ($dirty !== []) {
                        $same = false;
                        break;
                    }
                }
                // The new processing assessment may clear the mutable accepted_at.
                // Preserve an equal play projection only with a real prior observed
                // acceptance for this raw source and this exact game context.
                $contextHash = app(CanonicalPayloadHasher::class)->hash(
                    app(CfbSourceAssessmentRecorder::class)->context($locked)
                );
                $priorAcceptance = SourceAssessmentRevision::on($connection)
                    ->join('cfb_game_data_checks as assessed', 'assessed.id', '=', 'cfb_source_assessment_revisions.game_data_check_id')
                    ->where('assessed.game_id', $locked->id)->where('assessed.component', 'plays')->where('assessed.source_hash', $check->source_hash)
                    ->where('cfb_source_assessment_revisions.context_hash', $contextHash)
                    ->whereIn('cfb_source_assessment_revisions.evidence_kind', ['observed_assessment', 'acceptance_transition'])
                    ->whereNotNull('cfb_source_assessment_revisions.projection_snapshot->accepted_at')
                    ->orderByDesc('cfb_source_assessment_revisions.id')->lockForUpdate()->first(['cfb_source_assessment_revisions.id']);
                if ($same && $priorAcceptance !== null) {
                    if (! $check->isCurrentAccepted()) {
                        $check->update(['accepted_at' => now()]);
                    }

                    return 0;
                }
                $retainedIds = [];
                foreach ($rows as $attrs) {
                    $id = $attrs['espn_play_id'];
                    $query = Play::on($connection)->where('game_id', $locked->id)->where('espn_play_id', $id);
                    $column = $query->getConnection()->getQueryGrammar()->wrap('espn_play_id');
                    match ($query->getConnection()->getDriverName()) {
                        'mysql' => $query->whereRaw('BINARY '.$column.' = ?', [$id]),
                        'sqlite' => $query->whereRaw($column.' COLLATE BINARY = ?', [$id]),
                        default => throw new \RuntimeException('Exact play identity comparison is unsupported.'),
                    };
                    $row = $query->lockForUpdate()->first() ?? (new Play)->setConnection($connection)->forceFill(['game_id' => $locked->id]);
                    $row->fill([...$attrs, 'true_epa' => null, 'expected_points_before' => null, 'expected_points_after' => null, 'epa_calculated_at' => null])->save();
                    $retainedIds[] = $row->getKey();
                }
                Play::on($connection)->where('game_id', $locked->id)->whereNotIn('id', $retainedIds)->delete();
                $check->update(['accepted_at' => now()]);

                return count($rows);
            } finally {
                $this->clearPlayTeams();
            }
        }, 3);
    }

    private function canonicalPlaySourceState(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalPlaySourceState(...), $value);
    }
}
