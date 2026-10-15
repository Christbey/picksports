<?php

namespace App\Services\CFB\Predictions;

use App\Models\CFB\Game;
use App\Models\CFB\ResultRatingRun;
use App\Services\CFB\Ratings\ResultRatingModel;
use Carbon\CarbonImmutable;

class CfbCandidateEvidence
{
    private array $totalsCache = [];

    public const VERSION = 'cfb-candidate-evidence-v1';

    public function capture(Game $game, CarbonImmutable $asOf, CarbonImmutable $kickoff): array
    {
        $asOf = $asOf->setTimezone(config('app.timezone'));
        $kickoff = $kickoff->setTimezone(config('app.timezone'));
        if ($asOf->gte($kickoff)) {
            return ['status' => 'not_pregame'];
        }
        $rows = Game::query()->whereBetween('season', [$game->season - 1, $game->season])
            ->where('id', '!=', $game->id)->where('status', 'STATUS_FINAL')->where('season_type', 2)
            ->whereNotNull('home_score')->whereNotNull('away_score')
            ->whereDate('game_date', '<', $kickoff->utc()->toDateString())
            ->where('created_at', '<=', $asOf)->where('updated_at', '<=', $asOf)
            ->orderBy('id')->get(['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site', 'updated_at'])
            ->map(fn ($g) => [...$g->only(['id', 'season', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'neutral_site']),
                'observed_at' => $g->updated_at->toIso8601String()])->all();
        if ($rows === []) {
            return ['status' => 'missing_contemporaneous_history'];
        }
        $hash = hash('sha256', json_encode([self::VERSION, $game->season, $rows], JSON_THROW_ON_ERROR));
        $run = ResultRatingRun::query()->where('input_hash', $hash)->first();
        if (! $run) {
            $run = ResultRatingRun::query()->firstOrCreate(['input_hash' => $hash], [
                'season' => $game->season, 'version' => self::VERSION, 'source_rows' => $rows,
                'model' => ['results' => app(ResultRatingModel::class)->fit($rows, $game->season),
                    'totals' => app(CfbOpponentAdjustedTotals::class)->fit($rows, $game->season)],
                'source_available_at' => CarbonImmutable::parse(collect($rows)->max('observed_at')),
                'created_at' => now(),
            ]);
        }

        return $this->fromRun($run, $game, $asOf, $kickoff, allowNewRun: true);
    }

    /** Retrospective comparison uses only a run already stored by the original capture; never writes. */
    public function historical(Game $game, CarbonImmutable $asOf, CarbonImmutable $kickoff): array
    {
        $asOf = $asOf->setTimezone(config('app.timezone'));
        $kickoff = $kickoff->setTimezone(config('app.timezone'));
        $runs = ResultRatingRun::query()->where('season', $game->season)->whereIn('version', [self::VERSION, ResultRatingModel::VERSION])->where('created_at', '<=', $asOf)
            ->where('source_available_at', '<=', $asOf)->orderByDesc('created_at')->orderByDesc('id')->get(['id']);
        foreach ($runs as $identity) {
            $run = ResultRatingRun::query()->findOrFail($identity->id);
            $evidence = $this->fromRun($run, $game, $asOf, $kickoff);
            if ($evidence['status'] === 'available') {
                return $evidence;
            }
        }

        return ['status' => 'missing_contemporaneous_history'];
    }

    private function fromRun(ResultRatingRun $run, Game $game, CarbonImmutable $asOf, CarbonImmutable $kickoff, bool $allowNewRun = false): array
    {
        if ($asOf->gte($kickoff) || (! $allowNewRun && $run->created_at->gt($asOf)) || $run->source_available_at->gt($asOf)
            || collect($run->source_rows)->contains(fn ($r) => (int) $r['id'] === $game->id
                || ! isset($r['observed_at']) || CarbonImmutable::parse($r['observed_at'])->gt($asOf))) {
            return ['status' => 'unsafe_history'];
        }
        $rows = $run->source_rows;
        $resultModel = $run->version === self::VERSION ? $run->model['results'] : $run->model;
        if (count($this->totalsCache) > 32) {
            $this->totalsCache = [];
        }
        $totalsModel = $run->version === self::VERSION ? $run->model['totals']
            : ($this->totalsCache[$run->input_hash] ??= app(CfbOpponentAdjustedTotals::class)->fit($rows, $game->season));
        $result = app(ResultRatingModel::class)->predict($resultModel, $game->home_team_id, $game->away_team_id, (bool) $game->neutral_site);
        $total = app(CfbOpponentAdjustedTotals::class)->predict($totalsModel, $game->home_team_id, $game->away_team_id);

        return ['status' => 'available', 'version' => self::VERSION, 'run_id' => $run->id, 'input_hash' => $run->input_hash,
            'source_available_at' => $run->source_available_at->toIso8601String(),
            'result_rating' => $result ? array_intersect_key($result, array_flip(['home_margin', 'total', 'version'])) : null,
            'opponent_adjusted_total' => $total, 'source_games' => count($rows),
            'evidence_mode' => $run->version === self::VERSION ? 'frozen_candidate_inputs' : 'retrospective_fixed_candidate_from_contemporaneous_run'];
    }
}
