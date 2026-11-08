<?php

namespace App\Services\Api\V2;

use Illuminate\Support\Arr;

/** Opt-in board delivery; the full prediction API remains unchanged. */
class CfbPredictionBoardPayload
{
    private const LIVE_FIELDS = [
        'status', 'live_predicted_spread', 'live_predicted_total', 'live_win_probability',
        'live_seconds_remaining', 'live_outs_remaining', 'live_updated_at',
        'live_status', 'live_unavailable_reason',
    ];

    private const LIVE_GAME_FIELDS = [
        'status', 'home_score', 'away_score', 'period', 'clock', 'inning', 'inning_half',
        'balls', 'strikes', 'outs', 'home_linescores', 'away_linescores',
    ];

    public function response(array $predictions, array $pagination, ?string $previousVersion = null): array
    {
        $rows = array_map($this->compact(...), $predictions);
        $static = array_map(function (array $row): array {
            $result = Arr::except($row, self::LIVE_FIELDS);
            $result['game'] = Arr::except($row['game'] ?? [], self::LIVE_GAME_FIELDS);

            return $result;
        }, $rows);
        $version = hash('sha256', json_encode(['schema' => 1, 'data' => $static, 'pagination' => $pagination], JSON_THROW_ON_ERROR));
        $live = $previousVersion !== null && hash_equals($version, $previousVersion);

        return [
            'data' => $live ? array_map(function (array $row): array {
                return [
                    ...Arr::only($row, ['id', 'sport', 'game_id', ...self::LIVE_FIELDS]),
                    'game' => Arr::only($row['game'] ?? [], ['id', ...self::LIVE_GAME_FIELDS]),
                ];
            }, $rows) : $rows,
            'board' => ['version' => $version, 'mode' => $live ? 'live' : 'replace'],
        ];
    }

    public function compact(array $prediction): array
    {
        $row = Arr::only($prediction, [
            'id', 'sport', 'game_id', 'game', 'projection', 'comparison_forecasts',
            'actual_spread', 'actual_total', 'spread_error', 'total_error', 'winner_correct',
            'graded_at', 'created_at', 'updated_at', 'model_bet_context', 'weather_forecast',
            'value_signal', 'cfb_signal_context', 'recommendation', ...self::LIVE_FIELDS,
        ]);
        if (is_array($row['value_signal'] ?? null)) {
            $row['value_signal'] = Arr::only($row['value_signal'], [
                'best', 'decision_status', 'decision_summary', 'decision_notes', 'has_playable_value',
                'play_count', 'spread_assessment', 'football_signals', 'signal_contributions',
            ]);
            $football = $row['value_signal']['football_signals'] ?? null;
            if (is_array($football)) {
                $row['value_signal']['football_signals'] = [
                    ...Arr::only($football, ['catalog_count', 'applied', 'triggered', 'missing_inputs']),
                    'signals' => array_values(array_map(
                        fn (array $signal): array => Arr::only($signal, ['id', 'side', 'label', 'market', 'contribution_points', 'status', 'sample_games', 'matched']),
                        array_filter($football['signals'] ?? [], fn (array $signal): bool => (bool) ($signal['matched'] ?? false)),
                    )),
                ];
            }
            $contributions = $row['value_signal']['signal_contributions'] ?? null;
            if (is_array($contributions) && isset($contributions['signals'])) {
                $row['value_signal']['signal_contributions'] = [
                    ...Arr::only($contributions, ['active_spread_signals', 'active_total_signals']),
                    // Keep inactive entries too: list presence controls the existing disclosure.
                    'signals' => array_map(fn (array $signal): array => Arr::only($signal, ['signal', 'market', 'active', 'contribution_points']), $contributions['signals']),
                ];
            }
        }

        return $row;
    }
}
