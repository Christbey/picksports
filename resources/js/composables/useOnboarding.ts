import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import type {
    OnboardingProgress,
    OnboardingStep,
    ChecklistResponse,
    OnboardingSteps,
    PersonalizationData,
    CompleteStepRequest,
    OnboardingApiResponse,
} from '@/types/onboarding';

export function useOnboarding() {
    const progress = ref<OnboardingProgress | null>(null);
    const checklist = ref<ChecklistResponse | null>(null);
    const steps = ref<OnboardingSteps | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);

    const isStarted = computed(() => progress.value?.started ?? false);
    const isCompleted = computed(() => progress.value?.completed ?? false);
    const currentStep = computed(() => progress.value?.current_step);
    const progressPercentage = computed(() => progress.value?.progress_percentage ?? 0);

    const fetchProgress = async (): Promise<void> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.get<OnboardingProgress>('/api/v1/onboarding');
            progress.value = response.data;
        } catch (err) {
            error.value = 'Failed to fetch onboarding progress';
            console.error('Error fetching onboarding progress:', err);
        } finally {
            loading.value = false;
        }
    };

    const fetchChecklist = async (): Promise<void> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.get<ChecklistResponse>('/api/v1/onboarding/checklist');
            checklist.value = response.data;
        } catch (err) {
            error.value = 'Failed to fetch checklist';
            console.error('Error fetching checklist:', err);
        } finally {
            loading.value = false;
        }
    };

    const fetchSteps = async (): Promise<void> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.get<{ steps: OnboardingSteps }>('/api/v1/onboarding/steps');
            steps.value = response.data.steps;
        } catch (err) {
            error.value = 'Failed to fetch onboarding steps';
            console.error('Error fetching steps:', err);
        } finally {
            loading.value = false;
        }
    };

    const completeStep = async (request: CompleteStepRequest): Promise<boolean> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.post<OnboardingApiResponse>(
                '/api/v1/onboarding/steps/complete',
                request
            );
            progress.value = response.data.progress;
            return true;
        } catch (err) {
            error.value = 'Failed to complete step';
            console.error('Error completing step:', err);
            return false;
        } finally {
            loading.value = false;
        }
    };

    const savePersonalization = async (data: PersonalizationData): Promise<boolean> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.post<OnboardingApiResponse>(
                '/api/v1/onboarding/personalization',
                data
            );
            progress.value = response.data.progress;
            return true;
        } catch (err) {
            error.value = 'Failed to save personalization data';
            console.error('Error saving personalization:', err);
            return false;
        } finally {
            loading.value = false;
        }
    };

    const skipOnboarding = async (): Promise<boolean> => {
        try {
            loading.value = true;
            error.value = null;
            const response = await axios.post<OnboardingApiResponse>('/api/v1/onboarding/skip');
            progress.value = response.data.progress;
            return true;
        } catch (err) {
            error.value = 'Failed to skip onboarding';
            console.error('Error skipping onboarding:', err);
            return false;
        } finally {
            loading.value = false;
        }
    };

    const refreshProgress = async (): Promise<void> => {
        await fetchProgress();
    };

    return {
        progress,
        checklist,
        steps,
        loading,
        error,
        isStarted,
        isCompleted,
        currentStep,
        progressPercentage,
        fetchProgress,
        fetchChecklist,
        fetchSteps,
        completeStep,
        savePersonalization,
        skipOnboarding,
        refreshProgress,
    };
}
