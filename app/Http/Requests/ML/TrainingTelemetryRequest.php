<?php

namespace App\Http\Requests\ML;

use App\Services\ML\TrainingMonitoringService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrainingTelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        abort_unless(config('ml.monitoring.enabled', false), 404);
        $length = (string) $this->header('Content-Length');
        abort_unless(ctype_digit($length) && (int) $length <= 32768, 413);
    }

    public function validationData(): array
    {
        return [...parent::validationData(), 'owner' => $this->header('X-Training-Owner')];
    }

    public function rules(): array
    {
        if ($this->routeIs('ml-worker.heartbeat')) {
            return [
                'metrics' => ['required', 'array:version,cpu_count,load_one,disk_free_bytes,disk_total_bytes,memory_total_bytes,training_rss_bytes'],
                'metrics.version' => ['required', 'string', 'max:64'],
                'metrics.cpu_count' => ['nullable', 'integer', 'between:1,1024'],
                'metrics.load_one' => ['nullable', 'numeric', 'between:0,100000'],
                'metrics.disk_free_bytes' => ['nullable', 'integer', 'between:0,1125899906842624'],
                'metrics.disk_total_bytes' => ['nullable', 'integer', 'between:0,1125899906842624'],
                'metrics.memory_total_bytes' => ['nullable', 'integer', 'between:0,1125899906842624'],
                'metrics.training_rss_bytes' => ['nullable', 'integer', 'between:0,1125899906842624'],
            ];
        }

        return [
            'owner' => ['required', 'uuid'], 'sequence' => ['required', 'integer', 'between:1,1000000'],
            'stage' => ['required', Rule::in(TrainingMonitoringService::STAGES)],
            'log_tail' => ['present', 'nullable', 'string', 'max:8192'],
        ];
    }
}
