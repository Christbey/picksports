<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import Button from '@/components/ui/button/Button.vue';
import Dialog from '@/components/ui/dialog/Dialog.vue';
import DialogContent from '@/components/ui/dialog/DialogContent.vue';
import DialogDescription from '@/components/ui/dialog/DialogDescription.vue';
import DialogFooter from '@/components/ui/dialog/DialogFooter.vue';
import DialogHeader from '@/components/ui/dialog/DialogHeader.vue';
import DialogTitle from '@/components/ui/dialog/DialogTitle.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import type { OnboardingStep, PersonalizationData } from '@/types/onboarding';
import OnboardingProgressBar from './OnboardingProgressBar.vue';
import PersonalizationForm from './PersonalizationForm.vue';

const props = defineProps<{
    open?: boolean;
    autoStart?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    complete: [];
}>();

const {
    progress,
    steps,
    loading,
    error,
    currentStep,
    progressPercentage,
    fetchProgress,
    fetchSteps,
    completeStep,
    savePersonalization,
    skipOnboarding,
} = useOnboarding();

const isOpen = ref(props.open ?? false);
const activeStepIndex = ref(0);
const formKey = ref(0);

const stepSequence: OnboardingStep[] = [
    'welcome',
    'sport_selection',
    'alert_setup',
    'methodology_review',
];

const stepTitles: Record<OnboardingStep, string> = {
    welcome: 'Welcome to PickSports',
    sport_selection: 'Personalize Your Experience',
    alert_setup: 'Set Up Alerts',
    methodology_review: 'Review Methodology',
};

const stepDescriptions: Record<OnboardingStep, string> = {
    welcome: "Let's get you started with PickSports in just a few quick steps",
    sport_selection: 'Tell us about your sports preferences and betting experience',
    alert_setup:
        'Configure how you want to receive predictions and important updates',
    methodology_review:
        'Learn about our data-driven approach to sports predictions',
};

const currentStepKey = computed(() => stepSequence[activeStepIndex.value]);
const currentStepTitle = computed(() => stepTitles[currentStepKey.value]);
const currentStepDescription = computed(() => stepDescriptions[currentStepKey.value]);
const isLastStep = computed(() => activeStepIndex.value === stepSequence.length - 1);
const canGoBack = computed(() => activeStepIndex.value > 0);

onMounted(async () => {
    if (props.autoStart) {
        await fetchProgress();
        await fetchSteps();
        if (props.open !== undefined) {
            isOpen.value = props.open;
        }
    }
});

watch(() => props.open, (newValue) => {
    if (newValue !== undefined) {
        isOpen.value = newValue;
    }
});

watch(isOpen, async (newValue) => {
    if (newValue === true) {
        await fetchProgress();
        await nextTick();
        formKey.value++;
    }
});

const handleNext = async () => {
    const currentStep = currentStepKey.value;
    const success = await completeStep({ step: currentStep });

    if (success) {
        if (isLastStep.value) {
            handleComplete();
        } else {
            activeStepIndex.value++;
        }
    }
};

const handleBack = () => {
    if (canGoBack.value) {
        activeStepIndex.value--;
    }
};

const handleSkip = async () => {
    const success = await skipOnboarding();
    if (success) {
        handleComplete();
    }
};

const handlePersonalizationSubmit = async (data: PersonalizationData) => {
    console.log('Wizard received personalization data:', data);
    const success = await savePersonalization(data);
    console.log('Save personalization result:', success);
    if (success) {
        handleNext();
    }
};

const handleComplete = () => {
    isOpen.value = false;
    emit('complete');
    router.reload({ only: ['auth'] });
};

const handleClose = () => {
    isOpen.value = false;
    emit('close');
};
</script>

