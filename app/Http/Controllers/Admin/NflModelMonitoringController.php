<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NflModelMonitoringInvokeRequest;
use App\Services\NFL\NflModelMonitoringService;
use Inertia\Inertia;
use Inertia\Response;

class NflModelMonitoringController extends Controller
{
    public function __invoke(NflModelMonitoringInvokeRequest $request, NflModelMonitoringService $monitoring): Response
    {
        $filters = $request->validated();
        $evidence = array_filter([
            'from_season' => $filters['evidence_season'] ?? null,
            'to_season' => $filters['evidence_season'] ?? null,
            'model_version' => $filters['evidence_model'] ?? null,
            'validation_from_week' => $filters['evidence_week'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        return Inertia::render('Admin/NflModelMonitoring', $monitoring->dashboard(
            $request->string('artifact')->trim()->toString() ?: null,
            $evidence,
        ));
    }
}
