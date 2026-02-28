<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CheckCircle2, Circle } from 'lucide-vue-next';
import Card from '@/components/ui/card/Card.vue';
import CardContent from '@/components/ui/card/CardContent.vue';
import CardDescription from '@/components/ui/card/CardDescription.vue';
import CardHeader from '@/components/ui/card/CardHeader.vue';
import CardTitle from '@/components/ui/card/CardTitle.vue';
import type { ChecklistItem } from '@/types/onboarding';

defineProps<{
    items: ChecklistItem[];
    totalItems: number;
    completedItems: number;
}>();
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Quick Start Checklist</CardTitle>
            <CardDescription>
                Complete these steps to get the most out of PickSports
                ({{ completedItems }}/{{ totalItems }} completed)
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div class="space-y-3">
                <Link
                    v-for="item in items"
                    :key="item.id"
                    :href="item.url"
                    class="flex items-start gap-3 rounded-lg border border-sidebar-border/70 p-4 transition-all hover:border-sidebar-border hover:bg-sidebar-accent/50"
                >
                    <CheckCircle2
                        v-if="item.completed"
                        class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-600 dark:text-green-400"
                    />
                    <Circle
                        v-else
                        class="mt-0.5 h-5 w-5 flex-shrink-0 text-muted-foreground"
                    />
                    <div class="flex-1 space-y-1">
                        <h4
                            class="font-medium leading-none"
                            :class="{
                                'text-muted-foreground line-through': item.completed,
                            }"
                        >
                            {{ item.title }}
                        </h4>
                        <p
                            class="text-sm"
                            :class="{
                                'text-muted-foreground': !item.completed,
                                'text-muted-foreground/70': item.completed,
                            }"
                        >
                            {{ item.description }}
                        </p>
                    </div>
                </Link>
            </div>
        </CardContent>
    </Card>
</template>
