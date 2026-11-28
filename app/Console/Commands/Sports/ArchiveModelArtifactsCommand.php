<?php

namespace App\Console\Commands\Sports;

use App\Exceptions\ExistingArtifactOwnershipRefused;
use App\Models\ModelArtifact;
use App\Services\ML\ModelArtifactRegistry;
use Illuminate\Console\Command;

class ArchiveModelArtifactsCommand extends Command
{
    protected $signature = 'sports:archive-model-artifacts
        {--artifact=* : Restrict to one or more artifact UUIDs}';

    protected $description = 'Move hash-verifiable registered model artifacts to immutable run-specific paths';

    public function handle(ModelArtifactRegistry $artifactsRegistry): int
    {
        $ids = array_values(array_filter((array) $this->option('artifact')));
        $artifacts = ModelArtifact::query()
            ->with('trainingRun')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->get();
        $archived = 0;
        $mismatched = 0;
        $refused = 0;

        foreach ($artifacts as $artifact) {
            try {
                $artifactsRegistry->archiveExisting($artifact);
                $archived++;
            } catch (ExistingArtifactOwnershipRefused) {
                $refused++;

                continue;
            } catch (\RuntimeException) {
                try {
                    $artifactsRegistry->invalidateArchiveFailure($artifact);
                } catch (ExistingArtifactOwnershipRefused) {
                    $refused++;

                    continue;
                }
                $mismatched++;

                continue;
            }
        }

        $this->info("Archived {$archived} artifact(s) to immutable paths.");
        if ($mismatched > 0) {
            $this->warn("Skipped {$mismatched} artifact(s) whose file was missing or no longer matched the registered hash.");
        }

        if ($refused > 0) {
            $this->error("Refused {$refused} artifact(s) without current storage ownership admission; no status was changed for them.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
