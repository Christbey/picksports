<?php

use App\Models\ModelTrainingWorker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('issues a private hashed scoped credential rotates it and revokes it without logging plaintext', function () {
    $root = sys_get_temp_dir().'/picksports-credential-'.Str::uuid();
    File::ensureDirectoryExists($root, 0700);
    try {
        $this->artisan('ml:worker-credential', [
            'action' => 'issue', '--name' => 'Mini', '--sport' => ['nfl'], '--output' => $root.'/first.token',
        ])->assertSuccessful();
        $worker = ModelTrainingWorker::query()->sole();
        $first = trim(File::get($root.'/first.token'));
        expect($worker->token_hash)->toBe(hash('sha256', $first))
            ->and($worker->sports)->toBe(['nfl'])
            ->and($worker->abilities)->toBe(['training:read', 'training:claim', 'training:upload', 'training:complete', 'training:report'])
            ->and(fileperms($root.'/first.token') & 0777)->toBe(0600)
            ->and(Artisan::output())->not->toContain($first);
        $this->artisan('ml:worker-credential', [
            'action' => 'rotate', '--worker' => $worker->id, '--output' => $root.'/second.token',
        ])->assertSuccessful();
        $second = trim(File::get($root.'/second.token'));
        expect($worker->refresh()->token_hash)->toBe(hash('sha256', $second))
            ->and($second)->not->toBe($first)->and($worker->sports)->toBe(['nfl']);
        $this->artisan('ml:worker-credential', ['action' => 'revoke', '--worker' => $worker->id])->assertSuccessful();
        expect($worker->refresh()->revoked_at)->not->toBeNull();
        $this->artisan('ml:worker-credential', [
            'action' => 'issue', '--name' => 'Other', '--sport' => ['nfl'], '--output' => $root.'/first.token',
        ])->assertFailed();
        expect(trim(File::get($root.'/first.token')))->toBe($first)
            ->and(ModelTrainingWorker::query()->count())->toBe(1);
    } finally {
        File::deleteDirectory($root);
    }
});
