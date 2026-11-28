<?php

namespace App\Console\Commands\Sports;

use App\Models\ModelTrainingWorker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TrainingWorkerCredentialCommand extends Command
{
    protected $signature = 'ml:worker-credential {action : issue, rotate, or revoke}
        {--worker= : Existing worker UUID for rotate or revoke}
        {--name= : Worker display name when issuing}
        {--sport=* : Allowed sports when issuing (nfl or mlb)}
        {--report : Add reporting permission when rotating an existing worker credential}
        {--days=30 : Credential lifetime, 1 through 365 days}
        {--output= : New private token file; must not already exist}';

    protected $description = 'Manage scoped remote-training credentials without granting application user access';

    public function handle(): int
    {
        $handle = null;
        $written = false;
        try {
            $action = (string) $this->argument('action');
            if (! in_array($action, ['issue', 'rotate', 'revoke'], true)) {
                throw new RuntimeException('Choose issue, rotate, or revoke.');
            }
            $id = $this->option('worker');
            if ($action !== 'issue' && (! is_string($id) || ! Str::isUuid($id))) {
                throw new RuntimeException('A worker UUID is required.');
            }
            if ($action === 'revoke') {
                ModelTrainingWorker::query()->findOrFail($id)->update(['revoked_at' => now()]);
                $this->info('Worker credential revoked.');

                return self::SUCCESS;
            }
            $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
            if ($days === false || $days < 1 || $days > 365) {
                throw new RuntimeException('Credential lifetime must be 1 through 365 days.');
            }
            $sports = array_values(array_unique((array) $this->option('sport')));
            $name = (string) $this->option('name');
            if ($action === 'issue' && ($sports === [] || array_diff($sports, ['nfl', 'mlb']) !== [] || trim($name) === '' || strlen($name) > 100)) {
                throw new RuntimeException('Issue requires a name and explicit nfl/mlb sports.');
            }
            $path = $this->option('output');
            if (! is_string($path) || ! str_starts_with($path, '/')) {
                throw new RuntimeException('An absolute output path for a new private token file is required.');
            }
            $oldMask = umask(0077);
            try {
                $handle = @fopen($path, 'x');
            } finally {
                umask($oldMask);
            }
            if (! is_resource($handle)) {
                throw new RuntimeException('Token file could not be created; use a new path.');
            }
            $written = true;
            $token = bin2hex(random_bytes(48));
            $worker = DB::transaction(function () use ($action, $id, $days, $sports, $name, $token, $handle): ModelTrainingWorker {
                $attributes = ['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($days), 'revoked_at' => null];
                if ($action === 'issue') {
                    $worker = ModelTrainingWorker::query()->create([
                        ...$attributes, 'id' => (string) Str::uuid(), 'name' => $name, 'sports' => $sports,
                        'abilities' => ['training:read', 'training:claim', 'training:upload', 'training:complete', 'training:report'],
                    ]);
                } else {
                    $worker = ModelTrainingWorker::query()->lockForUpdate()->findOrFail($id);
                    if ($this->option('report')) {
                        $attributes['abilities'] = array_values(array_unique([...$worker->abilities, 'training:report']));
                    }
                    $worker->update($attributes);
                }
                if (fwrite($handle, $token."\n") !== strlen($token) + 1 || ! fflush($handle)) {
                    throw new RuntimeException('Unable to write private token file.');
                }

                return $worker;
            });
            $this->info('Worker: '.$worker->id);
            $this->line('Credential saved to the private output file. Plaintext was not logged.');

            return self::SUCCESS;
        } catch (Throwable) {
            if ($written) {
                @unlink((string) $this->option('output'));
            }
            $this->error('Credential operation failed; check action, worker, scope, lifetime, and new output path.');

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
