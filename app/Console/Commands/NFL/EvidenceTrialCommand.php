<?php

namespace App\Console\Commands\NFL;

use App\Models\ModelArtifact;
use App\Services\NFL\NflEvidenceTrialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class EvidenceTrialCommand extends Command
{
    protected $signature = 'nfl:evidence-trial {action : register, report or deactivate} {--spec= : Local JSON preregistration specification} {--artifact= : Trial artifact ID}';

    protected $description = 'Preregister or evaluate bounded NFL evidence shadow trials without publishing forecasts';

    public function handle(NflEvidenceTrialService $trials): int
    {
        try {
            if ($this->argument('action') === 'register') {
                $path = $this->option('spec');
                if (! is_string($path) || ! File::isFile($path)) {
                    throw new \InvalidArgumentException('Supply a readable local --spec JSON file.');
                }
                $artifact = $trials->register(json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR));
                $result = ['artifact_id' => $artifact->id, 'status' => 'preregistered_shadow_only', 'protocol' => $trials->protocol($artifact)];
            } elseif ($this->argument('action') === 'deactivate' && $this->option('artifact')) {
                $artifact = ModelArtifact::findOrFail($this->option('artifact'));
                $trials->protocol($artifact);
                $artifact->update(['status' => 'retired']);
                $result = ['artifact_id' => $artifact->id, 'status' => 'retired', 'public_output_changed' => false];
            } elseif ($this->argument('action') === 'report' && $this->option('artifact')) {
                $result = $trials->evaluate(ModelArtifact::findOrFail($this->option('artifact')));
            } else {
                throw new \InvalidArgumentException('Use register --spec or report --artifact.');
            }
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
