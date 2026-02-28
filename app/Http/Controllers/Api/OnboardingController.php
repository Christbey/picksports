<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OnboardingService;
use App\Support\SportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $this->onboardingService->completeStep(
            $request->user(),
            $validated['step'],
            $validated['data'] ?? null
        );

        return $this->progressMessageResponse($request, 'Step completed successfully');
    }

    public function savePersonalization(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'favorite_sports' => 'nullable|array',
            'favorite_sports.*' => ['string', Rule::in(SportCatalog::ALL)],
            'betting_experience' => 'nullable|string|in:beginner,intermediate,advanced',
            'interests' => 'nullable|array',
            'interests.*' => 'string',
            'goals' => 'nullable|array',
            'goals.*' => 'string',
        ]);

        $this->onboardingService->savePersonalizationData(
            $request->user(),
            $validated
        );

        return $this->progressMessageResponse($request, 'Personalization data saved successfully');
    }

    public function skip(Request $request): JsonResponse
    {
        $this->onboardingService->skipOnboarding($request->user());

        return $this->progressMessageResponse($request, 'Onboarding skipped successfully');
    }

    public function steps(): JsonResponse
    {
        $steps = $this->onboardingService->getAvailableSteps();

        return response()->json(['steps' => $steps]);
    }

    private function progressMessageResponse(Request $request, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'progress' => $this->onboardingService->getOnboardingProgress($request->user()),
        ]);
    }
}
