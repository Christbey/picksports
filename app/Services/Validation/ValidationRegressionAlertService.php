<?php

namespace App\Services\Validation;

use App\Models\User;
use App\Models\ValidationRun;
use App\Notifications\ValidationRegressionAlert;
use Illuminate\Support\Facades\Notification;

class ValidationRegressionAlertService
{
    public function maybeNotify(ValidationRun $run): void
    {
        if (! config('validation.regression_alerts.enabled', true)) {
            return;
        }

        $previousRun = ValidationRun::query()
            ->where('command_name', 'healthcheck:validate-data')
            ->where('scope', $run->scope)
            ->where('id', '<', $run->id)
            ->latest('id')
            ->first();

        if (! $previousRun) {
            return;
        }

        $currentSummary = is_array($run->summary) ? $run->summary : [];
        $previousSummary = is_array($previousRun->summary) ? $previousRun->summary : [];

        $delta = [
            'failing' => ((int) ($currentSummary['failing'] ?? 0)) - ((int) ($previousSummary['failing'] ?? 0)),
            'warning' => ((int) ($currentSummary['warning'] ?? 0)) - ((int) ($previousSummary['warning'] ?? 0)),
            'passing' => ((int) ($currentSummary['passing'] ?? 0)) - ((int) ($previousSummary['passing'] ?? 0)),
        ];

        $failingThreshold = (int) config('validation.regression_alerts.failing_delta_threshold', 1);
        $warningThreshold = (int) config('validation.regression_alerts.warning_delta_threshold', 2);

        $isRegression = $delta['failing'] >= $failingThreshold
            || ($delta['failing'] > 0)
            || ($delta['warning'] >= $warningThreshold && $delta['passing'] <= 0);

        if (! $isRegression) {
            return;
        }

        $admins = User::query()
            ->where('is_admin', true)
            ->whereNotNull('email')
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new ValidationRegressionAlert($run, $delta));
    }
}
