<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NFL\NflModelMonitoringService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NflModelMonitoringController extends Controller
{
    public function __invoke(Request $request, NflModelMonitoringService $monitoring): Response
    {
        $filters = $request->validate([
            'evidence_season' => ['nullable', 'integer', 'between:2000,2100'],
            'evidence_model' => ['nullable', 'string', 'max:128'],
            'evidence_week' => ['nullable', 'integer', 'between:2,22'],
        ]);
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
