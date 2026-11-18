<?php

namespace App\Services\Validation;

use App\Models\Healthcheck;
use App\Models\ValidationFinding;
use App\Models\ValidationRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class ValidationRunRecorder
{
    public function recent(string $scope, string $validationScope, int $minutes): ?ValidationRun
    {
        $run = $this->matching($scope, $validationScope)->latest('id')->first();
        if (! $run || ! $this->checksCompleted($run)
            || data_get($run->summary, 'execution_status', 'completed') !== 'completed'
            || $run->started_at->gt(now()) || $run->completed_at->lt(now()->subMinutes($minutes))) {
            return null;
        }

        return $run;
    }

    public function begin(string $scope, string $validationScope, bool $skipAlerts = false): ValidationRun
    {
        $this->matching($scope, $validationScope)->where('status', 'running')
            ->where('started_at', '<', now()->subHours(2))->update([
                'status' => 'interrupted', 'completed_at' => now(),
                'summary' => json_encode(['validation_scope' => $validationScope, 'execution_status' => 'failed',
                    'error' => 'Validation process did not complete; superseded by a retry.']),
            ]);

        return ValidationRun::query()->create([
            'command_name' => 'healthcheck:validate-data', 'scope' => $scope, 'status' => 'running',
            'summary' => ['validation_scope' => $validationScope, 'execution_status' => 'running', 'alerts_skipped' => $skipAlerts], 'started_at' => now(),
        ]);
    }

    /** Persist both views together so the admin summary cannot disagree with the audit history. */
    public function record(ValidationRun $run, string $sport, array $result): void
    {
        DB::transaction(function () use ($run, $sport, $result): void {
            $metadata = (array) ($result['metadata'] ?? []);
            $action = $result['recommended_action'] ?? null;
            Healthcheck::query()->create([
                'sport' => $sport, 'check_type' => $result['check_type'], 'status' => $result['status'],
                'message' => $result['message'], 'checked_at' => now(),
                'metadata' => [...$metadata, 'validation_run_id' => $run->id,
                    'validation_scope' => $run->summary['validation_scope'], 'recommended_action' => $action],
            ]);
            ValidationFinding::query()->create([
                'validation_run_id' => $run->id, 'sport' => $sport, 'check_type' => $result['check_type'],
                'status' => $result['status'], 'severity' => $result['severity'] ?? $result['status'],
                'message' => $result['message'], 'facts' => $metadata, 'recommended_action' => $action, 'detected_at' => now(),
            ]);
        });
    }

    public function complete(ValidationRun $run, ?Throwable $error = null): void
    {
        $counts = $run->findings()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $executionFailed = $error !== null || $run->findings()->where('facts->execution_failed', true)->exists();
        $status = $executionFailed || (int) $counts->get('failing', 0) > 0 ? 'failing'
            : ((int) $counts->get('warning', 0) > 0 ? 'warning' : 'passing');
        $summary = [
            ...($run->summary ?? []), 'total_findings' => (int) $counts->sum(),
            'passing' => (int) $counts->get('passing', 0), 'warning' => (int) $counts->get('warning', 0),
            'failing' => (int) $counts->get('failing', 0), 'execution_status' => $executionFailed ? 'failed' : 'completed',
            'checks_execution_status' => $executionFailed ? 'failed' : 'completed',
        ];
        if ($error !== null) {
            $summary['error_class'] = $error::class;
        }
        $run->forceFill(['status' => $status, 'summary' => $summary, 'completed_at' => now()])->save();
    }

    public function recordPostProcessingError(ValidationRun $run, string $component, Throwable $error): void
    {
        $summary = $run->summary ?? [];
        $summary['execution_status'] = 'failed';
        $summary['post_processing_errors'][$component] = $error::class;
        $run->forceFill(['summary' => $summary])->save();
    }

    /** Optional summary or notification failures must not hide completed check findings. */
    public function completedChecksQuery(string $scope, string $validationScope): Builder
    {
        return $this->matching($scope, $validationScope)->whereIn('status', ['passing', 'warning', 'failing'])
            ->whereNotNull('completed_at')->where('completed_at', '<=', now())
            ->where(function (Builder $query): void {
                $query->where('summary->checks_execution_status', 'completed')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('summary->checks_execution_status')
                        ->where(fn ($execution) => $execution->whereNull('summary->execution_status')->orWhere('summary->execution_status', 'completed')));
            });
    }

    public function checksCompleted(ValidationRun $run): bool
    {
        return $run->completed_at !== null && $run->completed_at->lte(now())
            && in_array($run->status, ['passing', 'warning', 'failing'], true)
            && data_get($run->summary, 'checks_execution_status', data_get($run->summary, 'execution_status', 'completed')) === 'completed';
    }

    public function previousCompleted(ValidationRun $run): ?ValidationRun
    {
        return $this->completedChecksQuery((string) $run->scope, data_get($run->summary, 'validation_scope', 'full'))
            ->where('id', '<', $run->id)->latest('id')->first();
    }

    private function matching(string $scope, string $validationScope): Builder
    {
        return ValidationRun::query()->where('command_name', 'healthcheck:validate-data')->where('scope', $scope)
            ->where(function (Builder $query) use ($validationScope): void {
                $query->where('summary->validation_scope', $validationScope);
                if ($validationScope === 'full') {
                    $query->orWhereNull('summary->validation_scope');
                }
            });
    }
}
