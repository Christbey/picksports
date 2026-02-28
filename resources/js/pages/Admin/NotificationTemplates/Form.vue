<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import RenderErrorBoundary from '@/components/RenderErrorBoundary.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

interface NotificationTemplate {
    id: number;
    name: string;
    description: string | null;
    subject: string | null;
    email_body: string | null;
    sms_body: string | null;
    push_title: string | null;
    push_body: string | null;
    variables: string[] | null;
    active: boolean;
}

interface AvailableVariable {
    name: string;
    description: string;
    category: string;
}

const props = defineProps<{
    template: NotificationTemplate | null;
    availableVariables: AvailableVariable[];
}>();

const isEditing = computed(() => props.template !== null);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Admin',
        href: '/admin/subscriptions',
    },
    {
        title: 'Subscription Tiers',
        href: '/admin/tiers',
    },
    {
        title: isEditing.value ? 'Edit Template' : 'Create Template',
        href: isEditing.value ? `/admin/notification-templates/${props.template?.id}/edit` : '/admin/notification-templates/create',
    },
];

const form = useForm({
    name: props.template?.name || '',
    description: props.template?.description || '',
    subject: props.template?.subject || '',
    email_body: props.template?.email_body || '',
    sms_body: props.template?.sms_body || '',
    push_title: props.template?.push_title || '',
    push_body: props.template?.push_body || '',
    variables: props.template?.variables || [],
    active: props.template?.active ?? true,
});

const subjectInput = ref<HTMLInputElement | null>(null);
const emailBodyTextarea = ref<HTMLTextAreaElement | null>(null);
const smsBodyTextarea = ref<HTMLTextAreaElement | null>(null);
const pushTitleInput = ref<HTMLInputElement | null>(null);
const pushBodyTextarea = ref<HTMLTextAreaElement | null>(null);

const lastFocusedInput = ref<HTMLInputElement | HTMLTextAreaElement | null>(null);
type EditableTemplateField = 'subject' | 'email_body' | 'sms_body' | 'push_title' | 'push_body';
const editableTemplateFields = new Set<EditableTemplateField>([
    'subject',
    'email_body',
    'sms_body',
    'push_title',
    'push_body',
]);

const variablesByCategory = computed(() => {
    const grouped: Record<string, AvailableVariable[]> = {};
    props.availableVariables.forEach((variable) => {
        if (!grouped[variable.category]) {
            grouped[variable.category] = [];
        }
        grouped[variable.category].push(variable);
    });
    return grouped;
});

function handleInputFocus(event: FocusEvent) {
    lastFocusedInput.value = event.target as HTMLInputElement | HTMLTextAreaElement;
}

function insertVariable(variableName: string) {
    const inputRef = lastFocusedInput.value || subjectInput.value;
    if (!inputRef) return;

    const startPos = inputRef.selectionStart || 0;
    const endPos = inputRef.selectionEnd || 0;
    const rawFieldName = inputRef.getAttribute('data-field');
    if (!rawFieldName || !editableTemplateFields.has(rawFieldName as EditableTemplateField)) return;

    const fieldName = rawFieldName as EditableTemplateField;
    const currentValue = form[fieldName] || '';
    const variableToken = `{${variableName}}`;
    const newValue = currentValue.substring(0, startPos) + variableToken + currentValue.substring(endPos);

    form[fieldName] = newValue;

    // Track variable usage
    if (!form.variables.includes(variableName)) {
        form.variables.push(variableName);
    }

    // Set focus back to input and move cursor after inserted variable
    setTimeout(() => {
        inputRef.focus();
        const newCursorPos = startPos + variableToken.length;
        inputRef.setSelectionRange(newCursorPos, newCursorPos);
    }, 10);
}

