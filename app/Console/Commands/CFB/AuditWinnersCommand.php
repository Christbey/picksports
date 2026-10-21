<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbWinnerAudit;
use App\Services\CFB\Predictions\CfbWinnerAuditDataset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use JsonException;

class AuditWinnersCommand extends Command
{
    protected $signature = 'cfb:audit-winners {--season=} {--dataset=} {--validation-from=} {--test-from=} {--rivalry-pair=*} {--output=}';

    protected $description = 'Read-only winner-first audit of frozen forecasts, confidence, matchups, market effects, rivalries, and selection ranking';

    public function handle(CfbWinnerAudit $audit, CfbWinnerAuditDataset $exporter): int
    {
        $validator = Validator::make(['season' => $this->option('season') ?? now()->year,
            'validation' => $this->option('validation-from'), 'test' => $this->option('test-from'),
            'pairs' => $this->option('rivalry-pair')],
            ['season' => 'required|integer|min:2000|max:2100', 'validation' => 'required|date_format:Y-m-d',
                'test' => 'required|date_format:Y-m-d|after:validation', 'pairs.*' => ['regex:/^[A-Za-z0-9-]+:[A-Za-z0-9-]+$/']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        try {
            $path = $this->option('dataset');
            if ($path && ! File::isFile($path)) {
                throw new InvalidArgumentException('The frozen dataset file does not exist.');
            }
            if ($path && $this->option('output') && realpath($path) === realpath($this->option('output'))) {
                throw new InvalidArgumentException('The report must not overwrite its frozen input dataset.');
            }
            $dataset = $path ? json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR)
                : $exporter->export((int) ($this->option('season') ?? now()->year));
            if (! is_array($dataset)) {
                throw new InvalidArgumentException('The dataset must be a versioned JSON object.');
            }
            $result = $audit->evaluate($dataset, $this->option('validation-from'), $this->option('test-from'), $this->option('rivalry-pair'));
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if ($this->option('output')) {
                File::put($this->option('output'), $json);
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
