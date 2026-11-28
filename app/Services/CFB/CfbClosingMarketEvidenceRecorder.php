<?php

namespace App\Services\CFB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\MarketQuote;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionPostgameEnrichment;
use App\Services\Predictions\CanonicalPayloadHasher;
use App\Support\Odds\MarketSpread;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use LogicException;

/** Stored postgame observations only: no provider refresh or captured-input mutation. */
final class CfbClosingMarketEvidenceRecorder
{
    private const PAYLOAD_BUDGET = 1048576;

    public function __construct(private readonly CfbSnapshotCaptureIntegrity $integrity, private readonly CanonicalPayloadHasher $hasher) {}

    /** @return list<PredictionPostgameEnrichment> */
    public function recordForGame(int $gameId): array
    {
        $connection = $this->coherentConnection(new Game);

        return $connection->transaction(function () use ($gameId): array {
            $game = Game::whereKey($gameId)->lockForUpdate()->first();
            if ($game === null || ! $this->finalGame($game)) {
                return [];
            }
            $predictions = Prediction::where('game_id', $gameId)->orderBy('id')->limit(101)->lockForUpdate()->get();
            if ($predictions->count() > 100) {
                throw new LogicException('Closing evidence game scope exceeds100 forecasts; no evidence written.');
            }
            $records = [];
            foreach ($predictions as $prediction) {
                $record = $this->recordForPrediction($prediction, $game);
                if ($record !== null) {
                    $records[] = $record;
                }
            }

            return $records;
        });
    }

    public function recordForPrediction(Prediction $prediction, Game $game): ?PredictionPostgameEnrichment
    {
        $connection = $this->coherentConnection($game, $prediction);
        if ((int) $prediction->game_id !== (int) $game->id) {
            throw new LogicException('Closing evidence forecast and game identity disagree.');
        }

        return $connection->transaction(function () use ($prediction, $game): ?PredictionPostgameEnrichment {
            $game = Game::whereKey($game->id)->lockForUpdate()->firstOrFail();
            $prediction = Prediction::whereKey($prediction->id)->lockForUpdate()->firstOrFail();
            if ((int) $prediction->game_id !== (int) $game->id || ! $this->finalGame($game)) {
                throw new LogicException('Closing evidence requires the current final game and forecast.');
            }
            $snapshot = PredictionFeatureSnapshot::where('sport', 'cfb')->where('prediction_table', $prediction->getTable())
                ->where('prediction_id', $prediction->id)->where('game_id', $game->id)
                ->where('model_version', $prediction->model_version)->where('feature_version', $prediction->feature_version)
                ->where('blend_version', $prediction->blend_version)->orderByDesc('generated_at')->orderByDesc('id')
                ->lockForUpdate()->first();
            if ($snapshot === null) {
                return null;
            }
            $proof = $this->integrity->inspect($snapshot, $game);
            $reasons = $proof['reasons'];
            $entry = data_get($snapshot->market_context, 'cfb_market_movement');
            if (! is_array($entry)) {
                $entry = [];
                $reasons[] = 'entry_market_context_missing';
            }
            $entry = array_intersect_key($entry, array_flip(['source', 'model_pick_side', 'model_home_margin', 'current_bookmaker_home_line', 'current_home_margin', 'current_captured_at']));
            $quote = null;
            $now = CarbonImmutable::now('UTC');
            if ($proof['kickoff'] !== null && $now->lt($proof['kickoff'])) {
                $reasons[] = 'closing_cutoff_not_reached';
            } elseif ($proof['kickoff'] !== null) {
                // Preserve the established stored quote wall-time contract; never query/lock the event after the game.
                $kickoff = $proof['kickoff']->setTimezone((string) config('app.timezone', 'UTC'));
                $asOf = $now->setTimezone((string) config('app.timezone', 'UTC'));
                $quote = MarketQuote::select(['id', 'game_table', 'game_id', 'sport', 'source', 'bookmaker_key', 'market_key',
                    'side', 'participant', 'line', 'price', 'bookmaker_home_line', 'quote_hash', 'captured_at', 'created_at'])
                    ->where('sport', 'cfb')->where('game_table', $game->getTable())->where('game_id', $game->id)
                    ->where('market_key', 'spreads')->where('side', 'home')->where('is_pregame', true)
                    ->where('captured_at', '<', $kickoff)->where('created_at', '<', $kickoff)
                    ->where('captured_at', '<=', $asOf)->where('created_at', '<=', $asOf)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(1)->lockForUpdate()->first();
                if ($quote === null) {
                    $reasons[] = 'closing_quote_missing';
                }
            }
            $quotes = $quote === null ? [] : [$this->quoteEvidence($quote)];
            $line = $this->finite($quote?->bookmaker_home_line ?? $quote?->line);
            $entryMargin = $this->finite($entry['current_home_margin'] ?? null);
            $side = $entry['model_pick_side'] ?? null;
            if ($entryMargin === null || ! in_array($side, ['home', 'away'], true)) {
                $reasons[] = 'entry_side_or_line_unavailable';
            }
            if ($quote !== null && $line === null) {
                $reasons[] = 'closing_quote_line_invalid';
            }
            $margin = $line === null ? null : MarketSpread::bookmakerHomeLineToHomeMargin($line);
            $clv = $margin !== null && $entryMargin !== null && in_array($side, ['home', 'away'], true)
                ? ($side === 'home' ? $margin - $entryMargin : $entryMargin - $margin) : null;
            $output = ['closing_bookmaker_home_line' => $line === null ? null : round($line, 3),
                'closing_home_margin' => $margin === null ? null : round($margin, 3),
                'closing_captured_at' => $quote?->captured_at?->toIso8601String(),
                'closing_line_value_points' => $clv === null ? null : round($clv, 3),
                'closing_line_value_bucket' => $this->bucket($clv)];
            if (strlen(json_encode([$entry, $quotes, $output], JSON_THROW_ON_ERROR)) > self::PAYLOAD_BUDGET) {
                $entry = $quotes = $output = [];
                $reasons[] = 'closing_evidence_payload_budget_exceeded';
            }
            $reasons = array_values(array_unique($reasons));
            $semantic = ['snapshot_id' => $snapshot->id, 'type' => PredictionPostgameEnrichment::CLOSING_MARKET,
                'source_feature_hash' => $snapshot->feature_hash, 'entry_payload_hash' => $proof['hash'],
                'game_context_hash' => $this->integrity->gameContextHash($game), 'quote_context_hash' => $this->hasher->hash($quotes),
                'source_integrity_status' => $proof['status'], 'availability_status' => $reasons === [] ? 'available' : 'unavailable',
                'reason_codes' => $reasons, 'entry_context' => $entry, 'source_quotes' => $quotes, 'output' => $output];
            $semanticHash = $this->hasher->hash($semantic);
            $latest = PredictionPostgameEnrichment::where('prediction_feature_snapshot_id', $snapshot->id)
                ->where('evidence_type', PredictionPostgameEnrichment::CLOSING_MARKET)->orderByDesc('revision')->lockForUpdate()->first();
            if ($latest !== null && hash_equals($latest->semantic_hash, $semanticHash)) {
                return $latest;
            }
            $revision = ($latest?->revision ?? 0) + 1;
            $observedAt = $now->format('Y-m-d H:i:s.u');
            $evidenceHash = $this->hasher->hash([...$semantic, 'revision' => $revision, 'supersedes' => $latest?->id, 'observed_at_utc' => $observedAt]);

            return PredictionPostgameEnrichment::create(['prediction_feature_snapshot_id' => $snapshot->id,
                'sport' => 'cfb', 'prediction_table' => $prediction->getTable(), 'prediction_id' => $prediction->id,
                'game_table' => $game->getTable(), 'game_id' => $game->id, 'evidence_type' => PredictionPostgameEnrichment::CLOSING_MARKET,
                'revision' => $revision, 'supersedes_enrichment_id' => $latest?->id, 'semantic_hash' => $semanticHash,
                ...array_diff_key($semantic, array_flip(['snapshot_id', 'type'])), 'evidence_hash' => $evidenceHash,
                'observed_at' => $now, 'source_max_captured_at' => $quote?->captured_at]);
        });
    }

