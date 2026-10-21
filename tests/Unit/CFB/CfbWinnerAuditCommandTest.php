<?php

use App\Services\CFB\Predictions\CfbWinnerAuditDataset;
use Tests\TestCase;

uses(TestCase::class);

it('requires ordered validation and test dates before attempting an export', function () {
    $this->mock(CfbWinnerAuditDataset::class)->shouldNotReceive('export');
    $this->artisan('cfb:audit-winners', ['--validation-from' => '2026-10-12', '--test-from' => '2026-10-03'])
        ->assertFailed();
});

it('evaluates a frozen file without database access or overwriting the dataset', function () {
    $this->mock(CfbWinnerAuditDataset::class)->shouldNotReceive('export');
    $input = tempnam(sys_get_temp_dir(), 'cfb-winner-input-');
    $output = tempnam(sys_get_temp_dir(), 'cfb-winner-report-');
    $dataset = json_encode(['schema' => 'cfb-winner-audit-dataset-v1', 'rows' => []], JSON_THROW_ON_ERROR);
    file_put_contents($input, $dataset);
    try {
        $options = ['--dataset' => $input, '--validation-from' => '2026-10-03', '--test-from' => '2026-10-12'];
        $this->artisan('cfb:audit-winners', [...$options, '--output' => $output])->assertSuccessful();
        $report = json_decode(file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
        expect($report['promotion']['allowed'])->toBeFalse()->and(file_get_contents($input))->toBe($dataset);
        $this->artisan('cfb:audit-winners', [...$options, '--output' => $input])->assertFailed();
        expect(file_get_contents($input))->toBe($dataset);
    } finally {
        unlink($input);
        unlink($output);
    }
});

it('rejects malformed datasets and invalid rivalry pairs', function () {
    $this->mock(CfbWinnerAuditDataset::class)->shouldNotReceive('export');
    $this->artisan('cfb:audit-winners', ['--dataset' => '/tmp/nonexistent-cfb-winner-fixture',
        '--validation-from' => '2026-10-03', '--test-from' => '2026-10-12'])->assertFailed();
    $this->artisan('cfb:audit-winners', ['--validation-from' => '2026-10-03', '--test-from' => '2026-10-12',
        '--rivalry-pair' => ['not-a-pair']])->assertFailed();
});
