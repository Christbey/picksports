<?php

namespace App\Services\MLB;

use App\Models\GameOddsSnapshot;
use App\Models\MLB\Game;
use App\Models\MLB\Prediction;
use App\Models\ModelRun;
use App\Models\PredictionEvaluation;
use App\Models\PredictionFeatureSnapshot;
use App\Models\SnapshotMetadataPayload;
use App\Support\MLB\MlbGameStart;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/** Bounded DB admission before any source/target JSON is hydrated. */
final class MlbTabularSnapshotReader
{
    public const MAX_CANDIDATES = 50000;

    public const MAX_GAMES = 10000;

    public const MAX_ROW_BYTES = 262144;

    public const MAX_BATCH_BYTES = 2097152;

    public const MAX_TOTAL_BYTES = 67108864;

    public const MAX_BATCH_TOKENS = 50000;

    public const MAX_TOTAL_TOKENS = 5000000;

    public const BATCH = 100;

    private const BODIES = ['s.features', 's.outputs', 's.market_context', 's.model_metadata', 's.source_timestamps', 's.lineage_metadata', 'pe.actuals'];

    public function connection(): Connection
    {
        $connection = (new PredictionFeatureSnapshot)->getConnection();
        foreach ([new Game, new Prediction, new ModelRun, new PredictionEvaluation, new GameOddsSnapshot, new SnapshotMetadataPayload] as $model) {
            if ($model->getConnection()->getName() !== $connection->getName()) {
                throw new RuntimeException('MLB tabular evidence requires one coherent resolved connection.');
            }
        }
        if (! in_array($connection->getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('MLB tabular evidence does not support this database driver.');
        }

        return $connection;
    }

    public function query(array $seasons = []): Builder
    {
        return $this->connection()->table('prediction_feature_snapshots as s')->useWritePdo()
            ->join('prediction_evaluations as pe', function ($join): void {
                $join->on('s.prediction_table', '=', 'pe.prediction_table')->on('s.prediction_id', '=', 'pe.prediction_id')
                    ->on('s.model_version', '=', 'pe.model_version')->on('s.feature_version', '=', 'pe.feature_version')->on('s.blend_version', '=', 'pe.blend_version');
                if ($this->connection()->getDriverName() === 'mysql') {
                    foreach (['prediction_table', 'model_version', 'feature_version', 'blend_version'] as $field) {
                        $join->whereRaw('CAST(s.'.$field.' AS BINARY) = CAST(pe.'.$field.' AS BINARY)');
                    }
                }
            })->join('mlb_games as g', 's.game_id', '=', 'g.id')
            ->leftJoin('model_runs as mr', 's.model_run_id', '=', 'mr.id')
            ->where('s.sport', 'mlb')->where('s.prediction_table', 'mlb_predictions')
            ->where('pe.sport', 'mlb')->whereColumn('pe.game_id', 's.game_id')
            ->when($seasons !== [], fn (Builder $query) => $query->whereIn('g.season', $seasons));
    }

    public function assertScope(Builder $query): array
    {
        $limited = (clone $query)->selectRaw('s.id, '.$this->rowBytes().' as payload_bytes')->limit(self::MAX_CANDIDATES + 1);
        $row = $this->connection()->query()->useWritePdo()->fromSub($limited, 'scope_budget')
            ->selectRaw('count(*) as row_count, coalesce(sum(payload_bytes),0) as total_bytes, coalesce(max(payload_bytes),0) as maximum_bytes')->first();
        if ((int) $row->row_count > self::MAX_CANDIDATES || (int) $row->total_bytes > self::MAX_TOTAL_BYTES || (int) $row->maximum_bytes > self::MAX_ROW_BYTES) {
            throw new RuntimeException('MLB tabular source count/JSON byte budget exceeded before body reads; narrow the explicit scope.');
        }

        return ['candidates' => (int) $row->row_count, 'json_bytes' => (int) $row->total_bytes];
    }

    /** @return \Generator<int,array<int,object>> */
    public function batches(Builder $query): \Generator
    {
        $last = 0;
        $seen = 0;
        while (true) {
            $rows = (clone $query)->where('s.id', '>', $last)->orderBy('s.id')->orderBy('pe.id')
                ->selectRaw('s.id as snapshot_id, s.game_id, pe.id as evaluation_id, '.$this->rowBytes().' as payload_bytes, count(*) over(partition by s.id) as identity_matches')
                ->limit(self::BATCH)->get();
            if ($rows->isEmpty()) {
                return;
            }
            $batch = [];
            $bytes = 0;
            foreach ($rows as $row) {
                if (++$seen > self::MAX_CANDIDATES || (int) $row->identity_matches !== 1 || (int) $row->payload_bytes > self::MAX_ROW_BYTES) {
                    throw new RuntimeException('MLB tabular source changed, is ambiguous or exceeds its row budget.');
                }
                if ($batch !== [] && $bytes + (int) $row->payload_bytes > self::MAX_BATCH_BYTES) {
                    yield $batch;
                    $batch = [];
                    $bytes = 0;
                }
                $batch[] = $row;
                $bytes += (int) $row->payload_bytes;
                $last = (int) $row->snapshot_id;
            }
            if ($batch !== []) {
                yield $batch;
            }
        }
    }

    /** Body CASE guards and batch SUM share one SQL snapshot, closing preflight→growth allocation races. */
    public function hydrate(Builder $base, array $identities, int &$totalBytes, int &$totalTokens, bool $current = false, bool $resolveMetadata = true): Collection
    {
        $ids = array_map(fn ($row) => (int) $row->snapshot_id, $identities);
        $evaluationIds = array_map(fn ($row) => (int) $row->evaluation_id, $identities);
        $scope = (clone $base)->whereIn('s.id', $ids)->whereIn('pe.id', $evaluationIds);
        $budget = (clone $scope)->selectRaw('sum('.$this->rowBytes().') as batch_bytes, max('.$this->rowBytes().') as maximum_bytes');
        $admitted = 'payload_budget.batch_bytes <= '.self::MAX_BATCH_BYTES.' and payload_budget.maximum_bytes <= '.self::MAX_ROW_BYTES;
        if ($current && (count($identities) > 7 || $this->connection()->transactionLevel() < 1)) {
            throw new RuntimeException('MLB current source fetch requires an owned bounded transaction.');
        }
        if ($current) {
            $admitted = '('.$this->rowBytes().') <= '.self::MAX_ROW_BYTES;
        }
        $query = ($current ? (clone $scope)->lockForUpdate() : (clone $scope)->crossJoinSub($budget, 'payload_budget'))
            ->select(['s.id', 's.sport', 's.prediction_table', 's.prediction_id', 's.game_id', 's.snapshot_run_id', 's.model_run_id', 's.model_version', 's.feature_version', 's.blend_version', 's.feature_hash', 's.generated_at', 's.game_start_at', 's.features_available_at', 's.pregame_safe', 's.availability_status', 'g.season', 'pe.id as evaluation_id', 'pe.evaluated_at as evaluation_evaluated_at', 'mr.config_hash as model_run_config_hash', 'mr.code_version as model_run_code_version'])
            ->selectRaw('case when '.$admitted.' then 1 else 0 end as payload_admitted, '.($current ? '('.$this->rowBytes().')' : 'payload_budget.batch_bytes').' as batch_bytes');
        foreach (self::BODIES as $column) {
            $name = $column === 'pe.actuals' ? 'evaluation_actuals' : substr($column, 2);
            $query->selectRaw('case when '.$admitted.' then '.$column.' else null end as '.$name);
        }
        $raw = $query->orderBy('s.id')->get();
        if ($raw->count() !== count($identities)) {
            throw new RuntimeException('MLB tabular source identity changed before body admission.');
        }
        $expected = [];
        foreach ($identities as $identity) {
            $expected[(int) $identity->snapshot_id] = (int) $identity->evaluation_id;
        }
        if ($resolveMetadata) {
            $this->resolveMetadata($raw, $current);
        }
        $seenIds = [];
        $batchTokens = 0;
        $actualBytes = 0;
        foreach ($raw as $row) {
            if (isset($seenIds[(int) $row->id]) || ($expected[(int) $row->id] ?? null) !== (int) $row->evaluation_id) {
                throw new RuntimeException('MLB tabular source pairing changed before body admission.');
            }
            $seenIds[(int) $row->id] = true;
            if ((int) $row->payload_admitted !== 1) {
                throw new RuntimeException('MLB tabular batch grew beyond its allocation budget; no JSON decoded.');
            }
            $rowBytes = 0;
            foreach (self::BODIES as $column) {
                $field = $column === 'pe.actuals' ? 'evaluation_actuals' : substr($column, 2);
                if ($row->{$field} !== null) {
                    $text = (string) $row->{$field};
                    $rowBytes += strlen($text);
                    $actualBytes += strlen($text);
                    $batchTokens += MlbTabularJsonBudget::inspect($text, self::MAX_ROW_BYTES);
                }
            }
            if ($rowBytes > self::MAX_ROW_BYTES) {
                throw new RuntimeException('MLB expanded metadata exceeded its row allocation budget.');
            }
            if ($batchTokens > self::MAX_BATCH_TOKENS) {
                throw new RuntimeException('MLB tabular batch JSON token budget exceeded before model hydration.');
            }
        }
        $totalBytes += $actualBytes;
        $totalTokens += $batchTokens;
        if ($actualBytes > self::MAX_BATCH_BYTES || $totalBytes > self::MAX_TOTAL_BYTES || $totalTokens > self::MAX_TOTAL_TOKENS) {
            throw new RuntimeException('MLB tabular cumulative allocation budget exceeded before model hydration.');
        }

        foreach ($raw as $row) {
            foreach (self::BODIES as $column) {
                $field = $column === 'pe.actuals' ? 'evaluation_actuals' : substr($column, 2);
                if ($row->{$field} !== null) {
                    json_decode($row->{$field}, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                }
            }
        }

        return $raw->map(fn ($row) => (new PredictionFeatureSnapshot)->newFromBuilder((array) $row, $this->connection()->getName()));
    }

    /** Resolve only admitted references on the evidence connection; never invoke lazy Eloquent loading. */
    /** Single inference target companion: same SQL-only compact-reference budgets and checks. */
    public function resolveInferenceTargetMetadata(array $bodies, bool $current): ?string
    {
        $row = (object) [...$bodies, 'payload_admitted' => 1];
        $bytes = 0;
        foreach (['features', 'outputs', 'market_context', 'model_metadata', 'source_timestamps', 'lineage_metadata'] as $field) {
            $raw = $row->$field ?? null;
            if ($raw !== null && ! is_string($raw)) {
                throw new RuntimeException('Inference target JSON requires bounded raw strings.');
            }
            $bytes += strlen($raw ?? '');
        }
        if ($bytes > self::MAX_ROW_BYTES || ($current && $this->connection()->transactionLevel() < 1)) {
            throw new RuntimeException('Inference target compact metadata exceeds its row budget.');
        }
        $this->resolveMetadata(collect([$row]), $current);

        return $row->model_metadata;
    }

    private function resolveMetadata(Collection $rows, bool $current): void
    {
        $references = [];
        $baseBytes = 0;
        foreach ($rows as $row) {
            if ((int) $row->payload_admitted !== 1) {
                throw new RuntimeException('MLB tabular batch grew beyond its allocation budget; no JSON decoded.');
            }
            $rowBytes = 0;
            foreach (self::BODIES as $column) {
                $field = $column === 'pe.actuals' ? 'evaluation_actuals' : substr($column, 2);
                $rowBytes += strlen((string) ($row->{$field} ?? ''));
            }
            $raw = $row->model_metadata;
            if ($raw === null) {
                $baseBytes += $rowBytes;

                continue;
            }
            MlbTabularJsonBudget::inspect($raw, self::MAX_ROW_BYTES);
            $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_string($value)) {
                $baseBytes += $rowBytes;

                continue;
            }
            if (preg_match('/\Apicksports:snapshot-metadata:gzip-v1:([a-f0-9]{64})\z/', $value, $match) !== 1) {
                throw new RuntimeException('Unsupported MLB snapshot metadata reference.');
            }
            $hash = $match[1];
            $otherBytes = $rowBytes - strlen($raw);
            $baseBytes += $otherBytes;
            $references[$hash]['rows'][] = $row;
            $references[$hash]['remaining'] = min($references[$hash]['remaining'] ?? self::MAX_ROW_BYTES, self::MAX_ROW_BYTES - $otherBytes);
        }
        if ($references === []) {
            return;
        }
        if (count($references) > self::BATCH || $baseBytes > self::MAX_BATCH_BYTES) {
            throw new RuntimeException('MLB metadata reference budget exceeded.');
        }
        // CASE/SUM are evaluated in the same SQL read as the selected body. The
        // declared expanded sizes are weighted by all referring source rows.
        $weight = 'case sha256';
        $remaining = 'case sha256';
        foreach ($references as $hash => $reference) {
            $weight .= " when '".$hash."' then ".count($reference['rows']);
            $remaining .= " when '".$hash."' then ".$reference['remaining'];
        }
        $weight .= ' else 0 end';
        $remaining .= ' else 0 end';
        $scope = $this->connection()->table('snapshot_metadata_payloads')->useWritePdo()->whereIn('sha256', array_keys($references));
        $valid = 'json_bytes >= 1 and json_bytes <= ('.$remaining.') and compressed_bytes >= 1 and compressed_bytes <= '.(self::MAX_ROW_BYTES + 65536)
            .' and '.$this->bytes('payload').' <= '.((int) ceil((self::MAX_ROW_BYTES + 65536) / 3) * 4);
        $budget = (clone $scope)->selectRaw('coalesce(sum(case when '.$valid.' then json_bytes * ('.$weight.') else '.(self::MAX_BATCH_BYTES + 1).' end),0) + '.$baseBytes.' as expanded_bytes');
        $admitted = '('.$valid.') and metadata_budget.expanded_bytes <= '.self::MAX_BATCH_BYTES;
        // A current source batch has at most seven source rows. Per-row
        // admission therefore bounds the total below 2 MiB without a stale
        // aggregate consistent-read view inside a repeatable-read transaction.
        if ($current) {
            $admitted = $valid;
            $query = (clone $scope)->lockForUpdate();
        } else {
            $query = (clone $scope)->crossJoinSub($budget, 'metadata_budget');
        }
        $payloads = $query->select(['sha256', 'compressed_sha256', 'json_bytes', 'compressed_bytes'])
            ->selectRaw('case when '.$admitted.' then payload else null end as payload')->orderBy('sha256')->get();
        if ($payloads->count() !== count($references)) {
            throw new RuntimeException('Referenced MLB metadata is missing.');
        }
        foreach ($payloads as $payload) {
            if ($payload->payload === null) {
                throw new RuntimeException('Expanded MLB metadata exceeds its allocation budget before body read.');
            }
            $compressed = base64_decode($payload->payload, true);
            if ($compressed === false || strlen($compressed) !== (int) $payload->compressed_bytes
                || ! hash_equals((string) $payload->compressed_sha256, hash('sha256', $compressed))) {
                throw new RuntimeException('MLB compressed metadata checksum mismatch.');
            }
            $json = @gzdecode($compressed, (int) $payload->json_bytes + 1);
            if ($json === false || strlen($json) !== (int) $payload->json_bytes
                || ! hash_equals((string) $payload->sha256, hash('sha256', $json))) {
                throw new RuntimeException('MLB expanded metadata checksum mismatch.');
            }
            MlbTabularJsonBudget::inspect($json, self::MAX_ROW_BYTES);
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (! is_array($value)) {
                throw new RuntimeException('MLB referenced metadata must contain an array or object.');
            }
            foreach ($references[$payload->sha256]['rows'] as $row) {
                // Hydrated representation only: storage bytes and protected source
                // identity are never rewritten or backstamped by a reader.
                $row->model_metadata = $json;
            }
        }
    }

    /** Quote payloads have the same pre-hydration budgets as feature payloads. */
    public function quotes(array $ids, int &$totalBytes, int &$totalTokens, bool $current = false): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }
        if (count($ids) > self::BATCH) {
            throw new RuntimeException('MLB quote reference batch exceeded.');
        }
        $scope = $this->connection()->table('game_odds_snapshots')->useWritePdo()->whereIn('id', $ids);
        $bytes = $this->bytes('odds_data').' + '.$this->bytes('market_context');
        $budget = (clone $scope)->selectRaw('sum('.$bytes.') as batch_bytes, max('.$bytes.') as maximum_bytes');
        $admitted = 'quote_budget.batch_bytes <= '.self::MAX_BATCH_BYTES.' and quote_budget.maximum_bytes <= '.self::MAX_ROW_BYTES;
        if ($current && (count($ids) > 7 || $this->connection()->transactionLevel() < 1)) {
            throw new RuntimeException('MLB current quote fetch requires an owned bounded transaction.');
        }
        if ($current) {
            $admitted = '('.$bytes.') <= '.self::MAX_ROW_BYTES;
        }
        $raw = ($current ? (clone $scope)->lockForUpdate() : (clone $scope)->crossJoinSub($budget, 'quote_budget'))
            ->select(['id', 'sport', 'game_table', 'game_id', 'odds_api_event_id', 'payload_hash'])
            ->selectRaw('case when '.$admitted.' then 1 else 0 end as payload_admitted')
            ->selectRaw('case when '.$admitted.' then odds_data else null end as odds_data')
            ->selectRaw('case when '.$admitted.' then market_context else null end as market_context')->get();
        $batchTokens = $batchBytes = 0;
        foreach ($raw as $row) {
            if ((int) $row->payload_admitted !== 1) {
                throw new RuntimeException('MLB quote batch exceeded its allocation budget before decode.');
            }
            foreach (['odds_data', 'market_context'] as $field) {
                if ($row->{$field} !== null) {
                    $batchBytes += strlen($row->{$field});
                    $batchTokens += MlbTabularJsonBudget::inspect($row->{$field}, self::MAX_ROW_BYTES);
                }
            }
        }
        $totalBytes += $batchBytes;
        $totalTokens += $batchTokens;
        if ($batchTokens > self::MAX_BATCH_TOKENS || $totalTokens > self::MAX_TOTAL_TOKENS || $totalBytes > self::MAX_TOTAL_BYTES) {
            throw new RuntimeException('MLB quote cumulative allocation budget exceeded before hydration.');
        }

        foreach ($raw as $row) {
            foreach (['odds_data', 'market_context'] as $field) {
                if ($row->{$field} !== null) {
                    json_decode($row->{$field}, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                }
            }
        }

        return $raw->map(fn ($row) => (new GameOddsSnapshot)->newFromBuilder((array) $row, $this->connection()->getName()))->keyBy('id');
    }

    public function games(array $ids, bool $lock = false): Collection
    {
        return Game::query()->useWritePdo()->select(['id', 'espn_event_id', 'season', 'season_type', 'home_team_id', 'away_team_id', 'game_date', 'game_time', 'status', 'home_score', 'away_score', ...MlbGameStart::FIELDS])
            ->whereKey(array_values(array_unique($ids)))->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
    }

    public function rowBytes(): string
    {
        return implode(' + ', array_map(fn (string $column): string => $this->bytes($column), self::BODIES));
    }

    private function bytes(string $column): string
    {
        return $this->connection()->getDriverName() === 'mysql'
            ? 'coalesce(octet_length(cast('.$column.' as char)),0)'
            : 'coalesce(length(cast('.$column.' as blob)),0)';
    }
}
