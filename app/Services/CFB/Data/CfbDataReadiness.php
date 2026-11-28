<?php

namespace App\Services\CFB\Data;

use App\Models\CFB\Game;
use App\Models\CFB\GameDataCheck;
use App\Models\CFB\TeamStat;
use App\Services\Predictions\CanonicalPayloadHasher;
use Carbon\CarbonInterface;

class CfbDataReadiness
{
    public function coreBoxscoreReady(Game $game): bool
    {
        if ($game->status !== 'STATUS_FINAL') {
            return false;
        }
        $box = $this->forGame($game)['components']['boxscore'];
        if ($box['state'] === 'complete' && $box['accepted']) {
            return true;
        }
        $check = app(CfbSourceAssessmentRecorder::class)->acceptedForGame($game, CfbCfbdTeamBoxscoreSync::COMPONENT, [CfbCfbdTeamBoxscoreSync::VERSION]);
        if (! $check || ! $this->matchesSuppliedContext($game, $check)) {
            return false;
        }
        foreach (['id', 'espn_event_id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score'] as $field) {
            if ((string) data_get($check->evidence, 'identity.'.$field) !== (string) $game->{$field}) {
                return false;
            }
        }
        foreach ([$game->home_team_id, $game->away_team_id] as $teamId) {
            $stat = TeamStat::where('game_id', $game->id)->where('team_id', $teamId)->first();
            $expected = data_get($check->evidence, 'team_stats.'.$teamId);
            if (! $stat || ! is_array($expected)) {
                return false;
            }
            foreach ($expected as $field => $value) {
                $actual = $stat->getAttribute($field);
                if ($value === null ? $actual !== null : $actual === null || (string) $actual !== (string) $value) {
                    return false;
                }
            }
        }

        return true;
    }

    public function acceptedCheck(Game $game, string $component, ?CarbonInterface $asOf = null): ?GameDataCheck
    {
        [$check, $accepted] = $this->assessment($game, $component, $asOf);

        return $accepted ? $check : null;
    }

    private function matchesSuppliedContext(Game $game, GameDataCheck $check, ?CarbonInterface $asOf = null): bool
    {
        $recorder = app(CfbSourceAssessmentRecorder::class);
        $receipt = $recorder->acceptanceReceipt($check, $asOf);

        return $receipt !== null && $receipt->context_hash === app(CanonicalPayloadHasher::class)->hash($recorder->context($game));
    }

    private function assessment(Game $game, string $component, ?CarbonInterface $asOf = null): array
    {
        if (! in_array($component, ['boxscore', 'plays'], true)) {
            throw new \InvalidArgumentException('Unsupported source component.');
        }
        $recorder = app(CfbSourceAssessmentRecorder::class);
        $check = $recorder->currentCheck($game, $component, [CfbGameDataValidator::VERSION],
            $component === 'plays' ? CfbGameDataValidator::RECONCILED_PLAYS_VERSION.':' : null, $asOf);
        $accepted = $check !== null && $this->matchesSuppliedContext($game, $check, $asOf);
        if (! $accepted) {
            $official = $component === 'boxscore'
                ? app(CfbOfficialBoxscoreRepair::class)->currentProof($game, $check, $asOf)
                : app(CfbOfficialPlayTailRepair::class)->currentProof($game, $check, $asOf);
            if ($official !== null) {
                $check = $official;
                $accepted = $this->matchesSuppliedContext($game, $official, $asOf);
            }
        }
        if ($accepted && str_starts_with($check->validator_version, CfbGameDataValidator::RECONCILED_PLAYS_VERSION.':')) {
            $box = $this->acceptedCheck($game, 'boxscore', $asOf);
            $accepted = $box !== null
                && data_get($check->evidence, 'score_snapshot_reconciliation.boxscore_source_hash') === $box->source_hash
                && data_get($check->evidence, 'score_snapshot_reconciliation.ledger_version') === CfbScoringLedger::VERSION;
        }

        return [$check, $accepted];
    }

    public function forGame(Game $game): array
    {
        $states = [];
        foreach (['boxscore', 'plays'] as $component) {
            [$check, $accepted] = $this->assessment($game, $component);
            $states[$component] = ['state' => $check?->state ?? 'unverified', 'accepted' => $accepted,
                'source_hash' => $check?->source_hash, 'discrepancies' => $check?->discrepancies ?? ['not_audited']];
        }

        return ['ready' => collect($states)->every(fn ($s) => $s['state'] === 'complete' && $s['accepted']), 'components' => $states];
    }
}
