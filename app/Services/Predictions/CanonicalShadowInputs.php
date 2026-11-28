<?php

namespace App\Services\Predictions;

use App\Application\Sports\ReadModels\CanonicalShadowSnapshot;
use App\Models\CanonicalPrediction;
use App\Models\MLB\Game;
use App\Services\Api\V2\CanonicalSportPredictionQuery;
use App\Support\Json\JsonAllocationBudget;
use App\Support\Sports\CanonicalEventStart;
use Illuminate\Support\Collection;

class CanonicalShadowInputs
{
    public function forGames(string $sport, ?int $gameId = null, int $limit = 0): Collection
    {
        $query = app(CanonicalSportPredictionQuery::class)->queryForSport($sport)
            ->withOnly(['markets', 'calculationRun.inputSnapshot', 'calculationRun.release', 'sportEvent.'.$sport.'Game'])
            ->tap(fn ($query) => CanonicalEventStart::future($query, $sport, now()))->where("{$sport}_games.status", 'STATUS_SCHEDULED')
            ->when($gameId !== null, fn ($query) => $query->where("{$sport}_games.id", $gameId))
            ->reorder()->orderBy('sport_events.starts_at')->orderBy("{$sport}_games.id");
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get()->map(fn ($prediction) => $this->fromPrediction($prediction))->filter()->values();
    }

    public function fromPrediction(CanonicalPrediction $prediction): ?CanonicalShadowSnapshot
    {
        return $this->convert($prediction);
    }

    /** Pure conversion of the coordinator's bounded locked projections; grants no provenance authority. */
    public function fromOwnedBoundedMlbPrediction(CanonicalPrediction $prediction, Game $game): ?CanonicalShadowSnapshot
    {
        if ($prediction->sport !== 'mlb' || ! $prediction->relationLoaded('calculationRun')
            || ! $prediction->relationLoaded('sportEvent') || ! $prediction->relationLoaded('markets')) {
            return null;
        }
        $run = $prediction->getRelation('calculationRun');
        $event = $prediction->getRelation('sportEvent');
        if ($run === null || $event === null || ! $run->relationLoaded('inputSnapshot') || ! $run->relationLoaded('release')
            || ! $event->relationLoaded('mlbGame') || $event->getRelation('mlbGame') !== $game) {
            return null;
        }
        $input = $run->getRelation('inputSnapshot');
        $raw = $input?->getRawOriginal('inputs');
        if (! is_string($raw)) {
            return null;
        }
        JsonAllocationBudget::inspect($raw, 2097152, 50000, 24);
        $inline = $input->inlineInputs();
        if (! is_array($inline)) {
            return null;
        }
        // The global CFB accessor resolves these SQL payload references. This
        // MLB path supports bounded inline input only and must not invoke it.
        foreach (['historical_signals', 'scoring_challenger', 'football_signal_evidence'] as $section) {
            if (is_string($inline[$section] ?? null) && str_starts_with($inline[$section], SnapshotMetadataStorage::REFERENCE_PREFIX)) {
                return null;
            }
        }

        return $this->convert($prediction, $game, $inline);
    }

    private function convert(CanonicalPrediction $prediction, ?Game $ownedGame = null, ?array $inlineInputs = null): ?CanonicalShadowSnapshot
    {
        if ($ownedGame === null) {
            $prediction->loadMissing('calculationRun.inputSnapshot', 'calculationRun.release', 'sportEvent.'.$prediction->sport.'Game', 'markets');
        }
        $input = $prediction->calculationRun?->inputSnapshot;
        $game = $ownedGame ?? $prediction->sportEvent?->getRelation($prediction->sport.'Game');
        $event = $prediction->sportEvent;
        $start = $event === null ? null : ($ownedGame === null ? CanonicalEventStart::forEvent($event) : CanonicalEventStart::forOwnedMlbGame($event, $ownedGame));
        $matches = $event === null || ($ownedGame === null ? CanonicalEventStart::snapshot($event, $input)
            : ($input !== null && CanonicalEventStart::snapshotForOwnedMlbGame($event, $input, $ownedGame, $inlineInputs ?? [])));
        if (! $matches) {
            return null;
        }
        if ($prediction->calculationRun?->status !== 'succeeded'
            || ! in_array($prediction->calculationRun?->release?->status, ['approved', 'retired'], true)
            || ! $input || ! $game || ! $start || $input->pregame_safety_status !== 'verified'
            || ! $input->captured_at || ! $input->cutoff_at || ! $prediction->generated_at
            || ! $prediction->published_at || $prediction->published_at->gt($start)
            || $input->captured_at->gt($start) || $input->cutoff_at->gt($start)
            || $input->captured_at->gt($prediction->generated_at)
            || $input->sport !== $prediction->sport || $input->sport_event_id !== $prediction->sport_event_id
            || $input->phase !== 'pregame' || $prediction->phase !== 'pregame'
            || $input->captured_at->gt($input->cutoff_at) || $prediction->generated_at->gte($start)
            || ($input->latest_source_available_at && $input->latest_source_available_at->gt($input->cutoff_at))
            || ! in_array($prediction->publication_state, ['published', 'superseded'], true)) {
            return null;
        }
        $inputs = $inlineInputs ?? (array) $input->inputs;
        $features = [];
        foreach (['home', 'away'] as $side) {
            $features[$side.'_team_elo'] = data_get($inputs, "{$side}.elo");
            $features[$side.'_pitcher_elo'] = data_get($inputs, "pitching.{$side}.elo");
            $features[$side.'_probable_pitcher_espn_id'] = data_get($inputs, "pitching.{$side}.espn_id");
            foreach ((array) data_get($inputs, "{$side}.metrics", []) as $key => $value) {
                if (is_numeric($value)) {
                    $features[$side.'_'.$key] = (float) $value;
                }
            }
        }
        $market = data_get($inputs, 'pregame_market.available') === true ? (array) data_get($inputs, 'pregame_market.consensus', []) : [];
        $moneyline = $prediction->markets->first(fn ($m) => $m->market_type === 'moneyline' && $m->selection === 'home');
        $spread = $prediction->markets->first(fn ($m) => $m->market_type === 'spread' && $m->selection === 'home');
        $total = $prediction->markets->first(fn ($m) => $m->market_type === 'total' && $m->selection === 'combined');
        $line = data_get($market, 'spread.line');
        $lineage = ['canonical_prediction_id' => $prediction->id, 'canonical_prediction_public_id' => $prediction->public_id,
            'calculation_run_id' => $prediction->calculation_run_id, 'event_input_snapshot_id' => $input->id,
            'input_content_hash' => $input->content_hash, 'calculation_release_id' => $prediction->calculationRun->calculation_release_id];

        return new CanonicalShadowSnapshot(
            $prediction->id, $game->id, $features,
            ['win_probability' => $moneyline?->probability, 'predicted_spread' => $spread?->projected_line !== null ? -(float) $spread->projected_line : null,
                'predicted_total' => $total?->projected_line, 'market_spread' => is_numeric($line) ? -(float) $line : null,
                'market_total' => data_get($market, 'total.line')],
            ['bookmaker_home_line' => $line, 'market_home_margin' => is_numeric($line) ? -(float) $line : null,
                'market_total' => data_get($market, 'total.line')],
            ['canonical_lineage' => $lineage], $input->content_hash, $prediction->calculation_run_id,
            $prediction->generated_at, $input->captured_at, $start, $lineage,
            model_version: (string) $prediction->model_version, feature_version: (string) $input->schema_version,
            blend_version: 'canonical-release-'.$prediction->calculationRun->calculation_release_id,
        );
    }
}