    private function coherentConnection(Game $game, ?Prediction $prediction = null): Connection
    {
        $connection = $game->getConnection();
        foreach ([$prediction ?? new Prediction, new PredictionFeatureSnapshot, new PredictionPostgameEnrichment, new MarketQuote] as $model) {
            if ($model->getConnection()->getName() !== $connection->getName()) {
                throw new LogicException('Closing evidence requires one resolved database connection.');
            }
        }

        return $connection;
    }

    private function quoteEvidence(MarketQuote $quote): array
    {
        return ['id' => $quote->id, 'source' => $quote->source, 'bookmaker_key' => $quote->bookmaker_key,
            'market_key' => $quote->market_key, 'side' => $quote->side, 'participant' => $quote->participant,
            'line' => $quote->line, 'bookmaker_home_line' => $quote->bookmaker_home_line, 'price' => $quote->price,
            'claimed_quote_hash' => $quote->quote_hash, 'captured_at_stored' => $quote->getRawOriginal('captured_at'),
            'created_at_stored' => $quote->getRawOriginal('created_at'), 'captured_at_interpreted' => $quote->captured_at?->toIso8601String(),
            'timestamp_policy' => 'legacy_market_quote_storage_and_app_cast'];
    }

    private function finalGame(Game $game): bool
    {
        return in_array($game->status, GameData::finalStatuses(), true) && $game->home_score !== null && $game->away_score !== null;
    }

    private function finite(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function bucket(?float $value): ?string
    {
        return match (true) {
            $value === null => null, $value >= 2 => 'strong_positive', $value >= .25 => 'positive',
            $value <= -2 => 'strong_negative', $value <= -.25 => 'negative', default => 'neutral',
        };
    }
}
