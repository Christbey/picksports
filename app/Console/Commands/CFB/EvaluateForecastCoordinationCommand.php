<?php

namespace App\Console\Commands\CFB;

use App\Services\CFB\Predictions\CfbForecastCoordinationStudy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

class EvaluateForecastCoordinationCommand extends Command
{
    protected $signature = 'cfb:evaluate-forecast-coordination {--dataset=} {--validation-from=} {--check-dataset=} {--output=}';

    protected $description = 'Evaluate one frozen, bounded CFB forecast combination without writing predictions or changing releases';

    public function handle(CfbForecastCoordinationStudy $study): int
    {
        try {
            $date = (string) $this->option('validation-from');
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || CarbonImmutable::createFromFormat('!Y-m-d', $date, 'America/Chicago')->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('A valid --validation-from=YYYY-MM-DD is required.');
            }
            $rows = $this->dataset((string) $this->option('dataset'));
            $training = $validation = [];
            foreach ($rows as $row) {
                if (CarbonImmutable::parse($row['kickoff'])->setTimezone('America/Chicago')->toDateString() < $date) {
                    $training[] = $row;
                } else {
                    $validation[] = $row;
                }
            }
            $artifact = $study->select($training);
            $artifact['frozen_at'] = now()->toIso8601String();
            $evaluation = $study->evaluate($artifact, $validation);
            $newEvaluation = $this->option('check-dataset')
                ? $study->evaluate($artifact, $this->dataset((string) $this->option('check-dataset'))) : null;
            $result = ['artifact' => $artifact, 'historical_validation' => $evaluation, 'later_check' => $newEvaluation,
                'decision' => ['action' => $evaluation['temporal_validation_passed'] ? 'eligible_for_limited_forecast_release' : 'retain_published_primary', 'forecast_changed' => false,
                    'reason' => $evaluation['reason'], 'market_policy' => 'Experimental coordinator: at most 10 percent, one margin point, two total points; indirect market signals removed.',
                    'scope' => 'Forecast accuracy only; no prices, profitability, approvals, generation, provider refresh, or production configuration changes.']];
            $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if ($this->option('output')) {
                File::put((string) $this->option('output'), $json);
            }
            $this->line($json);

            return self::SUCCESS;
        } catch (InvalidArgumentException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function dataset(string $path): array
    {
        if ($path === '' || ! File::isFile($path)) {
            throw new InvalidArgumentException('An existing frozen JSON dataset file is required.');
        }
        $rows = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($rows) || ! array_is_list($rows) || ! collect($rows)->every(fn ($row) => is_array($row) && isset($row['kickoff']))) {
            throw new InvalidArgumentException('The dataset must be a list of frozen forecast rows.');
        }

        return $rows;
    }
}
