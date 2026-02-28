<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import Heading from '@/components/Heading.vue';
import OnboardingChecklist from '@/components/onboarding/OnboardingChecklist.vue';
import OnboardingProgressBar from '@/components/onboarding/OnboardingProgressBar.vue';
import OnboardingWizard from '@/components/onboarding/OnboardingWizard.vue';
import Alert from '@/components/ui/alert/Alert.vue';
import AlertDescription from '@/components/ui/alert/AlertDescription.vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Onboarding',
        href: '/settings/onboarding',
    },
];

const showWizard = ref(false);

const {
    progress,
    checklist,
    loading,
    isStarted,
    isCompleted,
    progressPercentage,
    currentStep,
    fetchProgress,
    fetchChecklist,
} = useOnboarding();

onMounted(async () => {
    await Promise.all([fetchProgress(), fetchChecklist()]);
});

const handleStartOnboarding = () => {
    showWizard.value = true;
};

const handleWizardComplete = async () => {
    await Promise.all([fetchProgress(), fetchChecklist()]);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Onboarding" />

        <h1 class="sr-only">Onboarding Settings</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Onboarding"
                    description="Review your onboarding progress and get started with PickSports"
                />

                <!-- Completed State -->
                <Alert v-if="isCompleted && !loading" class="border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-950/20">
                    <AlertDescription class="text-green-900 dark:text-green-100">
                        You've completed the onboarding process! You can review it anytime by clicking the button below.
                    </AlertDescription>
                </Alert>

                <!-- Progress Card -->
                <Card v-if="isStarted && !loading">
                    <CardHeader>
                        <CardTitle class="text-base">Your Progress</CardTitle>
                        <CardDescription v-if="!isCompleted">
                            {{ isCompleted ? 'All done!' : 'Continue where you left off' }}
                        </CardDescription>
                        <CardDescription v-else>
                            Review your onboarding journey
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <OnboardingProgressBar
                            :percentage="progressPercentage"
                            :current-step="currentStep"
                        />
                        <Button
                            :variant="isCompleted ? 'outline' : 'default'"
                            @click="handleStartOnboarding"
                        >
                            {{ isCompleted ? 'Review Onboarding' : 'Continue Onboarding' }}
                        </Button>
                    </CardContent>
                </Card>

                <!-- Not Started State -->
                <Card v-else-if="!isStarted && !loading">
                    <CardHeader>
                        <CardTitle class="text-base">Get Started</CardTitle>
                        <CardDescription>
                            Complete a quick onboarding to learn about PickSports
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button @click="handleStartOnboarding">
                            Start Onboarding
                        </Button>
                    </CardContent>
                </Card>

                <!-- Quick Start Checklist -->
                <div v-if="checklist && !loading">
                    <OnboardingChecklist
                        :items="checklist.checklist"
                        :total-items="checklist.total_items"
                        :completed-items="checklist.completed_items"
                    />
                </div>

                <!-- Getting Started Resources -->
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Resources</CardTitle>
                        <CardDescription>
                            Learn more about using PickSports effectively
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-3">
                            <div class="rounded-lg border border-sidebar-border/70 p-3">
                                <h4 class="text-sm font-semibold">Understanding Predictions</h4>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Learn how our models generate predictions
                                </p>
                            </div>
                            <div class="rounded-lg border border-sidebar-border/70 p-3">
                                <h4 class="text-sm font-semibold">Betting Strategies</h4>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Best practices for using predictions
                                </p>
                            </div>
                            <div class="rounded-lg border border-sidebar-border/70 p-3">
                                <h4 class="text-sm font-semibold">FAQ & Support</h4>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    Common questions and how to get help
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Onboarding Wizard -->
            <OnboardingWizard
                :open="showWizard"
                :auto-start="true"
                @close="showWizard = false"
                @complete="handleWizardComplete"
            />
        </SettingsLayout>
    </AppLayout>
</template>
