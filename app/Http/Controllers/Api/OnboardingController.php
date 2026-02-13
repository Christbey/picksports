<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(
        protected OnboardingService $onboardingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $progress = $this->onboardingService->getOnboardingProgress($request->user());

        return response()->json($progress);
    }

    public function checklist(Request $request): JsonResponse
    {
        $checklist = $this->onboardingService->getQuickStartChecklist($request->user());

        return response()->json([
            'checklist' => $checklist,
            'total_items' => count($checklist),
            'completed_items' => count(array_filter($checklist, fn ($item) => $item['completed'])),
        ]);
    }

    public function completeStep(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'step' => 'required|string|in:welcome,sport_selection,alert_setup,methodology_review',
            'data' => 'nullable|array',
        ]);

        $progress = $this->onboardingService->completeStep(
            $request->user(),
            $validated['step'],
            $validated['data'] ?? null
        );

        return response()->json([
            'message' => 'Step completed successfully',
            'progress' => $this->onboardingService->getOnboardingProgress($request->user()),
        ]);
    }

    public function savePersonalization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'favorite_sports' => 'nullable|array',
            'favorite_sports.*' => 'string|in:nfl,nba,cbb,wcbb,mlb,cfb,wnba',
            'betting_experience' => 'nullable|string|in:beginner,intermediate,advanced',
            'interests' => 'nullable|array',
            'interests.*' => 'string',
            'goals' => 'nullable|array',
            'goals.*' => 'string',
        ]);

        $progress = $this->onboardingService->savePersonalizationData(
            $request->user(),
            $validated
        );

        return response()->json([
            'message' => 'Personalization data saved successfully',
            'progress' => $this->onboardingService->getOnboardingProgress($request->user()),
        ]);
    }

    public function skip(Request $request): JsonResponse
    {
        $progress = $this->onboardingService->skipOnboarding($request->user());

        return response()->json([
            'message' => 'Onboarding skipped successfully',
            'progress' => $this->onboardingService->getOnboardingProgress($request->user()),
        ]);
    }

    public function steps(): JsonResponse
    {
        $steps = $this->onboardingService->getAvailableSteps();

        return response()->json(['steps' => $steps]);
    }
}
