<?php

namespace App\Services\Admin;

use App\Services\Sports\SportsPipelineRegistry;
use App\Support\SportCatalog;
use DateTimeImmutable;
use DateTimeZone;

/** Finite dispatch, not a capacity/approval certificate for the underlying commands. */
class HealthcheckRemediationCatalog
{
    public function __construct(private readonly SportsPipelineRegistry $pipelines) {}

    public function describe(string $sport, string $checkType, mixed $recommendation = null): array
    {
        $resolved = $this->resolve($sport, $checkType, $recommendation);

        return $resolved === null
            ? ['supported' => false, 'kind' => null, 'effects' => null, 'reason' => 'No reviewed action is available here. Review the recommendation and use the explicit operator workflow.']
            : ['supported' => true, 'kind' => $resolved['kind'], 'effects' => $resolved['effects'], 'reason' => 'Runs current server-selected remediation; existing readiness and approval gates still apply.'];
    }

    public function resolve(string $sport, string $checkType, mixed $recommendation = null): ?array
    {
        if (! in_array($sport, SportCatalog::ALL, true)) {
            return null;
        }
        if (in_array($checkType, ['heartbeat_sync', 'heartbeat_live_scoreboard', 'heartbeat_prediction_pipeline', 'heartbeat_model_pipeline', 'heartbeat_odds', 'heartbeat_player_props'], true)) {
            $registered = $this->pipelines->healthcheckCommand($sport, $checkType);

            return $registered === null ? null : [...$registered, 'kind' => 'registered_pipeline', 'effects' => 'Runs a registered pipeline step; database writes, provider requests or queued work may occur.'];
        }
        if (! is_string($recommendation) || $recommendation === '' || strlen($recommendation) > 512
            || preg_match('/^[\x20-\x7e]+$/D', $recommendation) !== 1) {
            return null;
        }
        $tokens = explode(' ', trim($recommendation));
        $command = array_shift($tokens);
        $arguments = [];
        foreach ($tokens as $token) {
            if (preg_match('/^(--[a-z][a-z-]*)(?:=([A-Za-z0-9_-]+))?$/D', $token, $match) !== 1 || array_key_exists($match[1], $arguments)) {
                return null;
            }
            $arguments[$match[1]] = $match[2] ?? true;
        }
        $kind = null;
        $effects = 'Writes local data; provider requests or queued work may occur.';
        $noOptions = $arguments === [];
        if ($noOptions && in_array($command, [$sport.':generate-predictions', $sport.':generate-canonical-predictions'], true)) {
            $kind = 'prediction';
        } elseif ($noOptions && $command === 'espn:sync-'.$sport.'-game-details') {
            $kind = 'details';
        } elseif ($noOptions && $command === $sport.':sync-odds') {
            $kind = 'odds';
        } elseif ($noOptions && $command === 'espn:sync-'.$sport.'-injuries') {
            $kind = 'injuries';
        } elseif ($noOptions && $command === $sport.':sync-player-props' && in_array($sport, ['nba', 'cbb', 'wnba'], true)) {
            $kind = 'prop_sync';
        } elseif ($command === 'espn:sync-'.$sport.'-games-scoreboard') {
            if ($noOptions) {
                $kind = 'scoreboard';
            } elseif (array_keys($arguments) === ['--from-date', '--to-date']) {
                $from = $this->date($arguments['--from-date']);
                $to = $this->date($arguments['--to-date']);
                if ($from && $to && $to >= $from && $from->diff($to)->days <= 31) {
                    $kind = 'scoreboard';
                }
            }
        } elseif ($command === 'sports:operations-sentinel' && $arguments === ['--sport' => $sport]) {
            $kind = 'sentinel';
            $effects = 'Runs a repair pipeline (repair is the command default); database writes and provider requests may occur.';
        } elseif ($command === 'sports:sync-futures-odds' && array_keys($arguments) === ['--sport', '--season'] && $arguments['--sport'] === $sport && in_array($sport, ['nba', 'nfl', 'mlb', 'cbb', 'wcbb'], true) && $this->year($arguments['--season'])) {
            $arguments['--season'] = (int) $arguments['--season'];
            $kind = 'futures';
        } elseif ($command === 'sports:analyze-player-props' && ($arguments['--sport'] ?? null) === $sport && in_array(array_keys($arguments), [['--sport'], ['--sport', '--season'], ['--sport', '--only-missing'], ['--sport', '--season', '--only-missing']], true)
            && (! isset($arguments['--season']) || $this->year($arguments['--season'])) && (! isset($arguments['--only-missing']) || $arguments['--only-missing'] === true)) {
            if (isset($arguments['--season'])) {
                $arguments['--season'] = (int) $arguments['--season'];
            }
            $kind = 'prop_analysis';
            $effects = 'Runs local prop analysis and writes projections; installed input/quote gates remain authoritative.';
        } elseif ($sport === 'nfl' && $command === 'nfl:research-pipeline' && array_keys($arguments) === ['--days-forward', '--limit'] && is_string($arguments['--days-forward']) && preg_match('/^(?:[1-9]|1[0-4])$/D', $arguments['--days-forward']) === 1 && $arguments['--limit'] === '4') {
            $arguments = ['--days-forward' => (int) $arguments['--days-forward'], '--limit' => 4];
            $kind = 'research';
            $effects = 'May call paid research providers and write research; current budget/readiness gates still apply.';
        } elseif ($sport === 'cfb' && $command === 'cfb:run-pregame-pipeline' && $arguments === ['--days-forward' => '8', '--resume' => true]) {
            $arguments['--days-forward'] = 8;
            $kind = 'cfb_pregame';
        } elseif ($sport === 'nfl' && $command === 'nfl:run-pregame-pipeline' && $noOptions) {
            $kind = 'nfl_pregame';
        } elseif ($sport === 'cfb' && $noOptions && $command === 'cfb:import-fpi') {
            $kind = 'fpi';
        } elseif ($sport === 'cfb' && $noOptions && $command === 'cfb:calculate-team-metrics') {
            $kind = 'metrics';
        } elseif (in_array($sport, ['mlb', 'nfl'], true) && $command === $sport.':sync-game-weather' && $arguments === ['--days-back' => '0', '--days-forward' => '7', '--force' => true]) {
            $arguments = ['--days-back' => 0, '--days-forward' => 7, '--force' => true];
            $kind = 'weather';
        } elseif (in_array($sport, ['mlb', 'cfb'], true) && $command === 'sports:ai-daily-predictions' && $arguments === ['--sport' => $sport]) {
            $kind = 'ai_daily';
            $effects = 'May use paid AI/providers, write or publish analysis and send notifications under installed approval/spend gates.';
        }
        $allowed = match ($checkType) {
            'validation_prediction_completeness' => ['prediction', 'cfb_pregame'],
            'validation_odds_completeness' => ['odds'],
            'validation_injury_freshness' => ['injuries'],
            'validation_weather_completeness' => ['weather'],
            'validation_live_prediction_freshness', 'validation_past_scheduled_game_status' => ['scoreboard'],
            'validation_current_day_game_data_freshness', 'validation_team_stat_coverage', 'validation_finalized_data_completeness' => ['details'],
            'validation_schedule_window_integrity', 'validation_upcoming_game_readiness' => ['sentinel'],
            'validation_futures_odds_freshness' => ['futures'],
            'validation_player_prop_freshness' => ['prop_analysis'],
            'validation_nfl_research_coverage' => ['research'],
            'validation_pipeline_order' => ['prediction', 'cfb_pregame', 'nfl_pregame', 'prop_sync', 'fpi', 'metrics', 'ai_daily'],
            default => [],
        };

        return $kind !== null && in_array($kind, $allowed, true) ? compact('command', 'arguments', 'kind', 'effects') : null;
    }

    private function year(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:20\d{2}|2100)$/D', $value) === 1;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^(?:20\d{2}|2100)-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
