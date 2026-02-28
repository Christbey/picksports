<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import OnboardingChecklist from '@/components/onboarding/OnboardingChecklist.vue';
import OnboardingProgressBar from '@/components/onboarding/OnboardingProgressBar.vue';
import OnboardingWizard from '@/components/onboarding/OnboardingWizard.vue';
import Button from '@/components/ui/button/Button.vue';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import { useOnboarding } from '@/composables/useOnboarding';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Onboarding',
        href: '/onboarding',
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

const handleRestartOnboarding = () => {
    showWizard.value = true;
};

const handleWizardComplete = async () => {
    await Promise.all([fetchProgress(), fetchChecklist()]);
};
</script>

<template>
    <Head title="Onboarding" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
            <div class="space-y-2">
                <h1 class="text-3xl font-bold">Welcome to PickSports</h1>
                <p class="text-muted-foreground">
                    Get started with PickSports and unlock the power of data-driven sports
                    predictions
                </p>
            </div>

            <!-- Onboarding Progress Card -->
            <Card v-if="isStarted && !loading">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Your Onboarding Progress</CardTitle>
                            <CardDescription v-if="!isCompleted">
                                {{ isCompleted ? 'Completed!' : 'Continue where you left off' }}
                            </CardDescription>
                            <CardDescription v-else>
                                You've completed the onboarding process!
                            </CardDescription>
                        </div>
                        <Button
                            v-if="!isCompleted"
                            size="sm"
                            @click="handleStartOnboarding"
                        >
                            {{ isStarted ? 'Continue' : 'Start' }}
                        </Button>
                        <Button v-else variant="outline" size="sm" @click="handleRestartOnboarding">
                            Review Again
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <OnboardingProgressBar
                        :percentage="progressPercentage"
                        :current-step="currentStep"
                    />
                </CardContent>
            </Card>

            <!-- Welcome Card (for non-started users) -->
            <Card v-else-if="!isStarted && !loading">
                <CardHeader>
                    <CardTitle>Ready to get started?</CardTitle>
                    <CardDescription>
                        Let's walk you through the basics of PickSports
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button @click="handleStartOnboarding"> Start Onboarding </Button>
                </CardContent>
            </Card>

            <!-- Quick Start Checklist -->
            <OnboardingChecklist
                v-if="checklist && !loading"
                :items="checklist.checklist"
                :total-items="checklist.total_items"
                :completed-items="checklist.completed_items"
            />

            <!-- Getting Started Resources -->
            <Card>
                <CardHeader>
                    <CardTitle>Getting Started Resources</CardTitle>
                    <CardDescription>
                        Helpful guides and documentation to maximize your experience
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2 rounded-lg border border-sidebar-border/70 p-4">
                            <h4 class="font-semibold">Understanding Predictions</h4>
                            <p class="text-sm text-muted-foreground">
                                Learn how our models generate predictions and what the
                                different metrics mean
                            </p>
                        </div>
                        <div class="space-y-2 rounded-lg border border-sidebar-border/70 p-4">
                            <h4 class="font-semibold">Betting Strategies</h4>
                            <p class="text-sm text-muted-foreground">
                                Best practices for using our predictions to inform your
                                betting decisions
                            </p>
                        </div>
                        <div class="space-y-2 rounded-lg border border-sidebar-border/70 p-4">
                            <h4 class="font-semibold">API Documentation</h4>
                            <p class="text-sm text-muted-foreground">
                                Access predictions programmatically with our RESTful API
                            </p>
                        </div>
                        <div class="space-y-2 rounded-lg border border-sidebar-border/70 p-4">
                            <h4 class="font-semibold">FAQ & Support</h4>
                            <p class="text-sm text-muted-foreground">
                                Common questions and how to get help when you need it
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
    </AppLayout>
</template>
