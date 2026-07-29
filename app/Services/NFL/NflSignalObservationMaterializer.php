<?php

namespace App\Services\NFL;

use App\Models\NFL\Game;
use App\Models\NflSignalObservation;
use App\Models\PredictionFeatureSnapshot;
use App\Support\NflReasonCodeCatalog;
use InvalidArgumentException;

class NflSignalObservationMaterializer
{
    public function __construct(
        private readonly NflReasonCodeCatalog $reasonCodeCatalog,
    ) {}

    /**
     * @return array{created:int,existing:int,signals:int}
     */
    public function materialize(PredictionFeatureSnapshot $snapshot): array
    {
        $snapshot->loadMissing('modelRun');

        if (strtolower((string) $snapshot->sport) !== 'nfl') {
            throw new InvalidArgumentException('Only NFL prediction feature snapshots can be materialized.');
        }

        if (
            $snapshot->modelRun === null
            || empty($snapshot->snapshot_run_id)
            || empty($snapshot->feature_hash)
            || empty($snapshot->modelRun->config_hash)
        ) {
            throw new InvalidArgumentException("Snapshot {$snapshot->id} does not have complete model lineage.");
        }

        $game = Game::query()->find($snapshot->game_id);
        if ($game === null) {
            throw new InvalidArgumentException("Snapshot {$snapshot->id} does not reference an NFL game.");
        }

        $signals = $this->signals($snapshot);
        $created = 0;

        foreach ($signals as $signal) {
            $definitionHash = $this->hashPayload($signal['payload']);
            $attributes = [
                'prediction_feature_snapshot_id' => $snapshot->id,
                'signal_type' => $signal['type'],
                'signal_key' => $signal['key'],
            ];
            $values = [
                'model_run_id' => $snapshot->model_run_id,
                'prediction_id' => $snapshot->prediction_id,
                'game_id' => $snapshot->game_id,
                'season' => (int) $game->season,
                'week' => is_numeric($game->week) ? (int) $game->week : null,
                'snapshot_run_id' => $snapshot->snapshot_run_id,
                'model_version' => $snapshot->model_version,
                'feature_version' => $snapshot->feature_version,
                'blend_version' => $snapshot->blend_version,
                'config_hash' => $snapshot->modelRun->config_hash,
                'feature_hash' => $snapshot->feature_hash,
                'label' => $signal['label'],
                'market_type' => $signal['market_type'],
                'direction' => $signal['direction'],
                'action' => $signal['action'],
                'is_actionable' => $signal['is_actionable'],
                'is_diagnostic' => $signal['is_diagnostic'],
                'requires_market' => $signal['requires_market'],
                'pregame_safe' => (bool) $snapshot->pregame_safe,
                'availability_status' => $snapshot->availability_status,
                'signal_payload' => $signal['payload'],
                'definition_hash' => $definitionHash,
                'observation_hash' => hash('sha256', implode('|', [
                    $snapshot->snapshot_run_id,
                    $snapshot->model_run_id,
                    $snapshot->modelRun->config_hash,
                    $snapshot->feature_hash,
                    $signal['type'],
                    $signal['key'],
                    $definitionHash,
                ])),
                'observed_at' => $snapshot->generated_at,
                'game_start_at' => $snapshot->game_start_at,
            ];

            $observation = NflSignalObservation::query()->firstOrCreate($attributes, $values);
            if ($observation->wasRecentlyCreated) {
                $created++;
            }
        }

        return [
            'created' => $created,
            'existing' => count($signals) - $created,
            'signals' => count($signals),
        ];
    }

