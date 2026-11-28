<?php

namespace App\Console\Commands\CFB;

use App\DataTransferObjects\ESPN\GameData;
use App\Models\CFB\Game;
use App\Models\CFB\Prediction;
use App\Models\MarketQuote;
use App\Models\PredictionFeatureSnapshot;
use App\Models\PredictionPostgameEnrichment;
use App\Services\CFB\CfbClosingMarketEvidenceRecorder;
use Illuminate\Console\Command;
use Throwable;

class RecordClosingMarketEvidenceCommand extends Command
{
    protected $signature = 'cfb:record-closing-market-evidence
        {--game=* : One to100 explicit unique existing CFB game IDs}
        {--apply : Record bounded stored evidence; default is dry run}';

    protected $description = 'Record separately typed postgame closing evidence without rewriting captured forecasts';

    public function handle(CfbClosingMarketEvidenceRecorder $recorder): int
    {
        $raw = (array) $this->option('game');
        if ($raw === [] || count($raw) > 100 || array_filter($raw, fn ($id) => ! is_string($id)
            || ! ctype_digit($id) || (string) (int) $id !== $id || (int) $id < 1) !== []
            || count(array_unique($raw)) !== count($raw)) {
            $this->error('Choose one to100 explicit unique positive --game IDs.');

            return self::INVALID;
        }
        $ids = array_map('intval', $raw);
        $name = (new Game)->getConnection()->getName();
        foreach ([new Prediction, new PredictionFeatureSnapshot, new PredictionPostgameEnrichment, new MarketQuote] as $model) {
            if ($model->getConnection()->getName() !== $name) {
                $this->error('All selected evidence models must share one resolved connection.');

                return self::FAILURE;
            }
        }
        $games = Game::whereIn('id', $ids)->get()->keyBy('id');
        $forecasts = Prediction::whereIn('game_id', $ids)->limit(101)->get(['id', 'game_id']);
        if ($games->count() !== count($ids) || $forecasts->count() > 100) {
            $this->error('Every selected game must exist and the entire scope must contain at most100 forecasts; no evidence written.');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('Dry run: '.count($ids).' existing games / '.$forecasts->count().' forecasts; availability not assessed and no evidence written.');

            return self::SUCCESS;
        }
        if ($games->contains(fn (Game $game) => ! in_array($game->status, GameData::finalStatuses(), true)
            || $game->home_score === null || $game->away_score === null)) {
            $this->error('All selected games must be final with both scores before apply; no evidence written.');

            return self::FAILURE;
        }
        $available = $unavailable = $missing = $appended = $reused = 0;
        foreach ($ids as $id) {
            try {
                $records = $recorder->recordForGame($id);
            } catch (Throwable) {
                $this->error("Evidence refused for game {$id}; prior completed games remain recorded. Appended {$appended}, reused {$reused}. Review current source/connection state before bounded retry.");

                return self::FAILURE;
            }
            if ($records === []) {
                $missing++;
                $this->line("Game {$id}: unavailable (forecast or entry snapshot missing/current game no longer final).");
            }
            foreach ($records as $record) {
                $record->wasRecentlyCreated ? $appended++ : $reused++;
                $record->availability_status === 'available' ? $available++ : $unavailable++;
                $this->line("Game {$id}: {$record->availability_status} / {$record->source_integrity_status}".
                    ($record->reason_codes === [] ? '' : ' ('.implode(', ', $record->reason_codes).')'));
            }
        }
        $this->info("Evidence: {$available} available, {$unavailable} unavailable, {$missing} missing; appended {$appended}, reused {$reused}. Captured snapshots and forecast grades unchanged.");

        return $unavailable + $missing === 0 ? self::SUCCESS : self::INVALID;
    }
}