<template>
    <Dialog v-model:open="isOpen">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <DialogTitle>{{ currentStepTitle }}</DialogTitle>
                        <DialogDescription>
                            {{ currentStepDescription }}
                        </DialogDescription>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="-mr-2 -mt-2"
                        @click="handleSkip"
                    >
                        <X class="h-4 w-4" />
                    </Button>
                </div>
            </DialogHeader>

            <div class="space-y-6 py-4">
                <OnboardingProgressBar
                    :percentage="progressPercentage"
                    :current-step="currentStepTitle"
                />

                <!-- Welcome Step -->
                <div v-if="currentStepKey === 'welcome'" class="space-y-4">
                    <p>
                        PickSports uses advanced data analytics and machine learning to
                        provide you with accurate sports predictions across NFL, NBA, MLB,
                        and more.
                    </p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div
                            class="rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-4"
                        >
                            <h4 class="font-semibold">Data-Driven</h4>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Our models analyze thousands of data points
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-4"
                        >
                            <h4 class="font-semibold">Transparent</h4>
                            <p class="mt-1 text-sm text-muted-foreground">
                                We show you the reasoning behind every prediction
                            </p>
                        </div>
                        <div
                            class="rounded-lg border border-sidebar-border/70 bg-sidebar-accent/30 p-4"
                        >
                            <h4 class="font-semibold">Actionable</h4>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Get betting recommendations with confidence scores
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Sport Selection Step -->
                <div v-else-if="currentStepKey === 'sport_selection'">
                    <PersonalizationForm
                        :key="formKey"
                        :loading="loading"
                        :initial-data="{
                            favorite_sports: progress?.favorite_sports,
                            betting_experience: progress?.betting_experience,
                        }"
                        @submit="handlePersonalizationSubmit"
                    />
                </div>

                <!-- Alert Setup Step -->
                <div v-else-if="currentStepKey === 'alert_setup'" class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        We'll send you email notifications with daily predictions and
                        valuable betting insights. You can customize your alert preferences
                        anytime from your settings.
                    </p>
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/20">
                        <p class="text-sm">
                            <strong>Coming soon:</strong> SMS alerts, mobile push
                            notifications, and custom alert triggers based on your favorite
                            teams and betting criteria.
                        </p>
                    </div>
                </div>

                <!-- Methodology Review Step -->
                <div
                    v-else-if="currentStepKey === 'methodology_review'"
                    class="space-y-4"
                >
                    <p>Our prediction methodology combines multiple data sources:</p>
                    <ul class="space-y-2 text-sm">
                        <li class="flex gap-2">
                            <span class="text-blue-600">•</span>
                            <span
                                ><strong>Team Performance:</strong> Win rates, scoring
                                averages, and historical matchups</span
                            >
                        </li>
                        <li class="flex gap-2">
                            <span class="text-blue-600">•</span>
                            <span
                                ><strong>Player Stats:</strong> Individual player
                                performance and injury reports</span
                            >
                        </li>
                        <li class="flex gap-2">
                            <span class="text-blue-600">•</span>
                            <span
                                ><strong>Advanced Metrics:</strong> ELO ratings, strength
                                of schedule, and more</span
                            >
                        </li>
                        <li class="flex gap-2">
                            <span class="text-blue-600">•</span>
                            <span
                                ><strong>Market Analysis:</strong> Comparing our
                                predictions with betting market lines</span
                            >
                        </li>
                    </ul>
                    <p class="text-sm text-muted-foreground">
                        Each prediction includes a confidence score and detailed reasoning
                        to help you make informed betting decisions.
                    </p>
                </div>

                <p v-if="error" class="text-sm text-red-600 dark:text-red-400">
                    {{ error }}
                </p>
            </div>

            <DialogFooter v-if="currentStepKey !== 'sport_selection'">
                <div class="flex w-full items-center justify-between">
                    <Button
                        variant="ghost"
                        :disabled="!canGoBack || loading"
                        @click="handleBack"
                    >
                        Back
                    </Button>
                    <div class="flex gap-2">
                        <Button variant="ghost" @click="handleSkip"> Skip </Button>
                        <Button :disabled="loading" @click="handleNext">
                            {{ isLastStep ? 'Finish' : 'Next' }}
                        </Button>
                    </div>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