    /**
     * @return list<array{
     *     type:string,
     *     key:string,
     *     label:?string,
     *     market_type:?string,
     *     direction:?string,
     *     action:?string,
     *     is_actionable:bool,
     *     is_diagnostic:bool,
     *     requires_market:bool,
     *     payload:array<string,mixed>
     * }>
     */
    private function signals(PredictionFeatureSnapshot $snapshot): array
    {
        $analysis = data_get($snapshot->model_metadata, 'analysis_layer');
        if (! is_array($analysis)) {
            return [];
        }

        return collect()
            ->concat($this->tokenSignals('reason_code', (array) ($analysis['reason_codes'] ?? []), $analysis))
            ->concat($this->tokenSignals('risk_flag', (array) ($analysis['risk_flags'] ?? []), $analysis))
            ->concat($this->matchedRuleSignals((array) data_get($analysis, 'bet_rule_evaluation.matched_rules', [])))
            ->concat($this->passRuleSignals((array) data_get($analysis, 'bet_rule_evaluation.pass_rules', [])))
            ->concat($this->validatedComboSignals((array) ($analysis['validated_signals'] ?? [])))
            ->unique(fn (array $signal): string => $signal['type'].'|'.$signal['key'])
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $tokens
     * @param  array<string,mixed>  $analysis
     * @return list<array<string,mixed>>
     */
    private function tokenSignals(string $type, array $tokens, array $analysis): array
    {
        return collect($tokens)
            ->map(fn (mixed $token): string => trim((string) $token))
            ->filter()
            ->unique()
            ->map(function (string $token) use ($type, $analysis): array {
                $storedMetadata = data_get($analysis, "reason_code_metadata.{$token}");
                $metadata = is_array($storedMetadata)
                    ? $storedMetadata
                    : $this->reasonCodeCatalog->metadata($token);

                return [
                    'type' => $type,
                    'key' => $token,
                    'label' => $this->nullableString($metadata['label'] ?? null),
                    'market_type' => $this->normalizeMarketType($metadata['market_type'] ?? null),
                    'direction' => $this->nullableString($metadata['direction'] ?? null),
                    'action' => $type === 'risk_flag' ? 'risk' : 'signal',
                    'is_actionable' => (bool) ($metadata['is_actionable'] ?? false),
                    'is_diagnostic' => $type === 'risk_flag' || (bool) ($metadata['is_diagnostic'] ?? false),
                    'requires_market' => (bool) ($metadata['requires_market'] ?? false),
                    'payload' => [
                        'source_path' => "analysis_layer.{$type}s",
                        'metadata' => $metadata,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $rules
     * @return list<array<string,mixed>>
     */
    private function matchedRuleSignals(array $rules): array
    {
        return collect($rules)
            ->filter(fn (mixed $rule): bool => is_array($rule) && ! empty($rule['name']))
            ->map(function (array $rule): array {
                $action = (string) ($rule['action'] ?? 'lean');

                return [
                    'type' => 'matched_rule',
                    'key' => (string) $rule['name'],
                    'label' => $this->nullableString($rule['label'] ?? null),
                    'market_type' => $this->normalizeMarketType($rule['market'] ?? null),
                    'direction' => null,
                    'action' => $action,
                    'is_actionable' => in_array($action, ['play', 'lean'], true),
                    'is_diagnostic' => false,
                    'requires_market' => ! empty($rule['market']),
                    'payload' => [
                        'source_path' => 'analysis_layer.bet_rule_evaluation.matched_rules',
                        'rule' => $rule,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $ruleNames
     * @return list<array<string,mixed>>
     */
    private function passRuleSignals(array $ruleNames): array
    {
        return collect($ruleNames)
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->unique()
            ->map(fn (string $name): array => [
                'type' => 'pass_rule',
                'key' => $name,
                'label' => null,
                'market_type' => null,
                'direction' => null,
                'action' => 'pass',
                'is_actionable' => true,
                'is_diagnostic' => true,
                'requires_market' => false,
                'payload' => [
                    'source_path' => 'analysis_layer.bet_rule_evaluation.pass_rules',
                    'rule_name' => $name,
                    'definition_source' => 'snapshot_occurrence_only',
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<mixed>  $combos
     * @return list<array<string,mixed>>
     */
    private function validatedComboSignals(array $combos): array
    {
        return collect($combos)
            ->filter(fn (mixed $combo): bool => is_array($combo) && ! empty($combo['name']))
            ->map(fn (array $combo): array => [
                'type' => 'validated_combo',
                'key' => (string) $combo['name'],
                'label' => $this->nullableString($combo['label'] ?? null),
                'market_type' => $this->normalizeMarketType($combo['market'] ?? 'winner'),
                'direction' => null,
                'action' => (string) ($combo['tier'] ?? 'watchlist'),
                'is_actionable' => true,
                'is_diagnostic' => false,
                'requires_market' => false,
                'payload' => [
                    'source_path' => 'analysis_layer.validated_signals',
                    'combo' => $combo,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->sortRecursively($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    private function normalizeMarketType(mixed $marketType): ?string
    {
        $marketType = strtolower(trim((string) $marketType));

        return match ($marketType) {
            'winner', 'moneyline', 'h2h' => 'winner',
            'spread', 'spreads', 'ats' => 'spread',
            'total', 'totals', 'over_under' => 'total',
            '' => null,
            default => $marketType,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
