<?php

namespace App\Services\CFB\Predictions;

use App\Models\SportEvent;
use Carbon\CarbonImmutable;

class CfbSpreadCalibrationDataset
{
    public function rows(string $releaseVersion): array
    {
        // Share provenance and exact full-output replay checks with moneyline.
        // A release fixing only missing opponents must not discard unchanged FBS evidence.
        $frozen = app(CfbFrozenMoneylineCalibrationDataset::class)->rows($releaseVersion);
        $completed = SportEvent::query()->where('sport', 'cfb')
            ->whereHas('cfbGame', fn ($q) => $q->where('status', 'STATUS_FINAL'))->count();
        $excluded = $frozen['excluded'];
        $excluded['no_verified_contemporaneous_prediction'] = max(0, $completed - count($frozen['rows']));
        $rows = [];
        foreach ($frozen['rows'] as $row) {
            // The last stored quote before kickoff is the user's closing-line convention.
            $quote = app(CfbStoredPregameQuote::class)->latest($row['game_id'], 'spreads', 'home', CarbonImmutable::parse($row['kickoff']));
            if (! $quote || ! is_numeric($quote->line) || ! is_numeric($row['model_margin'])
                || abs((float) $quote->line * 2 - round((float) $quote->line * 2)) > 0.000001) {
                $excluded['missing_result_or_stored_closing_line'] = ($excluded['missing_result_or_stored_closing_line'] ?? 0) + 1;

                continue;
            }
            $rows[] = [...$row, 'quote_id' => $quote->id, 'home_line' => (float) $quote->line];
        }

        return ['rows' => $rows, 'excluded' => $excluded, 'configuration_hashes' => $frozen['configuration_hashes']];
    }
}