function submit() {
    if (isEditing.value) {
        form.put(`/admin/notification-templates/${props.template?.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/admin/tiers');
            },
        });
    } else {
        form.post('/admin/notification-templates', {
            preserveScroll: true,
            onSuccess: () => {
                router.visit('/admin/tiers');
            },
        });
    }
}

const smsCharCount = computed(() => form.sms_body?.length || 0);
</script>

<template>
    <Head :title="isEditing ? 'Edit Notification Template' : 'Create Notification Template'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <RenderErrorBoundary title="Template Form Render Error">
            <div class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4">
                <div class="rounded-xl border border-sidebar-border bg-white dark:bg-sidebar p-6 space-y-6">
                    <div>
                        <h1 class="text-2xl font-bold">
                            {{ isEditing ? 'Edit Notification Template' : 'Create Notification Template' }}
                        </h1>
                        <p class="mt-1 text-muted-foreground">
                            Configure multi-channel notification templates with variable substitution
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <h2 class="text-lg font-semibold mb-4">Basic Information</h2>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Template Name *</label>
                                    <input
                                        v-model="form.name"
                                        type="text"
                                        required
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="e.g., betting_value_alert"
                                    />
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Unique identifier for this template
                                    </p>
                                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-2">Description</label>
                                    <textarea
                                        v-model="form.description"
                                        rows="2"
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="Brief description of when this template is used"
                                    />
                                    <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                                </div>

                                <div class="flex items-center">
                                    <input
                                        v-model="form.active"
                                        type="checkbox"
                                        id="active"
                                        class="h-4 w-4 rounded border-sidebar-border text-primary focus:ring-primary"
                                    />
                                    <label for="active" class="ml-2 text-sm font-medium">
                                        Active
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-sidebar-border pt-6">
                            <h2 class="text-lg font-semibold mb-4">Email Channel</h2>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Email Subject</label>
                                    <input
                                        ref="subjectInput"
                                        v-model="form.subject"
                                        type="text"
                                        data-field="subject"
                                        @focus="handleInputFocus"
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="e.g., High-Value Betting Opportunity: {prediction.game_description}"
                                    />
                                    <p v-if="form.errors.subject" class="mt-1 text-sm text-red-600">{{ form.errors.subject }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-2">Email Body</label>
                                    <textarea
                                        ref="emailBodyTextarea"
                                        v-model="form.email_body"
                                        rows="6"
                                        data-field="email_body"
                                        @focus="handleInputFocus"
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 font-mono text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="Use variables like {user.name}, {prediction.game_description}, {prediction.edge_percentage}, etc."
                                    />
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Supports HTML and variable substitution
                                    </p>
                                    <p v-if="form.errors.email_body" class="mt-1 text-sm text-red-600">{{ form.errors.email_body }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-sidebar-border pt-6">
                            <h2 class="text-lg font-semibold mb-4">SMS Channel</h2>

                            <div>
                                <label class="block text-sm font-medium mb-2">SMS Body</label>
                                <textarea
                                    ref="smsBodyTextarea"
                                    v-model="form.sms_body"
                                    rows="3"
                                    maxlength="160"
                                    data-field="sms_body"
                                    @focus="handleInputFocus"
                                    class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 font-mono text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    placeholder="Short message with variables (max 160 chars)"
                                />
                                <div class="mt-1 flex justify-between text-sm">
                                    <p class="text-muted-foreground">Plain text only, variable substitution supported</p>
                                    <p :class="[
                                        'font-medium',
                                        smsCharCount > 160 ? 'text-red-600' : smsCharCount > 140 ? 'text-yellow-600' : 'text-muted-foreground'
                                    ]">
                                        {{ smsCharCount }}/160
                                    </p>
                                </div>
                                <p v-if="form.errors.sms_body" class="mt-1 text-sm text-red-600">{{ form.errors.sms_body }}</p>
                            </div>
                        </div>

                        <div class="border-t border-sidebar-border pt-6">
                            <h2 class="text-lg font-semibold mb-4">Push Notification Channel</h2>

                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium mb-2">Push Title</label>
                                    <input
                                        ref="pushTitleInput"
                                        v-model="form.push_title"
                                        type="text"
                                        data-field="push_title"
                                        @focus="handleInputFocus"
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="e.g., New Betting Opportunity"
                                    />
                                    <p v-if="form.errors.push_title" class="mt-1 text-sm text-red-600">{{ form.errors.push_title }}</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium mb-2">Push Body</label>
                                    <textarea
                                        ref="pushBodyTextarea"
                                        v-model="form.push_body"
                                        rows="2"
                                        data-field="push_body"
                                        @focus="handleInputFocus"
                                        class="w-full rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-4 py-2 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        placeholder="e.g., {prediction.game_description} has a {prediction.edge_percentage}% edge!"
                                    />
                                    <p v-if="form.errors.push_body" class="mt-1 text-sm text-red-600">{{ form.errors.push_body }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-sidebar-border pt-6">
                            <h2 class="text-lg font-semibold mb-4">Available Variables</h2>
                            <p class="text-sm text-muted-foreground mb-4">
                                Click any variable below to insert it at your cursor position. Variables use the format <code class="bg-sidebar-accent px-1.5 py-0.5 rounded text-xs">{`{category.variable}`}</code>
                            </p>

                            <div class="space-y-6">
                                <div v-for="(variables, category) in variablesByCategory" :key="category">
                                    <h3 class="text-sm font-semibold mb-3 text-muted-foreground">{{ category }}</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                        <button
                                            v-for="variable in variables"
                                            :key="variable.name"
                                            type="button"
                                            @click="insertVariable(variable.name)"
                                            class="text-left rounded-lg border border-sidebar-border bg-white dark:bg-sidebar-accent px-3 py-2 hover:border-primary hover:bg-primary/5 transition-all group"
                                        >
                                            <div class="font-mono text-xs font-semibold text-primary group-hover:text-primary">
                                                {`{${variable.name}}`}
                                            </div>
                                            <div class="text-xs text-muted-foreground mt-0.5">
                                                {{ variable.description }}
                                            </div>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="form.variables.length > 0" class="mt-6 pt-6 border-t border-sidebar-border">
                                <h3 class="text-sm font-semibold mb-3">Variables Used in This Template</h3>
                                <div class="flex flex-wrap gap-2">
                                    <span
                                        v-for="(variable, index) in form.variables"
                                        :key="index"
                                        class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-mono font-medium text-primary"
                                    >
                                        {`{${variable}}`}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex gap-4 justify-end">
                            <button
                                type="button"
                                @click="router.visit('/admin/tiers')"
                                class="rounded-lg bg-sidebar-accent px-6 py-2 font-medium hover:bg-sidebar-accent/80 transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="rounded-lg bg-primary px-6 py-2 font-medium text-primary-foreground hover:bg-primary/90 transition-colors disabled:opacity-50"
                            >
                                {{ form.processing ? 'Saving...' : (isEditing ? 'Update Template' : 'Create Template') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </RenderErrorBoundary>
    </AppLayout>
</template>
