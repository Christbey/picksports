<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import type { PersonalizationData, Sport, BettingExperience } from '@/types/onboarding';
import Button from '@/components/ui/button/Button.vue';
import Checkbox from '@/components/ui/checkbox/Checkbox.vue';
import Label from '@/components/ui/label/Label.vue';

const emit = defineEmits<{
    submit: [data: PersonalizationData];
}>();

const props = defineProps<{
    loading?: boolean;
    initialData?: PersonalizationData;
}>();

const sports: { value: Sport; label: string }[] = [
    { value: 'nfl', label: 'NFL (Football)' },
    { value: 'cfb', label: 'CFB (College Football)' },
    { value: 'nba', label: 'NBA (Basketball)' },
    { value: 'cbb', label: 'CBB (College Basketball)' },
    { value: 'wcbb', label: "WCBB (Women's College Basketball)" },
    { value: 'wnba', label: "WNBA (Women's Basketball)" },
    { value: 'mlb', label: 'MLB (Baseball)' },
];

const experienceLevels: { value: BettingExperience; label: string; description: string }[] = [
    {
        value: 'beginner',
        label: 'Beginner',
        description: 'I am new to sports betting',
    },
    {
        value: 'intermediate',
        label: 'Intermediate',
        description: 'I have some experience with betting',
    },
    {
        value: 'advanced',
        label: 'Advanced',
        description: 'I am an experienced bettor',
    },
];

const selectedSports = ref<Sport[]>([]);
const selectedExperience = ref<BettingExperience | null>(null);

const loadInitialData = () => {
    console.log('[PersonalizationForm] Loading initial data:', props.initialData);
    if (props.initialData?.favorite_sports) {
        selectedSports.value = [...props.initialData.favorite_sports];
        console.log('[PersonalizationForm] Set selectedSports to:', selectedSports.value);
    } else {
        selectedSports.value = [];
        console.log('[PersonalizationForm] No favorite_sports, set to empty array');
    }

    if (props.initialData?.betting_experience) {
        selectedExperience.value = props.initialData.betting_experience;
        console.log('[PersonalizationForm] Set betting_experience to:', selectedExperience.value);
    } else {
        selectedExperience.value = null;
        console.log('[PersonalizationForm] No betting_experience, set to null');
    }
};

onMounted(() => {
    console.log('[PersonalizationForm] Component mounted');
    loadInitialData();
});

const toggleSport = (sport: Sport, checked: boolean) => {
    if (checked) {
        if (!selectedSports.value.includes(sport)) {
            selectedSports.value.push(sport);
        }
    } else {
        selectedSports.value = selectedSports.value.filter(s => s !== sport);
    }
};

const selectExperience = (experience: BettingExperience) => {
    selectedExperience.value = experience;
};

const canSubmit = computed(() => {
    return selectedSports.value.length > 0 || selectedExperience.value !== null;
});

const handleSubmit = () => {
    const data: PersonalizationData = {};
    if (selectedSports.value.length > 0) {
        data.favorite_sports = selectedSports.value;
    }
    if (selectedExperience.value) {
        data.betting_experience = selectedExperience.value;
    }
    console.log('PersonalizationForm submitting:', data);
    console.log('selectedSports.value:', selectedSports.value);
    emit('submit', data);
};
</script>

<template>
    <div class="space-y-6">
        <!-- Sports Selection -->
        <div class="space-y-3">
            <div>
                <h3 class="font-medium leading-none">Select Your Favorite Sports</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Choose the sports you're most interested in
                </p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label
                    v-for="sport in sports"
                    :key="sport.value"
                    class="flex items-center space-x-2 cursor-pointer"
                >
                    <input
                        type="checkbox"
                        :id="sport.value"
                        :value="sport.value"
                        :checked="selectedSports.includes(sport.value)"
                        @change="(e) => {
                            const checked = (e.target as HTMLInputElement).checked;
                            console.log(`[PersonalizationForm] Checkbox ${sport.value} changed to:`, checked);
                            toggleSport(sport.value, checked);
                            console.log('[PersonalizationForm] selectedSports after toggle:', selectedSports);
                        }"
                        class="h-4 w-4 rounded border-gray-300"
                    />
                    <span class="font-normal">{{ sport.label }}</span>
                </label>
            </div>
        </div>

        <!-- Experience Level -->
        <div class="space-y-3">
            <div>
                <h3 class="font-medium leading-none">Your Betting Experience</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    Help us tailor recommendations to your level
                </p>
            </div>
            <div class="space-y-2">
                <button
                    v-for="level in experienceLevels"
                    :key="level.value"
                    type="button"
                    class="flex w-full items-start gap-3 rounded-lg border p-4 text-left transition-all"
                    :class="{
                        'border-blue-600 bg-blue-50 dark:bg-blue-950/20':
                            selectedExperience === level.value,
                        'border-sidebar-border/70 hover:border-sidebar-border hover:bg-sidebar-accent/50':
                            selectedExperience !== level.value,
                    }"
                    @click="selectExperience(level.value)"
                >
                    <div class="mt-0.5">
                        <div
                            class="flex h-5 w-5 items-center justify-center rounded-full border-2"
                            :class="{
                                'border-blue-600 bg-blue-600':
                                    selectedExperience === level.value,
                                'border-muted-foreground': selectedExperience !== level.value,
                            }"
                        >
                            <div
                                v-if="selectedExperience === level.value"
                                class="h-2 w-2 rounded-full bg-white"
                            />
                        </div>
                    </div>
                    <div class="flex-1 space-y-1">
                        <h4 class="font-medium leading-none">{{ level.label }}</h4>
                        <p class="text-sm text-muted-foreground">
                            {{ level.description }}
                        </p>
                    </div>
                </button>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end">
            <Button
                :disabled="!canSubmit || loading"
                @click="handleSubmit"
            >
                {{ loading ? 'Saving...' : 'Continue' }}
            </Button>
        </div>
    </div>
</template>
