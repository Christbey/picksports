<?php

namespace App\Console\Commands\CFB;

use App\Models\CanonicalPrediction;
use App\Services\CFB\Predictions\CfbCandidateReview;
use App\Services\CFB\Signals\CfbFrozenSignalEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class FreezeSignalEvidenceCommand extends Command
{
    protected $signature = 'cfb:freeze-signal-evidence {--prediction=} {--output=}';

    protected $description = 'Copy already-frozen signal weights to a checksummed candidate file without refitting, publishing, or changing a release';

    public function handle(CfbFrozenSignalEvidence $freezer, CfbCandidateReview $review): int
    {
        if (! $this->option('prediction') || ! $this->option('output')) {
            $this->error('An existing --prediction public ID and a new --output file are required.');

            return self::FAILURE;
        }
        if (File::exists($this->option('output'))) {
            $this->error('The artifact output already exists; frozen candidate files are not overwritten.');

            return self::FAILURE;
        }
        $prediction = CanonicalPrediction::query()->where('public_id', $this->option('prediction'))
            ->where('sport', 'cfb')->where('phase', 'pregame')->whereIn('publication_state', ['published', 'superseded'])
            ->with(['calculationRun.release', 'calculationRun.inputSnapshot', 'sportEvent'])->first();
        if (! $prediction || ! $review->safe($prediction)) {
            $this->error('A verified, previously published CFB pregame prediction is required.');

            return self::FAILURE;
        }
        $evidence = data_get($prediction->calculationRun->inputSnapshot->inputs, 'football_signal_evidence');
        if (! is_array($evidence)) {
            $this->error('The source forecast has no frozen signal evidence.');

            return self::FAILURE;
        }
        try {
            $artifact = $freezer->freeze($evidence, $prediction->calculationRun->release->configuration,
                CarbonImmutable::now(), $prediction->public_id);
            File::put($this->option('output'), json_encode($artifact, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->line('Frozen candidate '.$artifact['content_hash'].'. No release or forecast was changed.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
